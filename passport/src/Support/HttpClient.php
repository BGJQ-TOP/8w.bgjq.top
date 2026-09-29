<?php

namespace W8\Passport\Support;

/**
 * 统一的出站 HTTP 客户端
 *
 * 所有对接第三方接口的地方（玩家信息、邦国信息、简幻通、邮件、FanVerify）都走这里，
 * 保证超时、重试、日志、错误语义一致。
 *
 * 刻意不加 final：测试里用子类覆写 get/postJson 返回预设响应，
 * 就能在没有网络、没有 cURL 扩展的环境下验证第三方响应映射
 * （见 passport/tests/smoke.php 的 FanVerifyClient 用例）。
 */
class HttpClient
{
    /** @var Logger */
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $url
     * @param array<string,mixed>|null $jsonBody
     * @param array<string,string> $headers
     * @param int $timeout
     * @return HttpResponse
     */
    public function get($url, array $headers = array(), $timeout = 8)
    {
        return $this->request('GET', $url, null, $headers, $timeout);
    }

    /**
     * @param string $url
     * @param array<string,mixed>|null $jsonBody
     * @param array<string,string> $headers
     * @param int $timeout
     * @return HttpResponse
     */
    public function postJson($url, array $jsonBody = array(), array $headers = array(), $timeout = 8)
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        return $this->request('POST', $url, $jsonBody, $headers, $timeout);
    }

    /**
     * @param string $url
     * @param array<string,mixed>|null $jsonBody
     * @param array<string,string> $headers
     * @param int $timeout
     * @return HttpResponse
     */
    public function postForm($url, array $formBody = array(), array $headers = array(), $timeout = 8)
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';
        return $this->request('POST', $url, $formBody, $headers, $timeout, true);
    }

    /**
     * @return HttpResponse
     */
    private function request($method, $url, $body, array $headers, $timeout, $asForm = false)
    {
        $started = microtime(true);

        if (!function_exists('curl_init')) {
            $this->logger->error('http.curl_missing', array('url' => $url));
            return HttpResponse::failure('服务器未安装 cURL 扩展，无法访问外部接口');
        }

        $ch = curl_init();
        $headerLines = array();
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, (int) $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, '8W-Passport/1.0 (+https://8w.bgjq.top)');

        if ($headerLines) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $asForm ? http_build_query($body) : json_encode($body, JSON_UNESCAPED_UNICODE));
            }
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $elapsed = (int) ((microtime(true) - $started) * 1000);

        if ($errno !== 0) {
            $this->logger->warning('http.transport_error', array(
                'url' => self::sanitizeUrl($url), 'errno' => $errno, 'error' => $error, 'elapsed_ms' => $elapsed,
            ));
            return HttpResponse::failure('外部接口连接失败：' . $error, $errno);
        }

        $this->logger->debug('http.response', array(
            'url' => self::sanitizeUrl($url), 'method' => $method, 'status' => $status, 'elapsed_ms' => $elapsed,
        ));

        return new HttpResponse($status, is_string($raw) ? $raw : '', null);
    }

    /**
     * 抹掉 URL 查询串里的敏感参数
     *
     * 很多第三方接口要求把令牌直接放在 query string 上（FanVerify 的 accesstoken 就是），
     * 而日志里记 URL 是常规操作 —— 不处理的话令牌会被写进 passport/storage/logs。
     *
     * 用正则只替换敏感参数的**值**，其余部分逐字节保留：
     * 既不破坏 URL 原貌（便于排查），也不会像 http_build_query 那样把掩码本身编码掉。
     *
     * @param string $url
     * @return string
     */
    public static function sanitizeUrl($url)
    {
        $sensitive = array(
            'accesstoken', 'access_token', 'token', 'api_key', 'apikey', 'api_secret',
            'pass_code', 'passcode', 'password', 'secret', 'code', 'otp', 'signature', 'sign',
        );

        $url = (string) $url;
        $pos = strpos($url, '?');
        if ($pos === false) {
            return $url;
        }

        $base = substr($url, 0, $pos);
        $query = substr($url, $pos + 1);

        $pattern = '/(^|&)(' . implode('|', $sensitive) . ')=[^&]*/i';
        $sanitized = preg_replace($pattern, '$1$2=***', $query);

        return $base . '?' . ($sanitized === null ? $query : $sanitized);
    }
}
