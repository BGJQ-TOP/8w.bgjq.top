<?php
/**
 * FanVerify OTP 二维码 —— 服务端代理
 *
 *   GET /passport/api/v1/fanverify-qr?otp=xxx
 *
 * 为什么要代理：FanVerify 的 /openapi/genqrcode 要求把 accesstoken 放在 query string 上。
 * 如果让浏览器直接去请求，令牌就会出现在前端 URL、浏览器历史与 Referer 里。
 * 所以这里由服务端带上令牌取回 PNG，再原样吐给浏览器。
 *
 * 需要通行证登录态，避免被当成公开的二维码代取服务。
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Request;

$app = Application::instance();
$request = Request::fromGlobals();

try {
    $app->authenticator()->requireCurrent($request);

    $otp = $request->string('otp', (string) $request->query('otp', ''));
    if ($otp === '') {
        throw ApiException::validation('缺少参数 otp');
    }

    // OTP 是 FanVerify 侧签发的短随机串，这里做一次保守的长度/字符校验，
    // 避免把任意内容拼进上游请求
    if (strlen($otp) > 64 || preg_match('/^[A-Za-z0-9_-]+$/', $otp) !== 1) {
        throw ApiException::validation('otp 格式不正确');
    }

    $verifier = $app->fanVerifyVerifier();
    if (!$verifier->isConfigured()) {
        throw ApiException::notImplemented('FanVerify 尚未配置');
    }

    $png = $verifier->qrCodePng($otp);
} catch (ApiException $e) {
    http_response_code($e->httpStatus());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array('ok' => false, 'error' => array('code' => $e->errorCode(), 'message' => $e->getMessage())),
        JSON_UNESCAPED_UNICODE
    );
    exit;
} catch (Throwable $e) {
    $app->logger()->error('fanverify.qr_failed', array('message' => $e->getMessage()));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'error' => array('code' => 'server_error', 'message' => '二维码获取失败')), JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
// 二维码对应一次性 OTP，绝不能被缓存
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo $png;
