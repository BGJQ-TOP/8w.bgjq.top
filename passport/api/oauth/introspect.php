<?php
/**
 * POST /oauth/introspect   （RFC 7662）
 *
 * 供第三方服务端校验令牌是否有效。需以机密客户端身份认证。
 * 请求体：token=<令牌>&token_type_hint=access_token|refresh_token
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    if (!$request->isPost()) {
        throw ApiException::oauth('invalid_request', '内省端点必须使用 POST 请求');
    }

    $payload = $app->oauth()->introspect($request);

    return new Response(200, $payload, array('Cache-Control' => 'no-store'));
});
