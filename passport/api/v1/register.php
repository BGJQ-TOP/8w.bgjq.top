<?php
/**
 * POST /passport/api/v1/register
 *
 * 8W通行证注册。必须同时提供并通过：
 *   · 验证邮箱      email
 *   · 邮箱验证码    email_code      ⚠ 邮件接口 TODO
 *   · 游戏内玩家名  player_name     ⚠ 玩家查询接口 TODO
 *   · 简幻通ID      simpass_uid     ⚠ 简幻通接口 TODO
 *   · 简幻通验证码  simpass_code
 *
 * 请求体（JSON）：
 * {
 *   "username":    "louie",
 *   "password":    "********",
 *   "email":       "louie@example.com",
 *   "email_code":  "123456",
 *   "player_name": "LouieMAIN",
 *   "simpass_uid": 10086,
 *   "simpass_code":"654321"
 * }
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    if (!$request->isPost()) {
        throw W8\Passport\Http\ApiException::validation('注册请使用 POST 请求');
    }

    $account = $app->registration()->register($request->all(), $request);

    // 注册成功直接建立登录态，省掉一次登录
    $app->sessions()->create($account->id(), $request);
    $app->accounts()->touchLogin($account->id(), $request->ip());

    return Response::ok(array(
        'account' => $account->toPublicArray(),
    ));
});
