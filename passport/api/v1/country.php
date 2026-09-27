<?php
/**
 * GET /passport/api/v1/country?id=<邦国ID>[&fresh=1]
 * GET /passport/api/v1/country?name=<邦国名称>[&fresh=1]
 *
 * 查询邦国（权威第三方数据 + 本地缓存），返回字段：
 *   邦国ID、邦国名称、邦国宣言、邦国领土大小、邦国玩家列表
 *
 * 鉴权：Bearer（directory scope）或通行证会话 Cookie
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

    $countryId = $request->int('id', $request->int('country_id', 0));
    $countryName = $request->string('name', $request->string('country'));

    if ($countryId <= 0 && $countryName === '') {
        throw ApiException::validation('请提供 id（邦国ID）或 name（邦国名称）');
    }

    $fresh = $request->bool('fresh', false);

    $country = $countryId > 0
        ? $app->countries()->find($countryId, $fresh)
        : $app->countries()->findByName($countryName, $fresh);

    if ($country === null) {
        throw ApiException::notFound('未找到对应的邦国');
    }

    return Response::ok(array('country' => $country->toArray()));
});
