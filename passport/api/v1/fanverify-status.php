<?php
/**
 * FanVerify 接入自检（管理员）
 *
 *   GET /passport/api/v1/fanverify-status
 *
 * 调 FanVerify 的 /openapi/devinfo 看令牌到底能不能用，返回：
 *   { configured, ok, token_hint, developer: {...} | null, error: "..." | null }
 *
 * 存在的意义：令牌无效时接口只回一句 401，运维在后台点一下就能看到
 * "是没配令牌、还是令牌被拒、还是网络不通"，不用登服务器翻日志。
 * 服务器上更完整的排查用 php bin/fanverify-check.php。
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../_guard.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    $account = $app->authenticator()->requireCurrent($request);
    $app->markApiContext(null, $account->id());
    w8_require_admin($app, $account);

    $verifier = $app->fanVerifyVerifier();
    $client = $app->fanVerifyClient();

    if (!$verifier->isConfigured()) {
        return Response::ok(array(
            'configured' => false,
            'ok'         => false,
            'error'      => 'FANVERIFY_ACCESS_TOKEN 未配置',
            'developer'  => null,
        ));
    }

    try {
        $info = $client->developerInfo();

        return Response::ok(array(
            'configured' => true,
            'ok'         => true,
            'error'      => null,
            'base_url'   => $client->baseUrl(),
            'developer'  => $info,
            // 本站门槛低于 FanVerify 要求时给个提醒
            'level_warning' => (
                $app->config()->getInt('FANVERIFY_REQUIRED_LEVEL', 0) > 0
                && $info['need_end_level'] !== null
                && $app->config()->getInt('FANVERIFY_REQUIRED_LEVEL', 0) < $info['need_end_level']
            ),
        ), array('Cache-Control' => 'no-store'));
    } catch (ApiException $e) {
        // 自检失败不是服务器错误，是"这个接口现在不可用"，所以返回 200 + ok:false
        return Response::ok(array(
            'configured' => true,
            'ok'         => false,
            'error'      => $e->getMessage(),
            'base_url'   => $client->baseUrl(),
            'developer'  => null,
        ), array('Cache-Control' => 'no-store'));
    }
});
