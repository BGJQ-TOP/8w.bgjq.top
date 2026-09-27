<?php

namespace W8\Passport\Verification;

use W8\Passport\Contracts\EmailVerifier;
use W8\Passport\Http\ApiException;
use W8\Passport\Support\Arr;
use W8\Passport\Support\Config;
use W8\Passport\Support\HttpClient;
use W8\Passport\Support\Logger;
use W8\Passport\Support\Str;

/**
 * 邮箱验证码发送 —— HTTP 实现
 *
 * ============================================================================
 *  ⚠ TODO：等待"新的邮件发送接口"
 * ----------------------------------------------------------------------------
 *  对接时通常只需在 .env 里配置：
 *
 *    EMAIL_API_URL          发送接口地址
 *    EMAIL_API_TOKEN        可选，填了就以 Bearer 带上
 *    EMAIL_API_TIMEOUT      超时秒数，默认 8
 *    EMAIL_API_METHOD       POST（默认）或 GET
 *    EMAIL_API_BODY_TEMPLATE  请求体模板（JSON），占位符：
 *                             {email} {code} {scene} {ttl} {minutes}
 *                             默认 {"to":"{email}","code":"{code}","scene":"{scene}","ttl":{ttl}}
 *    EMAIL_API_SUCCESS_FIELD  可选，判定成功的字段路径；值为 false 视为失败
 *    EMAIL_API_MESSAGE_FIELD  可选，失败时取错误文案的字段路径
 *
 *  如果新接口是 GET 查询串、或者需要签名头，重写 buildRequest() / authHeaders() 即可。
 * ============================================================================
 */
final class HttpEmailVerifier implements EmailVerifier
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
        return $this->config->getString('EMAIL_API_URL') !== '';
    }

    public function sourceName()
    {
        return 'http:' . $this->config->getString('EMAIL_API_URL', '(unconfigured)');
    }

    public function sendCode($email, $code, $scene, $ttlSeconds)
    {
        if (!$this->isConfigured()) {
            throw ApiException::notImplemented('邮箱验证码发送接口尚未接入，请在 .env 中配置 EMAIL_API_URL');
        }

        $url = $this->config->getString('EMAIL_API_URL');
        $timeout = $this->config->getInt('EMAIL_API_TIMEOUT', 8);
        $headers = $this->authHeaders();

        $method = strtoupper($this->config->getString('EMAIL_API_METHOD', 'POST'));
        if ($method === 'GET') {
            $query = http_build_query(array(
                'email' => $email,
                'code'  => $code,
                'scene' => $scene,
                'ttl'   => (int) $ttlSeconds,
            ));
            $response = $this->http->get($url . (strpos($url, '?') === false ? '?' : '&') . $query, $headers, $timeout);
        } else {
            $response = $this->http->postJson($url, $this->buildBody($email, $code, $scene, $ttlSeconds), $headers, $timeout);
        }

        if ($response->failed()) {
            $this->logger->error('email_verifier.transport_error', array(
                'email' => Str::maskEmail($email), 'error' => $response->transportError(),
            ));
            throw ApiException::serverError('验证码发送失败，请稍后重试');
        }

        if (!$response->ok()) {
            $this->logger->error('email_verifier.bad_status', array(
                'email' => Str::maskEmail($email), 'status' => $response->status(),
                'body' => substr($response->body(), 0, 300),
            ));
            throw ApiException::serverError('验证码发送失败（HTTP ' . $response->status() . '）');
        }

        $payload = $response->json();
        if (is_array($payload)) {
            $successPath = $this->config->getString('EMAIL_API_SUCCESS_FIELD');
            if ($successPath !== '' && Arr::get($payload, $successPath, true) === false) {
                $messagePath = $this->config->getString('EMAIL_API_MESSAGE_FIELD', 'message');
                $message = Arr::toTextOrNull(Arr::get($payload, $messagePath, null));
                throw ApiException::serverError($message !== null ? $message : '验证码发送失败');
            }
        }

        $this->logger->info('email_verifier.sent', array('email' => Str::maskEmail($email), 'scene' => $scene));
    }

    /**
     * ⚠ TODO：新接口对接点 —— 请求体模板
     *
     * @return array<string,mixed>
     */
    private function buildBody($email, $code, $scene, $ttlSeconds)
    {
        $template = $this->config->getString(
            'EMAIL_API_BODY_TEMPLATE',
            '{"to":"{email}","code":"{code}","scene":"{scene}","ttl":{ttl}}'
        );

        $rendered = str_replace(
            array('{email}', '{code}', '{scene}', '{ttl}', '{minutes}', '{app_name}'),
            array(
                $email,
                $code,
                $scene,
                (string) (int) $ttlSeconds,
                (string) max(1, (int) ceil($ttlSeconds / 60)),
                $this->config->getString('EMAIL_FROM_NAME', '8W通行证'),
            ),
            $template
        );

        $decoded = json_decode($rendered, true);
        if (!is_array($decoded)) {
            // 模板写坏了就直接报错，别发出去一个空请求
            throw ApiException::serverError('EMAIL_API_BODY_TEMPLATE 不是合法 JSON，请检查 .env');
        }

        return $decoded;
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders()
    {
        $token = $this->config->getString('EMAIL_API_TOKEN');
        return $token === '' ? array() : array('Authorization' => 'Bearer ' . $token);
    }
}
