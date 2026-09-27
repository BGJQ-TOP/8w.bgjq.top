<?php

namespace W8\Passport\Verification;

use W8\Passport\Contracts\SimpassVerifier;
use W8\Passport\Http\ApiException;

/**
 * 简幻通验证 —— 未接入（Null Object）
 *
 * ⚠ TODO：简幻通接口待对接。
 *   把接口地址填进 .env 的 SIMPASS_API_URL，Application 会自动改用 HttpSimpassVerifier。
 */
final class UnavailableSimpassVerifier implements SimpassVerifier
{
    /** @var string */
    private $reason;

    public function __construct($reason = '简幻通验证接口尚未接入')
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

    public function verify($simpassUid, $verifyCode, $playerName)
    {
        throw ApiException::notImplemented(
            $this->reason . '。请在 .env 中配置 SIMPASS_API_URL / SIMPPASS_ACCESS_TOKEN（见 passport/README.md）'
        );
    }
}
