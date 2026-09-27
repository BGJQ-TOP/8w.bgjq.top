<?php

namespace W8\Passport\Contracts;

/**
 * 邮箱验证码发送方
 *
 * ⚠ TODO：邮件发送接口待对接。
 *   接口到位后实现本接口并在 Application 里替换绑定即可。
 *
 * 当前默认绑定：Verification\UnavailableEmailVerifier
 *   —— 它会明确抛出 not_implemented，注册流程不会因为"忘了配"而静默放行。
 *   验证码的生成、落库、校验、限流逻辑已经完整（Verification\EmailCodeService），
 *   只差"把码发出去"这一步。
 */
interface EmailVerifier
{
    /**
     * 发送验证码
     *
     * @param string $email 目标邮箱
     * @param string $code  明文验证码（由调用方生成）
     * @param string $scene register / rebind / reset
     * @param int    $ttlSeconds 有效期（秒）
     * @return void
     *
     * @throws \W8\Passport\Http\ApiException 发送失败
     */
    public function sendCode($email, $code, $scene, $ttlSeconds);

    /**
     * @return bool
     */
    public function isConfigured();

    /**
     * @return string
     */
    public function sourceName();
}
