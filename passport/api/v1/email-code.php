<?php
/**
 * POST /passport/api/v1/email-code
 *
 * 发送邮箱验证码。
 *
 * ⚠ TODO：邮件发送接口待对接。
 *   验证码的生成 / 限流 / 落库 / 校验逻辑已完整实现，
 *   只差 Verification\HttpEmailVerifier 里的"发出去"这一步（由 .env 的 EMAIL_API_URL 驱动）。
 *
 * 请求体：{"email":"a@b.com","scene":"register"}
 *   scene 取值：
 *     register  注册时绑定邮箱（默认）
 *     bind      登录后补绑邮箱
 *     reset     找回密码（接口接入后启用）
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

    $email = strtolower($request->string('email'));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw ApiException::validation('邮箱格式不正确', array('field' => 'email'));
    }

    $scene = $request->string('scene', 'register');
    $service = $app->emailCodes();

    // 接口没接好就直接说明白，别让前端以为"发了但没收到"
    if (!$service->isDeliverable()) {
        throw ApiException::notImplemented(
            '邮箱验证码发送接口尚未接入（TODO）。请在 .env 中配置 EMAIL_API_URL 后重试。'
        );
    }

    $ttl = $service->issue($email, $scene);

    return Response::ok(array(
        'email'      => W8\Passport\Support\Str::maskEmail($email),
        'scene'      => $scene,
        'expires_in' => $ttl,
    ), array('Cache-Control' => 'no-store'));
});
