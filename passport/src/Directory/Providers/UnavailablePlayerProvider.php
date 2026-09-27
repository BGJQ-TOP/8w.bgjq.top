<?php

namespace W8\Passport\Directory\Providers;

use W8\Passport\Contracts\PlayerProvider;
use W8\Passport\Http\ApiException;

/**
 * 未配置的玩家数据源（Null Object）
 *
 * 存在的意义：宁可明确报"接口未接入"，也绝不放行任何未经权威校验的注册。
 * 一旦 .env 配好 PLAYER_API_BASE，Application 会自动换成 HttpPlayerProvider。
 */
final class UnavailablePlayerProvider implements PlayerProvider
{
    /** @var string */
    private $reason;

    public function __construct($reason = '玩家信息接口尚未接入')
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

    public function findByName($playerName)
    {
        throw ApiException::notImplemented(
            $this->reason . '。请在 .env 中配置 PLAYER_API_BASE / PLAYER_API_PATH（见 passport/README.md）'
        );
    }
}
