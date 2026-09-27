<?php

namespace W8\Passport\Verification;

use W8\Passport\Contracts\EmailVerifier;
use W8\Passport\Http\ApiException;

/**
 * 邮箱验证码发送 —— 未接入（Null Object）
 *
 * ⚠ TODO：邮件发送接口待对接。
 *   把接口地址填进 .env 的 EMAIL_API_URL，Application 会自动改用 HttpEmailVerifier。
 */
final class UnavailableEmailVerifier implements EmailVerifier
{
    /** @var string */
    private $reason;

    public function __construct($reason = '邮箱验证码发送接口尚未接入')
    {
        $this->reason = (string) $reason;
    }

    public function isConfigured()
    {
        return false;
    }

    public function sourceName()
    {
        return 'unavailable';
    }

    public function sendCode($email, $code, $scene, $ttlSeconds)
    {
        throw ApiException::notImplemented(
            $this->reason . '。请在 .env 中配置 EMAIL_API_URL / EMAIL_API_TOKEN（见 passport/README.md）'
        );
    }
}
