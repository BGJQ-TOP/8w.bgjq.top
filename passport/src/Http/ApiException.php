<?php

namespace W8\Passport\Http;

use RuntimeException;

/**
 * 业务异常
 *
 * 所有可预期的失败都通过它抛出，由 Endpoint 统一翻译成 HTTP 响应。
 * 同时承担 OAuth2 错误（RFC 6749 §5.2）的载体，避免再引入第二套异常体系。
 */
final class ApiException extends RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $httpStatus;

    /** @var array<string,mixed> */
    private $details;

    /** @var string|null 非 null 时按 OAuth2 错误格式输出 */
    private $oauthError;

    /**
     * @param string $errorCode
     * @param string $message
     * @param int $httpStatus
     * @param array<string,mixed> $details
     * @param string|null $oauthError
     */
    public function __construct($errorCode, $message, $httpStatus = 400, array $details = array(), $oauthError = null)
    {
        parent::__construct($message);
        $this->errorCode = (string) $errorCode;
        $this->httpStatus = (int) $httpStatus;
        $this->details = $details;
        $this->oauthError = $oauthError;
    }

    public static function validation($message, array $details = array())
    {
        return new self('invalid_request', $message, 422, $details);
    }

    public static function unauthorized($message = '请先登录')
    {
        return new self('unauthorized', $message, 401);
    }

    public static function forbidden($message = '没有权限执行该操作')
    {
        return new self('forbidden', $message, 403);
    }

    public static function notFound($message = '资源不存在')
    {
        return new self('not_found', $message, 404);
    }

    public static function conflict($message, array $details = array())
    {
        return new self('conflict', $message, 409, $details);
    }

    public static function rateLimited($message = '请求过于频繁，请稍后再试')
    {
        return new self('rate_limited', $message, 429);
    }

    public static function notImplemented($message, array $details = array())
    {
        return new self('not_implemented', $message, 501, $details);
    }

    public static function serverError($message = '服务器内部错误')
    {
        return new self('server_error', $message, 500);
    }

    /**
     * OAuth2 协议错误
     *
     * @param string $oauthError invalid_request / invalid_client / invalid_grant /
     *                           unauthorized_client / unsupported_grant_type /
     *                           invalid_scope / access_denied
     */
    public static function oauth($oauthError, $description = '', $status = 400)
    {
        return new self($oauthError, $description !== '' ? $description : $oauthError, $status, array(), $oauthError);
    }

    public function errorCode()
    {
        return $this->errorCode;
    }

    public function httpStatus()
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string,mixed>
     */
    public function details()
    {
        return $this->details;
    }

    public function oauthError()
    {
        return $this->oauthError;
    }

    public function isOAuth()
    {
        return $this->oauthError !== null;
    }
}
