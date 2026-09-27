<?php

namespace W8\Passport\Directory;

use W8\Passport\Contracts\PlayerProvider;
use W8\Passport\Http\ApiException;
use W8\Passport\Support\Config;
use W8\Passport\Support\Database;
use W8\Passport\Support\Logger;

/**
 * 玩家目录 —— 权威数据 + 本地缓存
 *
 * 设计要点：
 *   · 权威方是第三方游戏服务，本地 players 表只是缓存，随时可被覆盖。
 *   · 读取策略：缓存未过期就用缓存；过期则回源，回源失败时降级用旧缓存
 *     （可用性优先），只有"从未缓存过且回源失败"才报错。
 *   · 写入策略：永远以权威结果为准。
 */
final class PlayerDirectory
{
    /** @var Database */
    private $db;

    /** @var PlayerProvider */
    private $provider;

    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    public function __construct(Database $db, PlayerProvider $provider, Config $config, Logger $logger)
    {
        $this->db = $db;
        $this->provider = $provider;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function provider()
    {
        return $this->provider;
    }

    public function isLive()
    {
        return $this->provider->isConfigured();
    }

    /**
     * 查询玩家
     *
     * @param string $playerName
     * @param bool $fresh 强制回源（注册校验必须用 true）
     * @param bool|null $servedFromCache 出参：结果是否来自本地缓存（含降级场景）
     * @return PlayerProfile|null
     */
    public function find($playerName, $fresh = false, &$servedFromCache = null)
    {
        $servedFromCache = false;

        $playerName = trim((string) $playerName);
        if ($playerName === '') {
            return null;
        }

        $row = $this->cachedRow($playerName);
        $cached = $row === null ? null : PlayerProfile::fromRow($row);

        if (!$fresh && $cached !== null && !$this->rowIsStale($row)) {
            $servedFromCache = true;
            return $cached;
        }

        // 权威源未接入时，有缓存就先用缓存（保证已上线功能不被配置拖垮）
        if (!$this->provider->isConfigured()) {
            if ($cached !== null) {
                $servedFromCache = true;
                return $cached;
            }
            throw ApiException::notImplemented(
                '玩家信息接口尚未接入，无法校验游戏内玩家名。请在 .env 中配置 PLAYER_API_BASE / PLAYER_API_PATH'
            );
        }

        try {
            $profile = $this->provider->findByName($playerName);
        } catch (ApiException $e) {
            if ($cached !== null) {
                $this->logger->warning('player_directory.degraded', array(
                    'player' => $playerName, 'reason' => $e->getMessage(),
                ));
                $servedFromCache = true;
                return $cached;
            }
            throw $e;
        }

        if ($profile === null) {
            // 权威方明确说"没有这个人"，顺手清掉可能过期的缓存
            if ($cached !== null) {
                $this->forget($playerName);
            }
            return null;
        }

        $this->remember($profile);
        return $profile;
    }

    /**
     * @param string $playerName
     * @return PlayerProfile|null
     */
    public function findCached($playerName)
    {
        $row = $this->cachedRow($playerName);
        return $row === null ? null : PlayerProfile::fromRow($row);
    }

    /**
     * @param string $playerName
     * @return array<string,mixed>|null
     */
    private function cachedRow($playerName)
    {
        return $this->db->selectOne(
            'SELECT `player_name`, `player_id`, `country_id`, `synced_at` FROM `players` WHERE `player_name` = ? LIMIT 1',
            array($playerName)
        );
    }

    /**
     * 写入/更新缓存
     */
    public function remember(PlayerProfile $profile)
    {
        if ($profile->name() === '') {
            return;
        }

        $this->db->execute(
            'INSERT INTO `players` (`player_name`, `player_id`, `country_id`, `synced_at`)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                `player_id`  = VALUES(`player_id`),
                `country_id` = VALUES(`country_id`),
                `synced_at`  = NOW()',
            array($profile->name(), $profile->id(), $profile->countryId())
        );
    }

    /**
     * 邦国玩家列表（读缓存）
     *
     * @param int $countryId
     * @return array<int,PlayerProfile>
     */
    public function roster($countryId)
    {
        $rows = $this->db->select(
            'SELECT player_name, player_id, country_id FROM `players` WHERE `country_id` = ? ORDER BY `player_name`',
            array((int) $countryId)
        );

        $players = array();
        foreach ($rows as $row) {
            $players[] = PlayerProfile::fromRow($row);
        }
        return $players;
    }

    /**
     * 用权威列表覆盖某邦国的玩家列表（在 CountryDirectory 的事务里调用）
     *
     * @param int $countryId
     * @param array<int,PlayerProfile> $players
     */
    public function replaceRoster($countryId, array $players)
    {
        $countryId = (int) $countryId;
        $names = array();
        foreach ($players as $player) {
            if ($player->name() !== '') {
                $names[] = $player->name();
            }
        }

        // 1) 已不在名册里的玩家，解除与本邦国的关联
        if ($names === array()) {
            $this->db->execute(
                'UPDATE `players` SET `country_id` = NULL, `synced_at` = NOW() WHERE `country_id` = ?',
                array($countryId)
            );
        } else {
            $placeholders = implode(', ', array_fill(0, count($names), '?'));
            $params = array_merge(array($countryId), $names);
            $this->db->execute(
                "UPDATE `players` SET `country_id` = NULL, `synced_at` = NOW()
                 WHERE `country_id` = ? AND `player_name` NOT IN ({$placeholders})",
                $params
            );
        }

        // 2) 名册内玩家 upsert
        foreach ($players as $player) {
            $this->remember($player);
        }
    }

    public function forget($playerName)
    {
        $this->db->execute('DELETE FROM `players` WHERE `player_name` = ?', array($playerName));
    }

    /**
     * 缓存是否已过有效期
     *
     * @param array<string,mixed> $row
     * @return bool
     */
    private function rowIsStale(array $row)
    {
        $ttl = $this->config->getInt('DIRECTORY_CACHE_TTL', 600);
        if ($ttl <= 0) {
            return false;
        }

        $syncedAt = isset($row['synced_at']) ? strtotime((string) $row['synced_at']) : false;
        return $syncedAt === false || (time() - $syncedAt) > $ttl;
    }
}
