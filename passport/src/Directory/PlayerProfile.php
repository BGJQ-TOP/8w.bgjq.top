<?php

namespace W8\Passport\Directory;

use W8\Passport\Support\Arr;

/**
 * 游戏内玩家 —— 权威数据模型
 *
 * 按规格，只保留三个字段：
 *   · 玩家名        （权威主键，唯一可信的检索键）
 *   · 玩家ID        （权威缓存）
 *   · 玩家所属邦国ID（权威缓存）
 *
 * 不可变值对象，构造即定型。
 */
final class PlayerProfile
{
    /** @var string */
    private $name;

    /** @var int|null */
    private $id;

    /** @var int|null */
    private $countryId;

    /**
     * @param string $name
     * @param int|null $id
     * @param int|null $countryId
     */
    public function __construct($name, $id = null, $countryId = null)
    {
        $this->name = (string) $name;
        $this->id = Arr::toIntOrNull($id);
        $this->countryId = Arr::toIntOrNull($countryId);
    }

    public function name()
    {
        return $this->name;
    }

    public function id()
    {
        return $this->id;
    }

    public function countryId()
    {
        return $this->countryId;
    }

    public function hasCountry()
    {
        return $this->countryId !== null && $this->countryId > 0;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray()
    {
        return array(
            'player_name' => $this->name,
            'player_id'   => $this->id,
            'country_id'  => $this->countryId,
        );
    }

    /**
     * @param array<string,mixed> $row 数据库行
     * @return PlayerProfile
     */
    public static function fromRow(array $row)
    {
        return new self(
            isset($row['player_name']) ? $row['player_name'] : '',
            isset($row['player_id']) ? $row['player_id'] : null,
            isset($row['country_id']) ? $row['country_id'] : null
        );
    }
}
