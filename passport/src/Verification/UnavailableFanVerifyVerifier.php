<?php

namespace W8\Passport\Verification;

use W8\Passport\Contracts\FanVerifyVerifier;
use W8\Passport\Http\ApiException;

/**
 * FanVerify 验证 —— 未配置（Null Object）
 *
 * 代码已完整接入 fanverify.cn openAPI，未配置只会出现在"没填令牌"的情况下。
 * 把 FANVERIFY_ACCESS_TOKEN 填进 .env，Application 会自动改用 HttpFanVerifyVerifier。
 *
 * 注意：FanVerify 是可选绑定，未配置**不影响注册与登录**；
 * 只有在用户主动发起 FanVerify 绑定时才会看到这个错误。
 */
final class UnavailableFanVerifyVerifier implements FanVerifyVerifier
{
    /** @var string */
    private $reason;

    public function __construct($reason = 'FanVerify 访问令牌未配置')
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

    public function verify($fanverifyUid, $verifyCode, $playerName)
    {
        throw $this->unavailable();
    }

    public function requestOtp()
    {
        throw $this->unavailable();
    }

    public function qrCodePng($otp)
    {
        throw $this->unavailable();
    }

    public function pollOtp($otp)
    {
        throw $this->unavailable();
    }

    /**
     * @return ApiException
     */
    private function unavailable()
    {
        return ApiException::notImplemented(
            $this->reason . '。请在 .env 中填写 FANVERIFY_ACCESS_TOKEN（见 passport/README.md）'
        );
    }
}
