<?php

namespace W8\Passport\Verification;

/**
 * 简幻通身份校验结果
 */
final class SimpassIdentity
{
    /** @var int */
    private $uid;

    /** @var int|null */
    private $level;

    /** @var string|null 简幻通侧绑定的游戏内玩家名（权威方返回时可用于交叉校验） */
    private $playerName;

    public function __construct($uid, $level = null, $playerName = null)
    {
        $this->uid = (int) $uid;
        $this->level = $level === null ? null : (int) $level;
        $this->playerName = $playerName === null ? null : trim((string) $playerName);
    }

    public function uid()
    {
        return $this->uid;
    }

    public function level()
    {
        return $this->level;
    }

    public function playerName()
    {
        return $this->playerName !== '' ? $this->playerName : null;
    }
}
