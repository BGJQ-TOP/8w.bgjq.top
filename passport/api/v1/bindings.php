<?php
/**
 * 绑定管理
 *
 *   GET    /passport/api/v1/bindings
 *          列出当前通行证的全部绑定状态（含"该绑定能否解绑""接口是否已接入"）
 *
 *   POST   /passport/api/v1/bindings
 *          绑定。请求体：
 *            { "type": "email",     "email": "a@b.com", "code": "123456", "password": "当前密码" }
 *            { "type": "fanverify", "uid": 10086,       "code": "654321", "password": "当前密码" }
 *            { "type": "fanverify", "otp": "...",       "password": "当前密码" }   ← 扫码流程
 *
 *          扫码流程见 /passport/api/v1/fanverify-otp
 *
 *   DELETE /passport/api/v1/bindings
 *          解绑。密码放在**请求体**里，不放查询串：
 *            { "type": "email",     "password": "当前密码" }
 *            { "type": "fanverify", "password": "当前密码" }
 *
 * 安全：绑定与解绑都会改变账号的找回途径，因此都要求提供当前密码。
 * 密码一律走请求体 —— 放进 URL 会被 Web 服务器访问日志、浏览器历史与 Referer 记录下来。
 *
 * 必填绑定（游戏内玩家名、简幻通）不在此处管理，无法解绑。
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;
use W8\Passport\Identity\BindingService;

Endpoint::run(function (Request $request, Application $app) {
    $account = $app->authenticator()->requireCurrent($request);
    $app->markApiContext(null, $account->id());

    $bindings = $app->bindings();
    $method = $request->method();

    if ($method === 'POST') {
        return w8_bind($bindings, $request, $account);
    }

    if ($method === 'DELETE') {
        return w8_unbind($bindings, $request, $account);
    }

    return Response::ok(array(
        'bindings' => $bindings->describe($account),
        'account'  => $account->toPublicArray(),
    ));
});

/**
 * @return Response
 */
function w8_bind(BindingService $bindings, Request $request, $account)
{
    $type = strtolower($request->string('type'));
    $password = (string) $request->input('password', '');
    $code = $request->string('code', $request->string('verify_code'));

    if ($type === BindingService::TYPE_EMAIL) {
        $updated = $bindings->bindEmail(
            $account,
            $request->string('email'),
            $code,
            $password
        );
    } elseif ($type === BindingService::TYPE_FANVERIFY) {
        // 两条路径：扫码（带 otp）或手填（带 uid + code）
        $otp = $request->string('otp');

        if ($otp !== '') {
            $updated = $bindings->bindFanVerifyByOtp($account, $otp, $password);
        } else {
            $updated = $bindings->bindFanVerify(
                $account,
                $request->int('uid', $request->int('fanverify_uid', 0)),
                $code,
                $password
            );
        }
    } else {
        throw ApiException::validation('不支持的绑定类型，仅支持 email 或 fanverify', array('field' => 'type'));
    }

    return Response::ok(array(
        'bound'    => $type,
        'bindings' => $bindings->describe($updated),
        'account'  => $updated->toPublicArray(),
    ));
}

/**
 * @return Response
 */
function w8_unbind(BindingService $bindings, Request $request, $account)
{
    // type 无敏感性，允许放查询串；password 只从请求体读
    $type = strtolower($request->string('type', (string) $request->query('type', '')));
    $password = (string) $request->input('password', '');

    if ($type === BindingService::TYPE_EMAIL) {
        $updated = $bindings->unbindEmail($account, $password);
    } elseif ($type === BindingService::TYPE_FANVERIFY) {
        $updated = $bindings->unbindFanVerify($account, $password);
    } else {
        throw ApiException::validation('不支持的绑定类型，仅支持 email 或 fanverify', array('field' => 'type'));
    }

    return Response::ok(array(
        'unbound'  => $type,
        'bindings' => $bindings->describe($updated),
        'account'  => $updated->toPublicArray(),
    ));
}
