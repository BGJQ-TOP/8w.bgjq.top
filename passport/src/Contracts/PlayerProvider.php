<?php

namespace W8\Passport\Contracts;

use W8\Passport\Directory\PlayerProfile;

/**
 * 游戏内玩家信息提供方（权威数据源）
 *
 * ⚠ 这是给"新的玩家查询接口"预留的接入点。
 *   接口到位后只需要实现本接口，并在 Application 里换掉绑定即可，
 *   上层（注册、登录、缓存、第三方 API）完全不用改。
 *
 * 现成实现：Directory\Providers\HttpPlayerProvider（配置驱动 + TODO 字段映射）
 * 未配置时：Directory\Providers\UnavailablePlayerProvider（明确报错，不静默放行）
 */
interface PlayerProvider
{
    /**
     * 按玩家名查询玩家信息
     *
     * @param string $playerName 游戏内玩家名
     * @return PlayerProfile|null 玩家不存在时返回 null
     *
     * @throws \W8\Passport\Http\ApiException 接口不可用/超时/响应异常
     */
    public function findByName($playerName);

    /**
     * 是否已配置好（未配置时注册流程会直接拒绝，而不是放行假数据）
     *
     * @return bool
     */
    public function isConfigured();

    /**
     * 数据源标识，用于日志与对外说明
     *
     * @return string
     */
    public function sourceName();
}
