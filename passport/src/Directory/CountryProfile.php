<?php

namespace W8\Passport\Directory;

use W8\Passport\Support\Arr;

/**
 * 邦国 —— 权威数据模型
 *
 * 按规格，字段为：
 *   · 邦国ID
 *   · 邦国名称
 *   · 邦国玩家列表   （PlayerProfile 列表）
 *   · 邦国宣言
 *   · 邦国领土大小
 *
 * 其中只有"邦国ID"是权威主键，其余全部来自权威接口，本地只是缓存。
 */
final class CountryProfile
{
    /** @var int */
    private $id;

    /** @var string */
    private $name;

    /** @var string|null */
    private $declaration;

    /** @var int|null */
    private $territoryChunks;

    /** @var array<int,PlayerProfile> */
    private $players;

    /** @var bool 权威接口是否真的返回了玩家列表 */
    private $rosterProvided;

    /**
     * @param int $id
     * @param string $name
     * @param string|null $declaration
     * @param int|null $territoryChunks
     * @param array<int,PlayerProfile> $players
     * @param bool $rosterProvided 列表缺省时不要清空本地缓存，故需显式区分
     */
    public function __construct($id, $name, $declaration = null, $territoryChunks = null, array $players = array(), $rosterProvided = true)
    {
        $this->id = (int) $id;
        $this->name = (string) $name;
        $this->declaration = Arr::toTextOrNull($declaration);
        $this->territoryChunks = Arr::toIntOrNull($territoryChunks);
        $this->players = array_values($players);
        $this->rosterProvided = (bool) $rosterProvided;
    }

    /**
     * 权威接口是否提供了玩家列表
     *
     * 为空列表和"没返回"是两回事：前者表示邦国真的没人，后者表示本次不该动缓存。
     */
    public function rosterProvided()
    {
        return $this->rosterProvided;
    }

    public function id()
    {
        return $this->id;
    }

    public function name()
    {
        return $this->name;
    }

    public function declaration()
    {
        return $this->declaration;
    }

    public function territoryChunks()
    {
        return $this->territoryChunks;
    }

    /**
     * 邦国玩家列表
     *
     * @return array<int,PlayerProfile>
     */
    public function players()
    {
        return $this->players;
    }

    /**
     * 人口 = 玩家列表长度（权威缓存派生值）
     */
    public function population()
    {
        return count($this->players);
    }

    /**
     * @return array<int,string>
     */
    public function playerNames()
    {
        $names = array();
        foreach ($this->players as $player) {
            $names[] = $player->name();
        }
        return $names;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray()
    {
        $players = array();
        foreach ($this->players as $player) {
            $players[] = $player->toArray();
        }

        return array(
            'id'               => $this->id,
            'name'             => $this->name,
            'declaration'      => $this->declaration,
            'territory_chunks' => $this->territoryChunks,
            'population'       => $this->population(),
            'players'          => $players,
        );
    }
}
