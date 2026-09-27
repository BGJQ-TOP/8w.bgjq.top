<?php

namespace W8\Passport\Http;

/**
 * 统一响应格式
 *
 * 成功：{"ok":true,"data":{...}}
 * 失败：{"ok":false,"error":{"code":"xxx","message":"..."}}
 *
 * 与旧接口的 {"success":true,...} 不冲突：通行证是新体系，格式从第一天就统一。
 */
final class Response
{
    /** @var int */
    private $status;

    /** @var mixed */
    private $payload;

    /** @var array<string,string> */
    private $headers;

    /**
     * @param int $status
     * @param mixed $payload
     * @param array<string,string> $headers
     */
    public function __construct($status, $payload, array $headers = array())
    {
        $this->status = (int) $status;
        $this->payload = $payload;
        $this->headers = $headers;
    }

    /**
     * @param mixed $data
     * @param array<string,string> $headers
     * @return Response
     */
    public static function ok($data = null, $headers = array())
    {
        return new self(200, array('ok' => true, 'data' => $data), $headers);
    }

    /**
     * 空响应体（RFC 7009 令牌吊销端点用）
     *
     * 输出 `{}` 而不是 `[]` —— 后者容易被客户端误判成"列表"。
     *
     * @param int $status
     * @return Response
     */
    public static function emptyBody($status = 200)
    {
        return new self($status, (object) array(), array('Cache-Control' => 'no-store'));
    }

    /**
     * @param string $code
     * @param string $message
     * @param int $status
     * @param array<string,mixed> $extra
     * @return Response
     */
    public static function error($code, $message, $status = 400, array $extra = array())
    {
        $error = array('code' => $code, 'message' => $message);
        if ($extra) {
            $error['details'] = $extra;
        }
        return new self($status, array('ok' => false, 'error' => $error));
    }

    /**
     * OAuth2 错误响应（RFC 6749 §5.2）
     *
     * @return Response
     */
    public static function oauthError($error, $description = '', $status = 400)
    {
        $payload = array('error' => $error);
        if ($description !== '') {
            $payload['error_description'] = $description;
        }
        return new self($status, $payload, array('Cache-Control' => 'no-store', 'Pragma' => 'no-cache'));
    }

    public function status()
    {
        return $this->status;
    }

    /**
     * @return mixed
     */
    public function payload()
    {
        return $this->payload;
    }

    public function withHeader($name, $value)
    {
        $this->headers[(string) $name] = (string) $value;
        return $this;
    }

    /**
     * 输出到客户端
     */
    public function send()
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
