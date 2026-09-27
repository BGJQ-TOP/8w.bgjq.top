<?php

namespace W8\Passport\Verification;

use W8\Passport\Contracts\FanVerifyVerifier;
use W8\Passport\Http\ApiException;
use W8\Passport\Support\Arr;
use W8\Passport\Support\Config;
use W8\Passport\Support\HttpClient;
use W8\Passport\Support\Logger;

/**
 * FanVerify 验证 —— HTTP 实现
 *
 * ============================================================================
 *  ⚠ TODO：等待 FanVerify 官方接口
 * ----------------------------------------------------------------------------
 *  FanVerify 是**可选绑定**（与验证邮箱同级）：不绑也能注册、能登录，
 *  绑定后只是多一条身份凭据。因此本文件未配置时只会让"绑定"这一步失败，
 *  不会影响任何主流程。
 *
 *  对接时通常只需在 .env 里配置：
 *
 *    FANVERIFY_API_URL           接口地址
 *    FANVERIFY_API_TOKEN         调用令牌（可选，填了就以 Bearer 带上）
 *    FANVERIFY_API_TIMEOUT       超时秒数，默认 8
 *    FANVERIFY_API_METHOD        POST（默认）/ GET
 *    FANVERIFY_API_SUCCESS_CODE  业务成功码，默认 200；留空表示只看 HTTP 状态
 *    FANVERIFY_API_CODE_FIELD    业务码字段路径，默认 code
 *    FANVERIFY_API_MESSAGE_FIELD 错误文案字段路径，默认 msg
 *    FANVERIFY_API_UID_FIELD     账号ID字段路径，默认 data.uid
 *    FANVERIFY_API_PLAYER_FIELD  绑定的游戏玩家名字段路径（可选，用于交叉校验）
 *
 *  若新接口形态不同（JSON body、签名头、嵌套结构），
 *  重写 buildRequest() / mapIdentity() / authHeaders() 三个方法即可，上层无感。
 * ============================================================================
 */
final class HttpFanVerifyVerifier implements FanVerifyVerifier
{
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
        return $this->config->getString('FANVERIFY_API_URL') !== '';
    }

    public function sourceName()
    {
        return 'http:' . $this->config->getString('FANVERIFY_API_URL', '(unconfigured)');
    }

    public function verify($fanverifyUid, $verifyCode, $playerName)
    {
        if (!$this->isConfigured()) {
            throw ApiException::notImplemented(
                'FanVerify 验证接口尚未接入，请在 .env 中配置 FANVERIFY_API_URL'
            );
        }

        $url = $this->config->getString('FANVERIFY_API_URL');
        $timeout = $this->config->getInt('FANVERIFY_API_TIMEOUT', 8);
        $params = $this->buildRequest($fanverifyUid, $verifyCode, $playerName);

        $method = strtoupper($this->config->getString('FANVERIFY_API_METHOD', 'POST'));
        if ($method === 'GET') {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $response = $this->http->get($url . $separator . http_build_query($params), $this->authHeaders(), $timeout);
        } else {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $response = $this->http->postForm(
                $url . $separator . http_build_query($params),
                array(),
                $this->authHeaders(),
                $timeout
            );
        }

        if ($response->failed()) {
            $this->logger->warning('fanverify.transport_error', array('error' => $response->transportError()));
            throw ApiException::serverError('FanVerify 验证服务暂时不可用，请稍后重试');
        }

        if (!$response->ok()) {
            $this->logger->warning('fanverify.bad_status', array(
                'status' => $response->status(), 'body' => substr($response->body(), 0, 300),
            ));
            throw ApiException::serverError('FanVerify 验证服务返回异常（HTTP ' . $response->status() . '）');
        }

        $payload = $response->json();
        if ($payload === null) {
            $this->logger->warning('fanverify.invalid_json', array('body' => substr($response->body(), 0, 300)));
            throw ApiException::serverError('FanVerify 验证服务返回了无法解析的数据');
        }

        // 业务码校验：留空则只看 HTTP 状态，适配"不返回业务码"的接口
        $successCode = $this->config->getString('FANVERIFY_API_SUCCESS_CODE', '200');
        if ($successCode !== '') {
            $codeField = $this->config->getString('FANVERIFY_API_CODE_FIELD', 'code');
            $actualCode = Arr::get($payload, $codeField, null);

            if ($actualCode === null || (string) $actualCode !== $successCode) {
                $messageField = $this->config->getString('FANVERIFY_API_MESSAGE_FIELD', 'msg');
                $message = Arr::toTextOrNull(Arr::get($payload, $messageField, null));
                $this->logger->info('fanverify.rejected', array('code' => $actualCode, 'message' => $message));

                throw ApiException::validation(
                    'FanVerify 验证失败：' . ($message !== null ? $message : '验证码错误或已过期'),
                    array('field' => 'fanverify_code')
                );
            }
        }

        return $this->mapIdentity($payload, $fanverifyUid, $playerName);
    }

    /**
     * ⚠ TODO：FanVerify 接口对接点 —— 请求参数组装
     *
     * @return array<string,string>
     */
    private function buildRequest($fanverifyUid, $verifyCode, $playerName)
    {
        return array(
            'uid'         => (string) (int) $fanverifyUid,
            'verify_code' => (string) $verifyCode,
            'mc_username' => (string) $playerName,
            'ip'          => $this->clientIp(),
        );
    }

    /**
     * ⚠ TODO：FanVerify 接口对接点 —— 响应字段映射
     *
     * @param array<string,mixed> $payload
     * @return FanVerifyIdentity
     */
    private function mapIdentity(array $payload, $fanverifyUid, $playerName)
    {
        $uidPath = $this->config->getString('FANVERIFY_API_UID_FIELD', 'data.uid');
        $playerPath = $this->config->getString('FANVERIFY_API_PLAYER_FIELD');

        $uid = Arr::toIntOrNull(Arr::first(
            $payload,
            array($uidPath, 'data.uid', 'data.id', 'uid', 'user_info.uid'),
            null
        ));

        if ($uid === null || $uid <= 0) {
            $this->logger->warning('fanverify.missing_uid', array('keys' => array_keys($payload)));
            throw ApiException::serverError('FanVerify 验证服务未返回有效的账号ID');
        }

        $boundPlayer = null;
        if ($playerPath !== '') {
            $boundPlayer = Arr::toTextOrNull(Arr::get($payload, $playerPath, null));
        }

        // FanVerify 侧若返回了绑定的游戏名，与当前通行证的不一致就拒绝
        if ($boundPlayer !== null && $playerName !== '' && strcasecmp($boundPlayer, $playerName) !== 0) {
            throw ApiException::validation(
                'FanVerify 账号绑定的游戏玩家名为「' . $boundPlayer . '」，与当前通行证的「' . $playerName . '」不一致',
                array('field' => 'fanverify_uid')
            );
        }

        return new FanVerifyIdentity($uid, $boundPlayer);
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders()
    {
        $token = $this->config->getString('FANVERIFY_API_TOKEN');
        return $token === '' ? array() : array('Authorization' => 'Bearer ' . $token);
    }

    private function clientIp()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        }
        return $ip;
    }
}
