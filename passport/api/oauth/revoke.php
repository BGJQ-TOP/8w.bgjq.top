<?php
/**
 * POST /oauth/revoke   （RFC 7009）
 *
 * 吊销访问令牌或刷新令牌。按规范，无论令牌是否存在都返回 200。
 * 请求体：token=<令牌>
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    if (!$request->isPost()) {
        throw ApiException::oauth('invalid_request', '吊销端点必须使用 POST 请求');
    }

    $app->oauth()->revoke($request);

    return new Response(200, array(), array('Cache-Control' => 'no-store'));
});
