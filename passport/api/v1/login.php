<?php
/**
 * POST /passport/api/v1/login
 *
 * 请求体：{"identifier":"用户名或邮箱","password":"..."}
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    if (!$request->isPost()) {
        throw ApiException::validation('登录请使用 POST 请求');
    }

    $identifier = $request->string('identifier', $request->string('username'));
    $result = $app->authenticator()->login($identifier, (string) $request->input('password', ''), $request);

    $account = $result['account'];
    $app->markApiContext(null, $account->id());

    return Response::ok(array(
        'account' => $account->toPublicArray(),
    ));
});
