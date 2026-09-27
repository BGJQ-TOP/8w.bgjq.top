<?php
/**
 * GET /passport/api/v1/player?name=<玩家名>[&fresh=1]
 *
 * 查询游戏内玩家（权威第三方数据 + 本地缓存）。
 *
 * 鉴权（二选一）：
 *   · Authorization: Bearer <access_token>  —— 需要 directory scope
 *   · 通行证会话 Cookie（站内页面）
 *
 * 返回：
 * {
 *   "ok": true,
 *   "data": {
 *     "player": { "player_name":"LouieMAIN", "player_id": 1001, "country_id": 7 },
 *     "country": { "id":7, "name":"...", "declaration":"...", "territory_chunks":123, "players":[...] },
 *     "source": "cache|authoritative"
 *   }
 * }
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../_guard.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    w8_guard($request, $app, 'directory');

    $playerName = $request->string('name', $request->string('player'));
    if ($playerName === '') {
        throw ApiException::validation('缺少参数 name（游戏内玩家名）');
    }

    // fresh=1 强制回源。默认允许，但目录层自身有 TTL 与降级保护，不会打爆第三方。
    $fresh = $request->bool('fresh', false);

    $servedFromCache = false;
    $player = $app->players()->find($playerName, $fresh, $servedFromCache);

    if ($player === null) {
        throw ApiException::notFound('游戏内不存在名为「' . $playerName . '」的玩家');
    }

    $data = array(
        'player' => $player->toArray(),
        // 如实反映数据来源：回源失败而降级用旧缓存时同样标 cache，不冒充权威结果
        'source' => $servedFromCache ? 'cache' : 'authoritative',
    );

    // 顺带把所属邦国的缓存信息带上，第三方一次请求就能拿全
    if ($player->hasCountry()) {
        try {
            $country = $app->countries()->find($player->countryId());
            if ($country !== null) {
                $data['country'] = $country->toArray();
            }
        } catch (ApiException $e) {
            // 邦国接口不可用不应让玩家查询整体失败，降级返回本地缓存
            $cachedCountry = $app->countries()->findCached($player->countryId());
            if ($cachedCountry !== null) {
                $data['country'] = $cachedCountry->toArray();
            }
            $data['country_unavailable'] = $e->getMessage();
        }
    }

    return Response::ok($data);
});
