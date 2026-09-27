<?php

namespace W8\Passport\Directory;

use W8\Passport\Contracts\CountryProvider;
use W8\Passport\Http\ApiException;
use W8\Passport\Support\Config;
use W8\Passport\Support\Database;
use W8\Passport\Support\Logger;

/**
 * 邦国目录 —— 权威数据 + 本地缓存
 *
 * countries 表同时承载权威缓存（name / declaration / territory_chunks / population）
 * 与站内补充字段（government_type / flag_url / is_active）。
 * 同步权威数据时**只覆盖权威字段**，绝不动站内补充字段。
 */
final class CountryDirectory
{
    /** @var Database */
    private $db;

    /** @var CountryProvider */
    private $provider;

    /** @var PlayerDirectory */
    private $players;

    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    public function __construct(Database $db, CountryProvider $provider, PlayerDirectory $players, Config $config, Logger $logger)
    {
        $this->db = $db;
        $this->provider = $provider;
        $this->players = $players;
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
     * 按邦国ID查询
     *
     * @param int $countryId
     * @param bool $fresh
     * @return CountryProfile|null
     */
    public function find($countryId, $fresh = false)
    {
        $countryId = (int) $countryId;
        if ($countryId <= 0) {
            return null;
        }

        $row = $this->cachedRow($countryId);
        if (!$fresh && $row !== null && !$this->rowIsStale($row)) {
            return $this->hydrate($row);
        }

        if (!$this->provider->isConfigured()) {
            if ($row !== null) {
                return $this->hydrate($row);
            }
            throw ApiException::notImplemented(
                '邦国信息接口尚未接入，无法查询邦国。请在 .env 中配置 COUNTRY_API_BASE / COUNTRY_API_PATH'
            );
        }

        try {
            $profile = $this->provider->findById($countryId);
        } catch (ApiException $e) {
            if ($row !== null) {
                $this->logger->warning('country_directory.degraded', array(
                    'country_id' => $countryId, 'reason' => $e->getMessage(),
                ));
                return $this->hydrate($row);
            }
            throw $e;
        }

        if ($profile === null) {
            return null;
        }

        $this->sync($profile);
        return $this->find($countryId, false);
    }

    /**
     * 按邦国名称查询
     *
     * @param string $countryName
     * @param bool $fresh
     * @return CountryProfile|null
     */
    public function findByName($countryName, $fresh = false)
    {
        $countryName = trim((string) $countryName);
        if ($countryName === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT * FROM `countries` WHERE `name` = ? LIMIT 1',
            array($countryName)
        );

        if (!$fresh && $row !== null && !$this->rowIsStale($row)) {
            return $this->hydrate($row);
        }

        if (!$this->provider->isConfigured()) {
            if ($row !== null) {
                return $this->hydrate($row);
            }
            throw ApiException::notImplemented(
                '邦国信息接口尚未接入，无法按名称查询邦国。请在 .env 中配置 COUNTRY_API_BASE / COUNTRY_API_PATH_BY_NAME'
            );
        }

        $profile = $this->provider->findByName($countryName);
        if ($profile === null) {
            return null;
        }

        $this->sync($profile);
        return $this->find($profile->id(), false);
    }

    /**
     * 读取本地缓存（绝不回源）
     *
     * @param int $countryId
     * @return CountryProfile|null
     */
    public function findCached($countryId)
    {
        $row = $this->cachedRow((int) $countryId);
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * 把权威结果写入缓存
     *
     * 覆盖范围：邦国名称、宣言、领土大小、人口、玩家列表。
     * 保留范围：government_type / flag_url / is_active 等站内补充字段。
     */
    public function sync(CountryProfile $profile)
    {
        $this->db->transaction(function () use ($profile) {
            $this->db->execute(
                'INSERT INTO `countries`
                    (`id`, `name`, `declaration`, `territory_chunks`, `population`, `synced_at`)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    `name`             = VALUES(`name`),
                    `declaration`      = VALUES(`declaration`),
                    `territory_chunks` = VALUES(`territory_chunks`),
                    `population`       = VALUES(`population`),
                    `synced_at`        = NOW()',
                array(
                    $profile->id(),
                    $profile->name(),
                    $profile->declaration(),
                    $profile->territoryChunks(),
                    $profile->population(),
                )
            );

            // 只有权威接口确实返回了玩家列表时才动名册缓存
            if ($profile->rosterProvided()) {
                $this->players->replaceRoster($profile->id(), $profile->players());
            }
        });

        $this->logger->debug('country_directory.synced', array(
            'country_id' => $profile->id(),
            'players' => $profile->population(),
        ));
    }

    /**
     * 确保本地有该邦国的缓存行（注册时用；权威源不可用时也不阻断注册）
     */
    public function touch($countryId, $fallbackName = null)
    {
        $countryId = (int) $countryId;
        if ($countryId <= 0) {
            return;
        }

        $this->db->execute(
            'INSERT INTO `countries` (`id`, `name`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `id` = `id`',
            array($countryId, $fallbackName !== null ? $fallbackName : ('邦国#' . $countryId))
        );
    }

    /**
     * 组装完整的邦国信息：缓存字段 + 玩家列表
     *
     * @param array<string,mixed> $row
     * @return CountryProfile
     */
    private function hydrate(array $row)
    {
        $countryId = (int) $row['id'];
        return new CountryProfile(
            $countryId,
            isset($row['name']) ? $row['name'] : '',
            isset($row['declaration']) ? $row['declaration'] : null,
            isset($row['territory_chunks']) ? $row['territory_chunks'] : null,
            $this->players->roster($countryId)
        );
    }

    /**
     * @param int $countryId
     * @return array<string,mixed>|null
     */
    private function cachedRow($countryId)
    {
        return $this->db->selectOne('SELECT * FROM `countries` WHERE `id` = ? LIMIT 1', array($countryId));
    }

    /**
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
