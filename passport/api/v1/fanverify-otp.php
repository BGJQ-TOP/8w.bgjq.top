<?php
/**
 * FanVerify 扫码绑定 —— OTP 申请与轮询
 *
 *   POST /passport/api/v1/fanverify-otp
 *        申请一个 OTP。请求体：{ "password": "当前密码" }
 *        返回：{ otp, qr_url, expires_in }
 *
 *   GET  /passport/api/v1/fanverify-otp?otp=xxx
 *        轮询 OTP 是否已被用户在小程序里确认。
 *        返回：{ status: "wait" | "ok" | "rate_limit" }
 *
 * 扫码绑定的完整流程：
 *   ① 本端点申请 OTP（同时先校验一次密码，让用户尽早知道密码对不对）
 *   ② 前端把 qr_url 指向的二维码展示给用户
 *   ③ 用户用 FanVerify 微信小程序扫码并确认
 *   ④ 前端轮询本端点，拿到 status=ok
 *   ⑤ 前端调 POST /passport/api/v1/bindings {type:"fanverify", otp, password}
 *      —— 落库前服务端会再轮询一次，不轻信前端"已确认"的说法
 *
 * 说明：OTP 轮询在 FanVerify 侧有 5 秒的最小间隔，太频繁会返回 rate_limit。
 * 前端固定 3 秒轮询一次即可，遇到 rate_limit 继续等下一次。
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;
use W8\Passport\Verification\FanVerifyClient;

Endpoint::run(function (Request $request, Application $app) {
    $account = $app->authenticator()->requireCurrent($request);
    $app->markApiContext(null, $account->id());

    $verifier = $app->fanVerifyVerifier();

    if (!$verifier->isConfigured()) {
        throw ApiException::notImplemented(
            'FanVerify 尚未配置，请在 .env 中填写 FANVERIFY_ACCESS_TOKEN'
        );
    }

    if ($request->isPost()) {
        return requestOtp($app, $request, $account);
    }

    return pollOtp($verifier, $request);
});

/**
 * 申请 OTP
 *
 * @return Response
 */
function requestOtp(Application $app, Request $request, $account)
{
    // 先验密码：等用户扫完码才发现密码错了，体验太差
    $password = (string) $request->input('password', '');
    if ($password === '' || !password_verify($password, $account->passwordHash())) {
        throw ApiException::validation('当前密码不正确', array('field' => 'password'));
    }

    $verifier = $app->fanVerifyVerifier();
    $otp = $verifier->requestOtp();

    $ttl = max(30, $app->config()->getInt('FANVERIFY_OTP_TTL', 180));

    $app->logger()->info('fanverify.otp_requested', array('account_id' => $account->id()));

    return Response::ok(array(
        'otp'        => $otp,
        // 二维码走服务端代理，浏览器永远看不到 accesstoken
        'qr_url'     => '/passport/api/v1/fanverify-qr?otp=' . rawurlencode($otp),
        'expires_in' => $ttl,
        'poll_interval' => 3,
    ), array('Cache-Control' => 'no-store'));
}

/**
 * 轮询
 *
 * @return Response
 */
function pollOtp($verifier, Request $request)
{
    $otp = $request->string('otp', (string) $request->query('otp', ''));
    if ($otp === '') {
        throw ApiException::validation('缺少参数 otp');
    }

    $result = $verifier->pollOtp($otp);
    $status = $result['status'];

    $payload = array(
        'status'   => $status,
        'verified' => $status === FanVerifyClient::STATUS_OK,
    );

    // 只回传"是否通过"，不在这里回传身份 —— 落库统一走 /bindings，
    // 避免出现"OTP 通过了但没人绑"的中间态被误用
    return Response::ok($payload, array('Cache-Control' => 'no-store'));
}
