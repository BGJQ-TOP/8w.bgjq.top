<?php
/**
 * POST /passport/api/v1/logout
 *
 * 注销当前通行证会话。
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;

Endpoint::run(function (Request $request, Application $app) {
    $account = $app->authenticator()->current($request);
    if ($account !== null) {
        $app->markApiContext(null, $account->id());
    }

    $app->authenticator()->logout($request);

    return Response::ok(array('logged_out' => true));
});
