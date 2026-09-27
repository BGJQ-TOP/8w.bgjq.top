<?php

namespace W8\Passport\Contracts;

use W8\Passport\Verification\FanVerifyIdentity;

/**
 * FanVerify 账号验证方
 *
 * ⚠ TODO：FanVerify 接口待对接。
 *   接口到位后实现本接口并在 Application 里替换绑定即可，
 *   注册流程与绑定流程都不用改。
 *
 * 当前默认绑定：Verification\UnavailableFanVerifyVerifier
 *   —— 明确抛 not_implemented，不会静默放行未验证的身份。
 *
 * 定位：FanVerify 是**可选绑定**，和验证邮箱同级。
 *   未绑定不影响注册与登录；绑定后多一条身份凭据，
 *   第三方也可以通过 fanverify scope 读到它。
 */
interface FanVerifyVerifier
{
    /**
     * 校验 FanVerify 账号ID + 验证码，并确认它绑定的游戏玩家名
     *
     * @param int    $fanverifyUid FanVerify 账号ID
     * @param string $verifyCode   验证码
     * @param string $playerName   当前通行证绑定的游戏内玩家名（用于交叉校验）
     * @return FanVerifyIdentity
     *
     * @throws \W8\Passport\Http\ApiException 验证失败/接口不可用
     */
    public function verify($fanverifyUid, $verifyCode, $playerName);

    /**
     * @return bool
     */
    public function isConfigured();

    /**
     * @return string
     */
    public function sourceName();
}
