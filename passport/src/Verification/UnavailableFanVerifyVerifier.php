<?php

namespace W8\Passport\Verification;

use W8\Passport\Contracts\FanVerifyVerifier;
use W8\Passport\Http\ApiException;

/**
 * FanVerify 验证 —— 未接入（Null Object）
 *
 * ⚠ TODO：FanVerify 接口待对接。
 *   把接口地址填进 .env 的 FANVERIFY_API_URL，Application 会自动改用 HttpFanVerifyVerifier。
 *
 * 注意：FanVerify 是可选绑定，未接入**不影响注册与登录**；
 * 只有在用户主动发起 FanVerify 绑定时才会看到这个错误。
 */
final class UnavailableFanVerifyVerifier implements FanVerifyVerifier
{
    /** @var string */
    private $reason;

    public function __construct($reason = 'FanVerify 验证接口尚未接入')
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
        throw ApiException::notImplemented(
            $this->reason . '。请在 .env 中配置 FANVERIFY_API_URL / FANVERIFY_API_TOKEN（见 passport/README.md）'
        );
    }
}
