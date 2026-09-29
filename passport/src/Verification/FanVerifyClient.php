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

        // 429 rate_limit 与 200 wait/ok 都是"正常业务状态"，不算错误
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
            throw $this->httpError($response, 'user_verify');
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

    // ========================================================================
    //  用户数据 / 风险标签
    // ========================================================================

    /**
     * POST /openapi/getuserdata —— 获取该开发者验证过的用户数据
     *
     * @param int|string $uid
     * @return FanVerifyIdentity|null 未被本开发者验证过时返回 null（403）
     */
    public function userData($uid)
    {
        $response = $this->http->postJson(
            $this->baseUrl() . '/openapi/getuserdata',
            array('accesstoken' => $this->accessToken(), 'uid' => (string) (int) $uid),
            array(),
            $this->timeout()
        );

        $this->assertTransport($response, 'getuserdata');

        if ($response->status() === 403) {
            // 文档里 403 的语义就是"没被本开发者验证过"
            return null;
        }

        if (!$response->ok()) {
            throw $this->httpError($response, 'getuserdata');
        }

        $payload = $response->json();
        if ($payload === null || Arr::get($payload, 'status') !== self::STATUS_OK) {
            return null;
        }

        return $this->mapIdentity($payload, 'getuserdata');
    }

    /**
     * POST /openapi/tag —— 打风险标签
     *
     * ⚠ 打一次标签需要有效认证超过 1000 次用户，并一次性扣除 1000 额度；
     *   标签对所有开发者与用户可见，且一旦打下无法自助取消。
     *   本客户端只提供能力，绝不自动调用 —— 是否打标签必须由人决定。
     *
     * @param int|string $uid
     * @param string $tag
     * @param string $message 打标签的理由（会公开，需要有证据）
     * @return bool
     */
    public function applyTag($uid, $tag, $message)
    {
        $response = $this->http->postJson(
            $this->baseUrl() . '/openapi/tag',
            array(
                'accesstoken' => $this->accessToken(),
                'tuid'        => (string) (int) $uid,
                'tag'         => (string) $tag,
                'message'     => (string) $message,
            ),
            array(),
            $this->timeout()
        );

        $this->assertTransport($response, 'tag');

        $payload = $response->json();
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
        $payload = $response->json();
        $message = Arr::toTextOrNull(Arr::get($payload, 'message'));
        $error = Arr::toTextOrNull(Arr::get($payload, 'error'));

        $this->logger->warning('fanverify.http_error', array(
            'endpoint' => $endpoint, 'status' => $status, 'error' => $error, 'message' => $message,
        ));

        if ($status === 401) {
            // 文档里 401 恒为 {"error":"Unauthorized"}：令牌无效、未启用或来源 IP 未在白名单内
            return ApiException::serverError(
                'FanVerify 拒绝了本次调用（401 未授权）。'
                . '请检查 FANVERIFY_ACCESS_TOKEN 是否有效、是否已在 FanVerify 开发者后台启用，'
                . '以及本服务器出口 IP 是否在令牌白名单内。'
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
