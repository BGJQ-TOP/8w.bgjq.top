<?php

namespace W8\Passport\Directory\Providers;

use W8\Passport\Contracts\CountryProvider;
use W8\Passport\Directory\CountryProfile;
use W8\Passport\Directory\PlayerProfile;
use W8\Passport\Http\ApiException;
use W8\Passport\Support\Arr;
use W8\Passport\Support\Config;
use W8\Passport\Support\HttpClient;
use W8\Passport\Support\Logger;

/**
 * 邦国信息 —— HTTP 权威数据源
 *
 * ============================================================================
 *  TODO：等待"新的邦国查询接口"
 * ----------------------------------------------------------------------------
 *  对接方式与 HttpPlayerProvider 一致，全部通过 .env 配置：
 *
 *    COUNTRY_API_BASE              接口根地址
 *    COUNTRY_API_PATH              按ID查询的路径模板，{country_id} 占位
 *    COUNTRY_API_PATH_BY_NAME      按名称查询的路径模板，{country} 占位（可选）
 *    COUNTRY_API_TOKEN             可选 Bearer
 *    COUNTRY_API_TIMEOUT           超时秒数，默认 8
 *    COUNTRY_API_SUCCESS_FIELD     可选，成功标志字段路径
 *    COUNTRY_API_ID_FIELD          邦国ID字段路径（可多个候选，逗号分隔）
 *    COUNTRY_API_NAME_FIELD        邦国名称字段路径（可多个候选，逗号分隔）
 *    COUNTRY_API_DECLARATION_FIELD 邦国宣言字段路径
 *    COUNTRY_API_TERRITORY_FIELD   邦国领土大小字段路径
 *    COUNTRY_API_PLAYERS_FIELD     邦国玩家列表字段路径
 *    COUNTRY_API_PLAYER_NAME_FIELD 列表元素的玩家名字段路径（相对元素）
 *    COUNTRY_API_PLAYER_ID_FIELD   列表元素的玩家ID字段路径（相对元素）
 *
 *  返回结构表达不了时，重写 mapCountry() / mapPlayers() 即可。
 * ============================================================================
 */
final class HttpCountryProvider implements CountryProvider
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
        return $this->config->getString('COUNTRY_API_BASE') !== ''
            && $this->config->getString('COUNTRY_API_PATH', '/country/{country_id}') !== '';
    }

    public function sourceName()
    {
        return 'http:' . $this->config->getString('COUNTRY_API_BASE', '(unconfigured)');
    }

    public function findById($countryId)
    {
        if (!$this->isConfigured()) {
            throw ApiException::notImplemented(
                '邦国信息接口尚未配置，请在 .env 中填写 COUNTRY_API_BASE / COUNTRY_API_PATH'
            );
        }

        $base = rtrim($this->config->getString('COUNTRY_API_BASE'), '/');
        $path = str_replace(
            array('{country_id}', '{country}'),
            rawurlencode((string) $countryId),
            $this->config->getString('COUNTRY_API_PATH', '/country/{country_id}')
        );

        return $this->fetch($base . '/' . ltrim($path, '/'), array('country_id' => $countryId));
    }

    public function findByName($countryName)
    {
        if (!$this->isConfigured()) {
            throw ApiException::notImplemented(
                '邦国信息接口尚未配置，请在 .env 中填写 COUNTRY_API_BASE / COUNTRY_API_PATH_BY_NAME'
            );
        }

        $template = $this->config->getString('COUNTRY_API_PATH_BY_NAME');
        if ($template === '') {
            // 没配按名称查询时，不猜、不乱扫全表，明确报错
            throw ApiException::notImplemented(
                '邦国信息接口未提供按名称查询，请使用邦国ID，或配置 COUNTRY_API_PATH_BY_NAME'
            );
        }

        $base = rtrim($this->config->getString('COUNTRY_API_BASE'), '/');
        $path = str_replace(
            array('{country}', '{country_name}'),
            rawurlencode($countryName),
            $template
        );

        return $this->fetch($base . '/' . ltrim($path, '/'), array('country' => $countryName));
    }

    /**
     * @param string $url
     * @param array<string,mixed> $context
     * @return CountryProfile|null
     */
    private function fetch($url, array $context)
    {
        $response = $this->http->get($url, $this->authHeaders(), $this->config->getInt('COUNTRY_API_TIMEOUT', 8));

        if ($response->failed()) {
            $this->logger->warning('country_provider.transport_error', $context + array('error' => $response->transportError()));
            throw ApiException::serverError('邦国信息查询服务暂时不可用，请稍后重试');
        }

        if ($response->notFound()) {
            return null;
        }

        if (!$response->ok()) {
            $this->logger->warning('country_provider.bad_status', $context + array('status' => $response->status()));
            throw ApiException::serverError('邦国信息查询服务返回异常（HTTP ' . $response->status() . '）');
        }

        $payload = $response->json();
        if ($payload === null) {
            $this->logger->warning('country_provider.invalid_json', $context + array('body' => substr($response->body(), 0, 300)));
            throw ApiException::serverError('邦国信息查询服务返回了无法解析的数据');
        }

        return $this->mapCountry($payload, $context);
    }

    /**
     * ⚠ TODO：新接口对接点。
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $context
     * @return CountryProfile|null
     */
    private function mapCountry(array $payload, array $context)
    {
        $successPath = $this->config->getString('COUNTRY_API_SUCCESS_FIELD');
        if ($successPath !== '' && Arr::get($payload, $successPath, true) === false) {
            return null;
        }

        $name = Arr::toTextOrNull(Arr::first($payload, $this->candidates('COUNTRY_API_NAME_FIELD', array(
            'data.name', 'data.country_name', 'name', 'country_name',
        )), null));

        $countryId = Arr::toIntOrNull(Arr::first($payload, $this->candidates('COUNTRY_API_ID_FIELD', array(
            'data.id', 'data.country_id', 'id', 'country_id',
        )), isset($context['country_id']) ? $context['country_id'] : null));

        // 名称都取不到，说明这条响应里没有邦国，不能凭请求参数编造
        if ($name === null || $countryId === null) {
            $this->logger->warning('country_provider.unmapped_response', $context + array('keys' => array_keys($payload)));
            return null;
        }

        $declaration = Arr::toTextOrNull(Arr::first($payload, $this->candidates('COUNTRY_API_DECLARATION_FIELD', array(
            'data.declaration', 'data.motto', 'data.description', 'declaration',
        )), null));

        $territory = Arr::toIntOrNull(Arr::first($payload, $this->candidates('COUNTRY_API_TERRITORY_FIELD', array(
            'data.territory_chunks', 'data.territory_size', 'data.territory', 'data.chunks', 'territory_chunks',
        )), null));

        return new CountryProfile(
            $countryId,
            $name,
            $declaration,
            $territory,
            $this->mapPlayers($payload, $countryId),
            is_array(Arr::get($payload, $this->config->getString('COUNTRY_API_PLAYERS_FIELD', 'data.players'), null))
        );
    }

    /**
     * 解析邦国玩家列表
     *
     * @param array<string,mixed> $payload
     * @param int $countryId
     * @return array<int,PlayerProfile>
     */
    private function mapPlayers(array $payload, $countryId)
    {
        $listPath = $this->config->getString('COUNTRY_API_PLAYERS_FIELD', 'data.players');
        $rawList = Arr::getList($payload, $listPath);

        $namePath = $this->config->getString('COUNTRY_API_PLAYER_NAME_FIELD', 'name');
        $idPath = $this->config->getString('COUNTRY_API_PLAYER_ID_FIELD', 'id');

        $players = array();
        foreach ($rawList as $raw) {
            if (is_string($raw)) {
                // 列表元素就是玩家名的情况
                $name = Arr::toTextOrNull($raw);
                if ($name !== null) {
                    $players[] = new PlayerProfile($name, null, $countryId);
                }
                continue;
            }

            if (!is_array($raw)) {
                continue;
            }

            $name = Arr::toTextOrNull(Arr::first($raw, array($namePath, 'name', 'player_name', 'username'), null));
            if ($name === null) {
                continue;
            }
            $playerId = Arr::toIntOrNull(Arr::first($raw, array($idPath, 'player_id', 'id'), null));

            $players[] = new PlayerProfile($name, $playerId, $countryId);
        }

        return $players;
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

    /**
     * @return array<string,string>
     */
    private function authHeaders()
    {
        $token = $this->config->getString('COUNTRY_API_TOKEN');
        return $token === '' ? array() : array('Authorization' => 'Bearer ' . $token);
    }
}
