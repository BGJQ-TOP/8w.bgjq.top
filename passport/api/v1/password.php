<?php
/**
 * POST /passport/api/v1/password
 *
 * 修改当前通行证密码。改密后其它设备上的会话会被全部踢下线。
 *
 * 请求体：{"old_password":"...","new_password":"..."}
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    if (!$request->isPost()) {
        throw ApiException::validation('请使用 POST 请求');
    }

    $account = $app->authenticator()->requireCurrent($request);
    $app->markApiContext(null, $account->id());

    $app->authenticator()->changePassword(
        $account,
        (string) $request->input('old_password', ''),
        (string) $request->input('new_password', ''),
        $request
    );

    return Response::ok(array('changed' => true));
});
