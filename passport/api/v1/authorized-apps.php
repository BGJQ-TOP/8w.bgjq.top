<?php
/**
 * GET    /passport/api/v1/authorized-apps  列出当前通行证已授权的第三方应用
 * DELETE /passport/api/v1/authorized-apps?client_id=xxx  取消对某个应用的授权
 *
 * 通行证必须提供"查看并撤销第三方授权"的能力，这是与市面主流通行证对齐的基本要求。
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;
use W8\Passport\OAuth\Scope;

Endpoint::run(function (Request $request, Application $app) {
    $account = $app->authenticator()->requireCurrent($request);
    $app->markApiContext(null, $account->id());

    $tokens = $app->oauthTokens();

    if ($request->method() === 'DELETE') {
        $clientId = $request->string('client_id', (string) $request->query('client_id', ''));
        if ($clientId === '') {
            throw ApiException::validation('缺少参数 client_id');
        }

        $tokens->revokeAll($clientId, $account->id());

        return Response::ok(array('revoked' => true, 'client_id' => $clientId));
    }

    $apps = array();
    foreach ($tokens->authorizedApps($account->id()) as $row) {
        $apps[] = array(
            'client_id'   => $row['client_id'],
            'name'        => $row['name'] !== null ? $row['name'] : $row['client_id'],
            'logo_url'    => $row['logo_url'],
            'homepage_url' => $row['homepage_url'],
            'scopes'      => Scope::parse($row['scopes']),
            'authorized_at' => $row['created_at'],
            'last_used_at'  => $row['last_used_at'],
        );
    }

    return Response::ok(array('apps' => $apps));
});
