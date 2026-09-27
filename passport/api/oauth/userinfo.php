<?php
/**
 * GET /oauth/userinfo
 *
 * 用访问令牌换取用户信息。返回内容按令牌的 scope 裁剪。
 * client_credentials 令牌不绑定用户，访问本端点会被拒绝。
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    $context = $app->oauth()->authenticateBearer($request);

    if ($context['account'] === null) {
        throw ApiException::oauth('invalid_token', '该访问令牌不代表任何用户，无法访问 userinfo', 403);
    }

    $app->markApiContext($context['client']['client_id'], $context['account']->id());

    return Response::ok($context['account']->toProfileArray($context['scopes']));
});
