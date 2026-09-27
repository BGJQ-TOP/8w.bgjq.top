<?php

namespace W8\Passport\Verification;

use W8\Passport\Contracts\SimpassVerifier;
use W8\Passport\Http\ApiException;
use W8\Passport\Support\Arr;
use W8\Passport\Support\Config;
use W8\Passport\Support\HttpClient;
use W8\Passport\Support\Logger;

/**
 * 简幻通验证 —— HTTP 实现
 *
 * ============================================================================
 *  ⚠ TODO：等待"新的简幻通接口"
 * ----------------------------------------------------------------------------
 *  这里保留了旧站已经跑通的调用形态（POST + query string，返回
 *  {code:200, msg, user_info:{simpass_uid, level}}），并把所有可变部分抽成配置：
 *
 *    SIMPASS_API_URL              接口地址
 *    SIMPPASS_ACCESS_TOKEN        调用令牌
 *    SIMPASS_API_TIMEOUT          超时秒数，默认 8
 *    SIMPASS_API_METHOD           POST（默认）/ GET
 *    SIMPASS_API_SUCCESS_CODE     业务成功码，默认 200
 *    SIMPASS_API_CODE_FIELD       业务码字段路径，默认 code
 *    SIMPASS_API_MESSAGE_FIELD    错误文案字段路径，默认 msg
 *    SIMPASS_API_UID_FIELD        简幻通ID字段路径，默认 user_info.simpass_uid
 *    SIMPASS_API_LEVEL_FIELD      等级字段路径，默认 user_info.level
 *    SIMPASS_API_PLAYER_FIELD     简幻通侧玩家名字段路径（可选，用于交叉校验）
 *
 *  新接口形态不同时，重写 buildRequest() / mapIdentity() 即可，上层无感。
 * ============================================================================
 */
final class HttpSimpassVerifier implements SimpassVerifier
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
        return $this->config->getString('SIMPASS_API_URL') !== ''
            && $this->config->getString('SIMPPASS_ACCESS_TOKEN') !== '';
    }

    public function sourceName()
    {
        return 'http:' . $this->config->getString('SIMPASS_API_URL', '(unconfigured)');
    }

    public function verify($simpassUid, $verifyCode, $playerName)
    {
        if (!$this->isConfigured()) {
            throw ApiException::notImplemented(
                '简幻通验证接口尚未接入，请在 .env 中配置 SIMPASS_API_URL / SIMPPASS_ACCESS_TOKEN'
            );
        }

        $url = $this->config->getString('SIMPASS_API_URL');
        $timeout = $this->config->getInt('SIMPASS_API_TIMEOUT', 8);
        $params = $this->buildRequest($simpassUid, $verifyCode, $playerName);

        $method = strtoupper($this->config->getString('SIMPASS_API_METHOD', 'POST'));
        if ($method === 'GET') {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $response = $this->http->get($url . $separator . http_build_query($params), array(), $timeout);
        } else {
            // 旧接口把参数放在 query string 上，这里保持一致；新接口若是 JSON body，
            // 把下面这行换成 $this->http->postJson($url, $params, ...) 即可。
            $separator = strpos($url, '?') === false ? '?' : '&';
            $response = $this->http->postForm($url . $separator . http_build_query($params), array(), array(), $timeout);
        }

        if ($response->failed()) {
            $this->logger->warning('simpass.transport_error', array('error' => $response->transportError()));
            throw ApiException::serverError('简幻通验证服务暂时不可用，请稍后重试');
        }

        if (!$response->ok()) {
            $this->logger->warning('simpass.bad_status', array(
                'status' => $response->status(), 'body' => substr($response->body(), 0, 300),
            ));
            throw ApiException::serverError('简幻通验证服务返回异常（HTTP ' . $response->status() . '）');
        }

        $payload = $response->json();
        if ($payload === null) {
            $this->logger->warning('simpass.invalid_json', array('body' => substr($response->body(), 0, 300)));
            throw ApiException::serverError('简幻通验证服务返回了无法解析的数据');
        }

        $successCode = $this->config->getString('SIMPASS_API_SUCCESS_CODE', '200');
        $codeField = $this->config->getString('SIMPASS_API_CODE_FIELD', 'code');
        $actualCode = Arr::get($payload, $codeField, null);

        if ($actualCode === null || (string) $actualCode !== $successCode) {
            $messageField = $this->config->getString('SIMPASS_API_MESSAGE_FIELD', 'msg');
            $message = Arr::toTextOrNull(Arr::get($payload, $messageField, null));
            $this->logger->info('simpass.rejected', array('code' => $actualCode, 'message' => $message));
            throw ApiException::validation(
                '简幻通验证失败：' . ($message !== null ? $message : '验证码错误或已过期'),
                array('field' => 'simpass_code')
            );
        }

        return $this->mapIdentity($payload, $simpassUid, $playerName);
    }

    /**
     * ⚠ TODO：新接口对接点 —— 请求参数组装
     *
     * @return array<string,string>
     */
    private function buildRequest($simpassUid, $verifyCode, $playerName)
    {
        return array(
            'token'       => $this->config->getString('SIMPPASS_ACCESS_TOKEN'),
            'user_id'     => (string) (int) $simpassUid,
            'verify_code' => (string) $verifyCode,
            'mc_username' => (string) $playerName,
            'mc_uuid'     => '',
            'ip'          => $this->clientIp(),
        );
    }

    /**
     * ⚠ TODO：新接口对接点 —— 响应字段映射
     *
     * @param array<string,mixed> $payload
     * @return SimpassIdentity
     */
    private function mapIdentity(array $payload, $simpassUid, $playerName)
    {
        $uidPath = $this->config->getString('SIMPASS_API_UID_FIELD', 'user_info.simpass_uid');
        $levelPath = $this->config->getString('SIMPASS_API_LEVEL_FIELD', 'user_info.level');
        $playerPath = $this->config->getString('SIMPASS_API_PLAYER_FIELD');

        $uid = Arr::toIntOrNull(Arr::first($payload, array($uidPath, 'user_info.simpass_uid', 'user_info.uid', 'uid'), null));
        if ($uid === null || $uid <= 0) {
            $this->logger->warning('simpass.missing_uid', array('keys' => array_keys($payload)));
            throw ApiException::serverError('简幻通验证服务未返回有效的简幻通ID');
        }

        $level = Arr::toIntOrNull(Arr::first($payload, array($levelPath, 'user_info.level', 'level'), null));

        $boundPlayer = null;
        if ($playerPath !== '') {
            $boundPlayer = Arr::toTextOrNull(Arr::get($payload, $playerPath, null));
        }

        // 简幻通侧若返回了绑定的游戏名，与用户填写的不一致就直接拒绝
        if ($boundPlayer !== null && $playerName !== '' && strcasecmp($boundPlayer, $playerName) !== 0) {
            throw ApiException::validation(
                '简幻通账号绑定的游戏玩家名为「' . $boundPlayer . '」，与您填写的「' . $playerName . '」不一致',
                array('field' => 'player_name')
            );
        }

        return new SimpassIdentity($uid, $level, $boundPlayer);
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
