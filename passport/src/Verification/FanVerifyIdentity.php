<?php

namespace W8\Passport\Verification;

/**
 * FanVerify 身份校验结果
 *
 * 与 SimpassIdentity 同构：一个外部验证平台返回的"这个人是谁"。
 */
final class FanVerifyIdentity
{
    /** @var int */
    private $uid;

    /** @var string|null FanVerify 侧绑定的游戏内玩家名（返回时可用于交叉校验） */
    private $playerName;

    public function __construct($uid, $playerName = null)
    {
        $this->uid = (int) $uid;
        $this->playerName = $playerName === null ? null : trim((string) $playerName);
    }

    public function uid()
    {
        return $this->uid;
    }

    public function playerName()
    {
        return $this->playerName !== '' ? $this->playerName : null;
    }
}
