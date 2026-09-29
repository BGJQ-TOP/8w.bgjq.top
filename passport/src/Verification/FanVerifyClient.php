<?php

namespace W8\Passport\Verification;

use W8\Passport\Http\ApiException;
use W8\Passport\Support\Arr;
use W8\Passport\Support\Config;
use W8\Passport\Support\HttpClient;
use W8\Passport\Support\Logger;

/**
 * FanVerify openAPI 客户端
 *
 * 文档：https://doc.fanverify.cn/llms.txt
 * 接口根地址：https://api.fanverify.cn（官方 OpenAPI 文档里 servers 为空，
 *             该地址是实测出来的：/openapi/* 会返回文档中描述的 401 {"error":"Unauthorized"}）
 *
 * 全部接口都挂在 /openapi/ 下，鉴权统一用 `accesstoken`：
 *   · GET  类接口把 accesstoken 放在 query string
 *   · POST 类接口把 accesstoken 放在 JSON body
 *
 * 已覆盖文档里的 7 个接口：
 *   devinfo      开发者令牌信息（可当连通性/配置自检用）
 *   otp          申请 OTP（扫码流程第一步）
 *   genqrcode    OTP 二维码（返回 PNG，必须由服务端代理，不能把 token 给浏览器）
 *   seeotp       轮询 OTP 是否通过（5 秒内重复查询会 429）
 *   user_verify  UID + 动态验证码验证（手填流程）
 *   getuserdata  获取已验证过的用户数据
 *   tag          打风险标签（会扣额度，本客户端只提供能力，不主动调用）
 *
 * 本类只负责"把 HTTP 讲清楚"，业务判断（谁在绑、能不能绑）在调用方。
 */
final class FanVerifyClient
{
    /** 文档里的成功标志 */
    const STATUS_OK = 'ok';

    /** seeotp 的两种非成功状态 */
    const OTP_WAIT = 'wait';
    const OTP_RATE_LIMIT = 'rate_limit';

    /** @var Config */
    private $config;

    /** @var HttpClient */
    private $http;

    /** @var Logger */
    private $logger;

    public function __construct(Config $config, HttpClient $http, Logger $logger)
    {
        $this->config = $config;
        $this->http = $http;
        $this->logger = $logger;
    }

    public function isConfigured()
    {
        return $this->baseUrl() !== '' && $this->accessToken() !== '';
    }

    public function baseUrl()
    {
        return rtrim($this->config->getString('FANVERIFY_API_BASE', 'https://api.fanverify.cn'), '/');
    }

    private function accessToken()
    {
        return $this->config->getString('FANVERIFY_ACCESS_TOKEN');
    }

    private function timeout()
    {
        return max(3, $this->config->getInt('FANVERIFY_API_TIMEOUT', 10));
    }

    // ========================================================================
    //  开发者信息 —— 也可当连通性与令牌自检
    // ========================================================================

    /**
     * GET /openapi/devinfo
     *
     * @return array<string,mixed> Date_of_Issue / bind_uid / mode / need_end_level / service_message / status
     */
    public function developerInfo()
    {
        $payload = $this->get('devinfo');

        return array(
            'issued_at'       => Arr::toTextOrNull(Arr::get($payload, 'Date_of_Issue')),
            'bind_uid'        => Arr::toIntOrNull(Arr::get($payload, 'bind_uid')),
            'mode'            => Arr::toTextOrNull(Arr::get($payload, 'mode')),
            'need_end_level'  => Arr::toIntOrNull(Arr::get($payload, 'need_end_level')),
            'service_message' => Arr::toTextOrNull(Arr::get($payload, 'service_message')),
            'status'          => Arr::toTextOrNull(Arr::get($payload, 'status')),
        );
    }

    // ========================================================================
    //  扫码流程：申请 OTP → 出二维码 → 轮询
    // ========================================================================

    /**
     * GET /openapi/otp —— 申请一个 OTP
     *
     * @return string OTP 串
     */
    public function requestOtp()
    {
        $payload = $this->get('otp');

        if (Arr::get($payload, 'success', false) !== true) {
            $this->logger->warning('fanverify.otp_apply_failed', array('payload' => $this->summarize($payload)));
            throw ApiException::serverError('FanVerify 未能签发 OTP，请稍后重试');
        }

        $otp = Arr::toTextOrNull(Arr::get($payload, 'data.otp'));
        if ($otp === null) {
            $this->logger->warning('fanverify.otp_missing', array('payload' => $this->summarize($payload)));
            throw ApiException::serverError('FanVerify 返回的 OTP 为空');
        }

        return $otp;
    }

    /**
     * GET /openapi/genqrcode —— 取 OTP 二维码的 PNG
     *
     * 返回原始二进制。**必须由服务端代理给浏览器**，
     * 否则 accesstoken 会暴露在前端 URL 里。
     *
     * @param string $otp
     * @return string PNG 二进制
     */
    public function qrCodePng($otp)
    {
        $url = $this->url('genqrcode', array('otp' => (string) $otp));
        $response = $this->http->get($url, array(), $this->timeout());

        $this->assertTransport($response, 'genqrcode');

        if (!$response->ok()) {
            throw $this->httpError($response, 'genqrcode');
        }

        $body = $response->body();
        if ($body === '' || strncmp($body, "\x89PNG", 4) !== 0) {
            $this->logger->warning('fanverify.qrcode_not_png', array(
                'body_preview' => substr($body, 0, 120),
            ));
            throw ApiException::serverError('FanVerify 返回的二维码不是 PNG 图片');
        }

        return $body;
    }

    /**
     * GET /openapi/seeotp —— 轮询 OTP 是否通过
     *
     * ⚠ 官方文档说"5 秒内重复查询返回 429"，但**实测是 HTTP 200 + `{"status":"rate_limit"}`**。
     *   所以这里以 `status` 字段为准，HTTP 429 只作为兜底一并识别。
     *
     * @param string $otp
     * @return array{status:string,identity:FanVerifyIdentity|null}
     *         status: ok / wait / rate_limit
     */
    public function pollOtp($otp)
    {
        $url = $this->url('seeotp', array('otp' => (string) $otp));
        $response = $this->http->get($url, array(), $this->timeout());

        $this->assertTransport($response, 'seeotp');

        $payload = $response->json();

        // 限流：以 status 字段为准（实测 200），429 兜底
        if ($response->status() === 429) {
            return array('status' => self::OTP_RATE_LIMIT, 'identity' => null);
        }

        if ($payload === null) {
            throw $this->httpError($response, 'seeotp');
        }

        $status = Arr::toTextOrNull(Arr::get($payload, 'status'));
        if ($status === null) {
            // 非 2xx 且没有 status 字段 —— 当成错误处理
            if (!$response->ok()) {
                throw $this->httpError($response, 'seeotp');
            }
            $status = self::OTP_WAIT;
        }

        if ($status !== self::STATUS_OK) {
            return array('status' => $status, 'identity' => null);
        }

        return array('status' => self::STATUS_OK, 'identity' => $this->mapIdentity($payload, 'seeotp'));
    }

    // ========================================================================
    //  手填流程：UID + 动态验证码
    // ========================================================================

    /**
     * GET /openapi/user_verify —— UID + 动态验证码验证
     *
     * @param int|string $uid
     * @param string $passCode
     * @return FanVerifyIdentity
     */
    public function verifyUser($uid, $passCode)
    {
        $url = $this->url('user_verify', array(
            'uid'       => (string) (int) $uid,
            'pass_code' => (string) $passCode,
        ));

        $response = $this->http->get($url, array(), $this->timeout());

        $this->assertTransport($response, 'user_verify');

        if (!$response->ok()) {
            throw $this->userVerifyError($response);
        }

        $payload = $response->json();
        if ($payload === null) {
            $this->logger->warning('fanverify.user_verify_bad_json', array('body' => substr($response->body(), 0, 200)));
            throw ApiException::serverError('FanVerify 返回了无法解析的数据');
        }

        $status = Arr::toTextOrNull(Arr::get($payload, 'status'));
        if ($status !== self::STATUS_OK) {
            $this->logger->info('fanverify.user_verify_rejected', array('status' => $status));
            throw ApiException::validation(
                'FanVerify 验证失败：账号ID或动态验证码不正确',
                array('field' => 'fanverify_code')
            );
        }

        return $this->mapIdentity($payload, 'user_verify');
    }

    /**
     * user_verify 的错误翻译
     *
     * ⚠ 这个端点的状态码语义**和别的端点不一样**，是实测出来的
     *    （官方文档只写了 401，其余靠实测）：
     *
     *     uid 存在 + 动态验证码错误 -> 403 {"error":"Forbidden"}
     *     uid 不存在 / uid=0        -> 404 {"error":"Not Found"}
     *     pass_code 为空或非数字    -> 400 {"error":"Bad Request"}
     *
     * 所以这里**不能**复用通用的 httpError()：
     * 通用逻辑会把 404 说成"接口路径不存在，可能根地址配错了"，
     * 而实际上它表示"这个 FanVerify 账号不存在"。
     *
     * @return ApiException
     */
    private function userVerifyError($response)
    {
        $status = $response->status();
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : array();
        $error = Arr::toTextOrNull(Arr::get($payload, 'error'));

        $this->logger->info('fanverify.user_verify_error', array('status' => $status, 'error' => $error));

        if ($status === 401) {
            return $this->httpError($response, 'user_verify');
        }

        if ($status === 403) {
            // 实测：uid 有效但动态验证码不对。
            //
            // ⚠ 如果 FanVerify 后台给令牌设了 need_end_level > 0，
            //   那么"账号等级不足"也会返回同样的 403，两者无法区分。
            //   当前该令牌的 need_end_level 已设为 0，所以 403 就是验证码错误，
            //   文案按单一原因写。**若日后调高 need_end_level，必须回来把文案改回去**
            //   （管理员可在「接口状态 → 自检」里看到当前值）。
            return ApiException::validation(
                'FanVerify 动态验证码不正确，请在微信小程序里重新获取后重试',
                array('field' => 'fanverify_code')
            );
        }

        if ($status === 404) {
            return ApiException::validation(
                'FanVerify 账号不存在，请检查账号ID是否填写正确',
                array('field' => 'fanverify_uid')
            );
        }

        if ($status === 400) {
            return ApiException::validation(
                'FanVerify 拒绝了请求参数：账号ID需为数字，动态验证码不能为空',
                array('field' => 'fanverify_uid')
            );
        }

        if ($status === 429) {
            return ApiException::rateLimited('FanVerify 请求过于频繁，请稍后重试');
        }

        return ApiException::serverError('FanVerify 返回异常（HTTP ' . $status . '）');
    }

    // ========================================================================
    //  用户数据 / 风险标签
    // ========================================================================

    /**
     * POST /openapi/getuserdata —— 获取该开发者验证过的用户数据
     *
     * ⚠ 官方文档把 `accesstoken` 写在 JSON body 里，但**实测放 body 会 401**，
     *   必须放 query string（和 GET 类接口一样）。POST 类接口都按这个来。
     *
     * ⚠ 另外实测：这个端点在各种输入形态下都返回 `{"code":400}`（HTTP 400）——
     *   包括 uid 存在、uid 不存在、token 在 query 或 body、表单或 JSON。
     *   也就是说它当前**不可用**，所以这里把 400/403 都当成"拿不到数据"返回 null，
     *   不让它影响调用方。等 FanVerify 侧修好再收紧。
     *
     * @param int|string $uid
     * @return FanVerifyIdentity|null 拿不到时返回 null
     */
    public function userData($uid)
    {
        $response = $this->http->postJson(
            $this->url('getuserdata'),
            array('uid' => (string) (int) $uid),
            array(),
            $this->timeout()
        );

        $this->assertTransport($response, 'getuserdata');

        // 403：文档语义是"该用户没被本开发者验证过"
        // 400：实测当前恒返回这个，无法区分原因
        if ($response->status() === 403 || $response->status() === 400) {
            $this->logger->info('fanverify.user_data_unavailable', array(
                'uid' => (int) $uid, 'status' => $response->status(),
            ));
            return null;
        }

        if (!$response->ok()) {
            throw $this->httpError($response, 'getuserdata');
        }

        $payload = $response->json();
        if (!is_array($payload) || Arr::get($payload, 'status') !== self::STATUS_OK) {
            return null;
        }

        return $this->mapIdentity($payload, 'getuserdata');
    }

    /**
     * POST /openapi/tag —— 打风险标签
     *
     * ⚠ 打一次标签需要有效认证超过 1000 次用户，并一次性扣除 1000 额度；
     *   标签对所有开发者与用户可见，且一旦打下无法自助取消。
     *   本客户端只提供能力，**绝不自动调用** —— 是否打标签必须由人决定。
     *
     * ⚠ 与 getuserdata 同样：`accesstoken` 放 query string，不放 body。
     *   该方法**未经实测**（会扣额度，不能拿生产令牌做实验）。
     *
     * @param int|string $uid
     * @param string $tag
     * @param string $message 打标签的理由（会公开，需要有证据）
     * @return bool
     */
    public function applyTag($uid, $tag, $message)
    {
        $response = $this->http->postJson(
            $this->url('tag'),
            array(
                'tuid'    => (string) (int) $uid,
                'tag'     => (string) $tag,
                'message' => (string) $message,
            ),
            array(),
            $this->timeout()
        );

        $this->assertTransport($response, 'tag');

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : array();
        $code = Arr::toIntOrNull(Arr::get($payload, 'code'));

        if ($response->ok() && $code === 200) {
            $this->logger->info('fanverify.tag_applied', array('uid' => (int) $uid, 'tag' => $tag));
            return true;
        }

        $this->logger->warning('fanverify.tag_failed', array(
            'uid' => (int) $uid, 'status' => $response->status(), 'code' => $code,
            'message' => Arr::toTextOrNull(Arr::get($payload, 'message')),
        ));

        return false;
    }

    // ========================================================================
    //  内部
    // ========================================================================

    /**
     * GET 类接口的公共部分
     *
     * @param string $path
     * @param array<string,string> $extraQuery
     * @return array<string,mixed>
     */
    private function get($path, array $extraQuery = array())
    {
        if (!$this->isConfigured()) {
            throw ApiException::notImplemented(
                'FanVerify 尚未配置，请在 .env 中填写 FANVERIFY_API_BASE / FANVERIFY_ACCESS_TOKEN'
            );
        }

        $response = $this->http->get($this->url($path, $extraQuery), array(), $this->timeout());

        $this->assertTransport($response, $path);

        if (!$response->ok()) {
            throw $this->httpError($response, $path);
        }

        $payload = $response->json();
        if ($payload === null) {
            $this->logger->warning('fanverify.bad_json', array(
                'endpoint' => $path, 'body' => substr($response->body(), 0, 200),
            ));
            throw ApiException::serverError('FanVerify 返回了无法解析的数据');
        }

        return $payload;
    }

    /**
     * 拼 URL，自动带上 accesstoken
     *
     * @param string $path /openapi/ 后面的部分
     * @param array<string,string> $query
     * @return string
     */
    private function url($path, array $query = array())
    {
        $query = array_merge(array('accesstoken' => $this->accessToken()), $query);

        return $this->baseUrl() . '/openapi/' . ltrim($path, '/') . '?' . http_build_query($query);
    }

    /**
     * 传输层失败统一报错
     *
     * 把底层原因一并带出来：连不上/超时/DNS 失败/cURL 扩展缺失
     * 这几种情况的排查方向完全不同，只给一句"服务不可用"没法定位。
     */
    private function assertTransport($response, $endpoint)
    {
        if (!$response->failed()) {
            return;
        }

        $reason = (string) $response->transportError();

        $this->logger->warning('fanverify.transport_error', array(
            'endpoint' => $endpoint, 'error' => $reason,
        ));

        throw ApiException::serverError('FanVerify 服务暂时不可用（' . $reason . '）');
    }

    /**
     * 把 HTTP 状态翻译成对用户有意义的错误
     *
     * @return ApiException
     */
    private function httpError($response, $endpoint)
    {
        $status = $response->status();
        // 非 2xx 的响应体未必是 JSON —— CDN/WAF 出错时返回 HTML 错误页是常态
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : array();

        $message = Arr::toTextOrNull(Arr::get($payload, 'message'));
        $error = Arr::toTextOrNull(Arr::get($payload, 'error'));

        $this->logger->warning('fanverify.http_error', array(
            'endpoint' => $endpoint, 'status' => $status, 'error' => $error, 'message' => $message,
        ));

        if ($status === 401) {
            // 文档里 401 恒为 {"error":"Unauthorized"}。
            // 实测：FanVerify 先校验路径再鉴权 —— 不存在的路径返回 404，
            // 所以拿到 401 说明**路径是对的**，问题一定在令牌侧：
            // 无效、未启用、已过期，或来源 IP 不在白名单内。
            return ApiException::serverError(
                'FanVerify 拒绝了本次调用（401 未授权）。'
                . '请检查 FANVERIFY_ACCESS_TOKEN 是否有效、是否已在 FanVerify 开发者后台启用，'
                . '以及本服务器出口 IP 是否在令牌白名单内。'
            );
        }

        if ($status === 404) {
            // 路径写错了属于我们的问题，和令牌无关，要能一眼区分开
            $this->logger->error('fanverify.endpoint_not_found', array('endpoint' => $endpoint));

            return ApiException::serverError(
                'FanVerify 接口路径不存在（404）：' . $endpoint
                . '。这通常意味着 FANVERIFY_API_BASE 配错了，或 FanVerify 改了接口路径。'
            );
        }

        if ($status === 403) {
            return ApiException::forbidden(
                $message !== null ? $message : 'FanVerify 拒绝了本次操作（403 权限或额度不足）'
            );
        }

        if ($status === 429) {
            return ApiException::rateLimited('FanVerify 请求过于频繁，请稍后重试');
        }

        if ($message !== null) {
            return ApiException::serverError('FanVerify 返回错误：' . $message);
        }

        return ApiException::serverError('FanVerify 返回异常（HTTP ' . $status . '）');
    }

    /**
     * 文档里所有"返回用户信息"的接口都是同一个结构：
     *   { status: "ok", data: [ { uid, level, reg_time, tag } ] }
     *
     * @param array<string,mixed> $payload
     * @param string $endpoint
     * @return FanVerifyIdentity
     */
    private function mapIdentity(array $payload, $endpoint)
    {
        $row = Arr::getList($payload, 'data');
        $row = $row === array() ? array() : $row[0];

        if (!is_array($row)) {
            $this->logger->warning('fanverify.empty_data', array('endpoint' => $endpoint));
            throw ApiException::serverError('FanVerify 未返回用户数据');
        }

        $uid = Arr::toIntOrNull(Arr::get($row, 'uid'));
        if ($uid === null || $uid <= 0) {
            $this->logger->warning('fanverify.missing_uid', array(
                'endpoint' => $endpoint, 'keys' => array_keys($row),
            ));
            throw ApiException::serverError('FanVerify 未返回有效的账号ID');
        }

        return new FanVerifyIdentity(
            $uid,
            Arr::toIntOrNull(Arr::get($row, 'level')),
            Arr::toTextOrNull(Arr::get($row, 'tag')),
            Arr::toTextOrNull(Arr::get($row, 'reg_time'))
        );
    }

    /**
     * 日志用的响应摘要（避免把整包打出去）
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function summarize(array $payload)
    {
        return array(
            'keys' => array_keys($payload),
            'status' => Arr::toTextOrNull(Arr::get($payload, 'status')),
            'success' => Arr::get($payload, 'success', null),
        );
    }
}
