<?php

namespace W8\Passport\Verification;

use W8\Passport\Contracts\FanVerifyVerifier;
use W8\Passport\Http\ApiException;
use W8\Passport\Support\Config;

/**
 * FanVerify 验证 —— 已接入实现
 *
 * 对接的是 FanVerify openAPI 的「用户验证接口」：
 *   GET https://api.fanverify.cn/openapi/user_verify?accesstoken=&uid=&pass_code=
 *
 * 也就是用户在自己的 FanVerify 微信小程序里拿到动态验证码，连同账号ID一起填到我们这边。
 * 另有一条更省事的「扫码流程」（申请 OTP → 出二维码 → 轮询），
 * 由 FanVerifyClient 直接支撑，见 passport/api/v1/fanverify-otp.php。
 *
 * 本类只做"校验并返回身份"，不负责写库；绑定逻辑在 Identity\BindingService。
 */
final class HttpFanVerifyVerifier implements FanVerifyVerifier
{
    /** @var FanVerifyClient */
    private $client;

    /** @var Config */
    private $config;

    public function __construct(Config $config, FanVerifyClient $client)
    {
        $this->config = $config;
        $this->client = $client;
    }

    public function client()
    {
        return $this->client;
    }

    public function isConfigured()
    {
        return $this->client->isConfigured();
    }

    public function sourceName()
    {
        return 'fanverify:' . $this->client->baseUrl();
    }

    /**
     * 校验 FanVerify 账号ID + 动态验证码
     *
     * @param int    $fanverifyUid
     * @param string $verifyCode   小程序里的动态验证码（pass_code）
     * @param string $playerName   当前通行证绑定的游戏内玩家名
     *                             —— FanVerify 不返回"绑定的游戏名"，所以这里只用于日志，
     *                                不做交叉校验（简幻通那边才做）
     * @return FanVerifyIdentity
     */
    public function verify($fanverifyUid, $verifyCode, $playerName)
    {
        if (!$this->isConfigured()) {
            throw ApiException::notImplemented(
                'FanVerify 尚未配置，请在 .env 中填写 FANVERIFY_ACCESS_TOKEN'
            );
        }

        $verifyCode = trim((string) $verifyCode);
        if ($verifyCode === '') {
            throw ApiException::validation('请填写 FanVerify 动态验证码', array('field' => 'fanverify_code'));
        }

        return $this->client->verifyUser($fanverifyUid, $verifyCode);
    }

    public function requestOtp()
    {
        return $this->client->requestOtp();
    }

    public function qrCodePng($otp)
    {
        return $this->client->qrCodePng($otp);
    }

    public function pollOtp($otp)
    {
        return $this->client->pollOtp($otp);
    }

    /**
     * 开发者令牌自检 —— 给后台「接口接入状态」卡片与 bin/fanverify-check.php 用
     *
     * @return array<string,mixed>
     */
    public function developerInfo()
    {
        return $this->client->developerInfo();
    }

    /**
     * 该令牌要求的最低等级（0 表示不限）
     *
     * @return int
     */
    public function requiredLevel()
    {
        $configured = $this->config->getInt('FANVERIFY_REQUIRED_LEVEL', 0);
        return max(0, $configured);
    }
}
