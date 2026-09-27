<?php

namespace W8\Passport\Directory\Providers;

use W8\Passport\Contracts\CountryProvider;
use W8\Passport\Http\ApiException;

/**
 * 未配置的邦国数据源（Null Object）
 */
final class UnavailableCountryProvider implements CountryProvider
{
    /** @var string */
    private $reason;

    public function __construct($reason = '邦国信息接口尚未接入')
    {
        $this->reason = (string) $reason;
    }

    public function isConfigured()
    {
        return false;
    }

    public function sourceName()
    {
        return 'unavailable';
    }

    public function findById($countryId)
    {
        throw ApiException::notImplemented(
            $this->reason . '。请在 .env 中配置 COUNTRY_API_BASE / COUNTRY_API_PATH（见 passport/README.md）'
        );
    }

    public function findByName($countryName)
    {
        throw ApiException::notImplemented(
            $this->reason . '。请在 .env 中配置 COUNTRY_API_BASE / COUNTRY_API_PATH_BY_NAME（见 passport/README.md）'
        );
    }
}
