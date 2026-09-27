<?php

namespace W8\Passport\Directory\Providers;

use W8\Passport\Contracts\PlayerProvider;
use W8\Passport\Directory\PlayerProfile;
use W8\Passport\Http\ApiException;
use W8\Passport\Support\Arr;
use W8\Passport\Support\Config;
use W8\Passport\Support\HttpClient;
use W8\Passport\Support\Logger;

/**
 * 游戏内玩家信息 —— HTTP 权威数据源
 *
 * ============================================================================
 *  TODO：等待"新的玩家查询接口"
 * ----------------------------------------------------------------------------
 *  对接新接口时，通常**不需要改代码**，只要在 .env 里填好下面这些键：
 *
 *    PLAYER_API_BASE           接口根地址，例如 https://game.example.com
 *    PLAYER_API_PATH           路径模板，用 {player} 占位，例如 /v1/player/{player}
 *    PLAYER_API_TOKEN          可选，填了就以 Bearer 方式带上
 *    PLAYER_API_TIMEOUT        超时秒数，默认 8
 *    PLAYER_API_SUCCESS_FIELD  可选，判定"查询成功"的字段路径，值为 false 视为玩家不存在
 *    PLAYER_API_NAME_FIELD     玩家名字段路径（可写多个候选，逗号分隔）
 *    PLAYER_API_ID_FIELD       玩家ID字段路径（可写多个候选）
 *    PLAYER_API_COUNTRY_FIELD  所属邦国ID字段路径（可写多个候选）
 *
 *  若新接口的返回结构无法用点路径表达（例如需要二次请求、或列表里再筛），
 *  只需要重写下面的 mapPlayer() 一个方法。
 * ============================================================================
 */
final class HttpPlayerProvider implements PlayerProvider
{
    /** @var Config */
    private $config;

    /** @var HttpClient */
    private $http;

    /** @var Logger */
    private $logger;

    public function __construct(Config $config, HttpClient $http, Logger $logger)
    {
        $this->config = $config;
        $this->http = $http;
        $this->logger = $logger;
    }

    public function isConfigured()
    {
        return $this->config->getString('PLAYER_API_BASE') !== ''
            && $this->config->getString('PLAYER_API_PATH', '/player/{player}') !== '';
    }

    public function sourceName()
    {
        return 'http:' . $this->config->getString('PLAYER_API_BASE', '(unconfigured)');
    }

    public function findByName($playerName)
    {
        if (!$this->isConfigured()) {
            throw ApiException::notImplemented(
                '玩家信息接口尚未配置，请在 .env 中填写 PLAYER_API_BASE / PLAYER_API_PATH'
            );
        }

        $url = $this->buildUrl($playerName);
        $response = $this->http->get($url, $this->authHeaders(), $this->config->getInt('PLAYER_API_TIMEOUT', 8));

        if ($response->failed()) {
            $this->logger->warning('player_provider.transport_error', array(
                'player' => $playerName, 'error' => $response->transportError(),
            ));
            throw ApiException::serverError('玩家信息查询服务暂时不可用，请稍后重试');
        }

        if ($response->notFound()) {
            return null;
        }

        if (!$response->ok()) {
            $this->logger->warning('player_provider.bad_status', array(
                'player' => $playerName, 'status' => $response->status(),
            ));
            throw ApiException::serverError('玩家信息查询服务返回异常（HTTP ' . $response->status() . '）');
        }

        $payload = $response->json();
        if ($payload === null) {
            $this->logger->warning('player_provider.invalid_json', array(
                'player' => $playerName, 'body' => substr($response->body(), 0, 300),
            ));
            throw ApiException::serverError('玩家信息查询服务返回了无法解析的数据');
        }

        return $this->mapPlayer($payload, $playerName);
    }

    /**
     * 把权威接口的响应映射成 PlayerProfile
     *
     * ⚠ TODO：新接口对接点。默认走 .env 里配置的字段路径。
     *
     * @param array<string,mixed> $payload
     * @param string $playerName 查询时使用的玩家名
     * @return PlayerProfile|null null 表示玩家不存在
     */
    private function mapPlayer(array $payload, $playerName)
    {
        // 1) 显式成功标志：为 false 直接判定"玩家不存在"
        $successPath = $this->config->getString('PLAYER_API_SUCCESS_FIELD');
        if ($successPath !== '' && Arr::get($payload, $successPath, true) === false) {
            return null;
        }

        // 2) 解析三个字段
        $name = Arr::toTextOrNull(Arr::first($payload, $this->candidates('PLAYER_API_NAME_FIELD', array(
            'data.player_name', 'data.name', 'data.username', 'player_name', 'name',
        )), null));

        $playerId = Arr::toIntOrNull(Arr::first($payload, $this->candidates('PLAYER_API_ID_FIELD', array(
            'data.player_id', 'data.id', 'player_id', 'id',
        )), null));

        $countryId = Arr::toIntOrNull(Arr::first($payload, $this->candidates('PLAYER_API_COUNTRY_FIELD', array(
            'data.country_id', 'data.faction_id', 'data.country.id', 'country_id', 'faction_id',
        )), null));

        // 3) 三个字段一个都没解析出来 —— 说明响应里根本没有这个玩家，
        //    绝不能凭查询用的名字凭空造一条记录，否则注册校验会被绕过。
        if ($name === null && $playerId === null && $countryId === null) {
            $this->logger->warning('player_provider.unmapped_response', array(
                'player' => $playerName,
                'keys' => array_keys($payload),
            ));
            return null;
        }

        return new PlayerProfile($name !== null ? $name : $playerName, $playerId, $countryId);
    }

    /**
     * @return array<int,string>
     */
    private function candidates($configKey, array $fallback)
    {
        $configured = $this->config->getString($configKey);
        if ($configured === '') {
            return $fallback;
        }
        $paths = array();
        foreach (explode(',', $configured) as $path) {
            $path = trim($path);
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        return $paths ? array_merge($paths, $fallback) : $fallback;
    }

    private function buildUrl($playerName)
    {
        $base = rtrim($this->config->getString('PLAYER_API_BASE'), '/');
        $path = $this->config->getString('PLAYER_API_PATH', '/player/{player}');
        $path = str_replace(
            array('{player}', '{player_name}'),
            rawurlencode($playerName),
            $path
        );
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders()
    {
        $token = $this->config->getString('PLAYER_API_TOKEN');
        return $token === '' ? array() : array('Authorization' => 'Bearer ' . $token);
    }
}
