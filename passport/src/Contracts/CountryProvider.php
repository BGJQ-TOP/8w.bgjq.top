<?php

namespace W8\Passport\Contracts;

use W8\Passport\Directory\CountryProfile;

/**
 * 邦国信息提供方（权威数据源）
 *
 * ⚠ 同样是为后续"新的邦国查询接口"预留的接入点。
 *
 * 现成实现：Directory\Providers\HttpCountryProvider
 * 未配置时：Directory\Providers\UnavailableCountryProvider
 */
interface CountryProvider
{
    /**
     * 按邦国ID查询邦国信息（含邦国玩家列表）
     *
     * @param int $countryId
     * @return CountryProfile|null 邦国不存在时返回 null
     *
     * @throws \W8\Passport\Http\ApiException
     */
    public function findById($countryId);

    /**
     * 按邦国名称查询邦国信息
     *
     * @param string $countryName
     * @return CountryProfile|null
     *
     * @throws \W8\Passport\Http\ApiException
     */
    public function findByName($countryName);

    /**
     * @return bool
     */
    public function isConfigured();

    /**
     * @return string
     */
    public function sourceName();
}
