<?php

namespace W8\Passport\Contracts;

use W8\Passport\Verification\SimpassIdentity;

/**
 * 简幻通身份验证方
 *
 * ⚠ TODO：简幻通接口待对接。
 *   接口到位后实现本接口并在 Application 里替换绑定即可。
 *
 * 当前默认绑定：Verification\UnavailableSimpassVerifier
 *   —— 明确抛 not_implemented，不会静默放行未验证的身份。
 */
interface SimpassVerifier
{
    /**
     * 校验简幻通ID + 验证码，并确认该简幻通账号绑定的游戏玩家名
     *
     * @param int    $simpassUid 简幻通ID
     * @param string $verifyCode 验证码
     * @param string $playerName 待绑定的游戏内玩家名
     * @return SimpassIdentity
     *
     * @throws \W8\Passport\Http\ApiException 验证失败/接口不可用
     */
    public function verify($simpassUid, $verifyCode, $playerName);

    /**
     * @return bool
     */
    public function isConfigured();

    /**
     * @return string
     */
    public function sourceName();
}
