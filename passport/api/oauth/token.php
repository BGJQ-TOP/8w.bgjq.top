<?php
/**
 * POST /oauth/token
 *
 * OAuth 2.0 令牌端点。支持三种 grant_type：
 *
 *   authorization_code  授权码换令牌（公开客户端必须带 code_verifier，PKCE）
 *   refresh_token       刷新令牌（轮换式，旧令牌立即失效）
 *   client_credentials  机器对机器（仅 directory scope）
 *
 * 客户端认证支持 client_secret_basic 与 client_secret_post 两种方式。
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    if (!$request->isPost()) {
        throw ApiException::oauth('invalid_request', '令牌端点必须使用 POST 请求');
    }

    $payload = $app->oauth()->issueToken($request);

    return new Response(200, $payload, array(
        'Cache-Control' => 'no-store',
        'Pragma'        => 'no-cache',
    ));
});
