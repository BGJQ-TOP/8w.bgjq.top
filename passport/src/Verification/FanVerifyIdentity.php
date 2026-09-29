<?php

namespace W8\Passport\Verification;

/**
 * FanVerify 身份校验结果
 *
 * 对应 FanVerify openAPI 里所有"返回用户信息"的接口的统一结构：
 *   { status: "ok", data: [ { uid, level, reg_time, tag } ] }
 *
 * 与 SimpassIdentity 同构，只是多了风险标签与注册时间。
 */
final class FanVerifyIdentity
{
    /** @var int */
    private $uid;

    /** @var int|null */
    private $level;

    /** @var string|null 风险标签，空串按"无标签"处理 */
    private $tag;

    /** @var string|null 注册时间（RFC3339 字符串） */
    private $regTime;

    public function __construct($uid, $level = null, $tag = null, $regTime = null)
    {
        $this->uid = (int) $uid;
        $this->level = $level === null ? null : (int) $level;
        $this->tag = $tag === null ? null : trim((string) $tag);
        $this->regTime = $regTime === null ? null : trim((string) $regTime);
    }

    public function uid()
    {
        return $this->uid;
    }

    public function level()
    {
        return $this->level;
    }

    /**
     * @return string|null 无标签时为 null（FanVerify 会返回空串表示没有标签）
     */
    public function tag()
    {
        return ($this->tag === null || $this->tag === '') ? null : $this->tag;
    }

    public function regTime()
    {
        return ($this->regTime === null || $this->regTime === '') ? null : $this->regTime;
    }

    public function hasTag()
    {
        return $this->tag() !== null;
    }
}
