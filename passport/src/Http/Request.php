<?php

namespace W8\Passport\Http;

/**
 * 请求对象
 *
 * 把 $_SERVER / $_GET / php://input 收敛成只读视图，
 * 业务代码不再直接碰超全局变量。
 */
final class Request
{
    /** @var string */
    private $method;

    /** @var string */
    private $path;

    /** @var array<string,mixed> */
    private $query;

    /** @var array<string,mixed> */
    private $body;

    /** @var array<string,string> */
    private $headers;

    /** @var array<string,mixed> */
    private $cookies;

    /** @var array<string,mixed> */
    private $server;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,mixed> $cookies
     * @param array<string,mixed> $server
     */
    public function __construct($method, $path, array $query = array(), array $body = array(), array $headers = array(), array $cookies = array(), array $server = array())
    {
        $this->method = strtoupper((string) $method);
        $this->path = '/' . ltrim((string) $path, '/');
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
        $this->cookies = $cookies;
        $this->server = $server;
    }

    /**
     * 从当前请求上下文构建
     */
    public static function fromGlobals()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $headers = array();
        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self($method, $path, $_GET, self::readBody($headers), $headers, $_COOKIE, $_SERVER);
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private static function readBody(array $headers)
    {
        $contentType = isset($headers['content-type']) ? strtolower($headers['content-type']) : '';
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return array();
        }

        if (strpos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : array();
        }

        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            $parsed = array();
            parse_str($raw, $parsed);
            return $parsed;
        }

        // 未声明 Content-Type 时先按 JSON 试一次，兼容各种手搓客户端
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $parsed = array();
        parse_str($raw, $parsed);
        return $parsed;
    }

    public function method()
    {
        return $this->method;
    }

    public function path()
    {
        return $this->path;
    }

    public function isPost()
    {
        return $this->method === 'POST';
    }

    /**
     * 取参数：body 优先，其次 query
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function input($key, $default = null)
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        return $default;
    }

    public function string($key, $default = '')
    {
        $value = $this->input($key, null);
        if (is_array($value) || $value === null) {
            return (string) $default;
        }
        return trim((string) $value);
    }

    public function int($key, $default = 0)
    {
        $value = $this->input($key, null);
        if (is_array($value) || $value === null || $value === '') {
            return (int) $default;
        }
        return (int) $value;
    }

    public function bool($key, $default = false)
    {
        $value = $this->input($key, null);
        if ($value === null) {
            return (bool) $default;
        }
        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
    }

    /**
     * @return array<string,mixed>
     */
    public function all()
    {
        return array_merge($this->query, $this->body);
    }

    public function query($key, $default = null)
    {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    public function header($name, $default = '')
    {
        $name = strtolower((string) $name);
        return array_key_exists($name, $this->headers) ? $this->headers[$name] : (string) $default;
    }

    public function cookie($name, $default = '')
    {
        return array_key_exists($name, $this->cookies) ? (string) $this->cookies[$name] : (string) $default;
    }

    public function ip()
    {
        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }
        $real = $this->header('x-real-ip');
        if ($real !== '') {
            return $real;
        }
        return isset($this->server['REMOTE_ADDR']) ? (string) $this->server['REMOTE_ADDR'] : '';
    }

    public function userAgent()
    {
        return substr($this->header('user-agent'), 0, 255);
    }

    /**
     * Authorization: Bearer xxx
     */
    public function bearerToken()
    {
        $header = $this->header('authorization');
        if ($header === '') {
            return '';
        }
        if (stripos($header, 'bearer ') === 0) {
            return trim(substr($header, 7));
        }
        return '';
    }

    /**
     * Basic 认证（OAuth2 机密客户端可用 client_secret_basic）
     *
     * @return array{0:string,1:string}|null [clientId, clientSecret]
     */
    public function basicAuth()
    {
        $header = $this->header('authorization');
        if ($header === '' || stripos($header, 'basic ') !== 0) {
            return null;
        }
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return null;
        }
        list($user, $pass) = explode(':', $decoded, 2);
        return array(urldecode($user), urldecode($pass));
    }

    public function wantsHtml()
    {
        $accept = $this->header('accept');
        return $accept !== '' && strpos($accept, 'text/html') !== false;
    }

    /**
     * @return array<string,mixed>
     */
    public function server()
    {
        return $this->server;
    }
}
