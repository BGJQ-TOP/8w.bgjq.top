<?php
/**
 * GET /passport/api/v1/me
 *
 * 当前登录的通行证信息（含绑定的游戏内玩家与邦国缓存）。
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    $account = $app->authenticator()->requireCurrent($request);
    $app->markApiContext(null, $account->id());

    $data = array('account' => $account->toPublicArray());

    // 玩家信息（权威缓存）
    $player = $app->players()->findCached($account->playerName());
    if ($player !== null) {
        $data['player'] = $player->toArray();
    }

    // 邦国信息（权威缓存，不回源，避免每次打开个人页都打第三方接口）
    if ($account->countryId() !== null) {
        $country = $app->countries()->findCached($account->countryId());
        if ($country !== null) {
            $data['country'] = $country->toArray();
        }
    }

    return Response::ok($data);
});
