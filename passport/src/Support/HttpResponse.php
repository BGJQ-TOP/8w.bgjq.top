<?php

namespace W8\Passport\Support;

/**
 * 出站请求的结果
 *
 * 刻意把「传输层失败」和「HTTP 状态码」分开表达：
 * 前者说明根本没连上（failed() 为 true），后者说明连上了但对方返回了错误。
 * 两者的处理方式完全不同，混在一起会导致"超时"被误判成"资源不存在"。
 */
final class HttpResponse
{
    /** @var int */
    private $status;

    /** @var string */
    private $body;

    /** @var string|null */
    private $transportError;

    public function __construct($status, $body, $transportError = null)
    {
        $this->status = (int) $status;
        $this->body = (string) $body;
        $this->transportError = $transportError;
    }

    /**
     * 构造一个传输层失败的结果
     *
     * @param string $message
     * @param int $code
     * @return HttpResponse
     */
    public static function failure($message, $code = 0)
    {
        return new self($code, '', (string) $message);
    }

    public function failed()
    {
        return $this->transportError !== null;
    }

    public function transportError()
    {
        return $this->transportError;
    }

    public function status()
    {
        return $this->status;
    }

    public function body()
    {
        return $this->body;
    }

    public function ok()
    {
        return !$this->failed() && $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string,mixed>|null 无法解析时返回 null
     */
    public function json()
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function notFound()
    {
        return $this->status === 404;
    }
}
