<?php

namespace W8\Passport\Contracts;

use W8\Passport\Verification\FanVerifyIdentity;

/**
 * FanVerify 账号验证方
 *
 * 对接 fanverify.cn openAPI（文档 https://doc.fanverify.cn/llms.txt）。
 * 它同时承担两条绑定路径：
 *
 *   ① 手填：UID + 动态验证码
 *      verify($uid, $passCode, $playerName)
 *
 *   ② 扫码：申请 OTP → 出二维码 → 轮询
 *      requestOtp() → qrCodePng($otp) → pollOtp($otp)
 *
 * 默认绑定：Verification\HttpFanVerifyVerifier（令牌未配置时退化为
 * Verification\UnavailableFanVerifyVerifier，明确抛 not_implemented）。
 *
 * 定位：FanVerify 是**可选绑定**，和验证邮箱同级。
 *   未绑定不影响注册与登录；绑定后多一条身份凭据，
 *   第三方也可以通过 fanverify scope 读到它。
 */
interface FanVerifyVerifier
{
    /**
     * 手填验证：校验账号ID + 动态验证码
     *
     * @param int    $fanverifyUid FanVerify 账号ID
     * @param string $verifyCode   小程序里的动态验证码
     * @param string $playerName   当前通行证绑定的游戏内玩家名
     *                             （FanVerify 不返回游戏名，仅供日志；交叉校验只有简幻通那边做）
     * @return FanVerifyIdentity
     *
     * @throws \W8\Passport\Http\ApiException 验证失败/接口不可用
     */
    public function verify($fanverifyUid, $verifyCode, $playerName);

    /**
     * 扫码流程第一步：申请一个 OTP
     *
     * @return string OTP 串
     * @throws \W8\Passport\Http\ApiException
     */
    public function requestOtp();

    /**
     * 扫码流程第二步：取 OTP 的二维码 PNG
     *
     * 返回值是二进制图片，**必须由服务端代理给浏览器**，
     * 否则 accesstoken 会出现在前端 URL 里。
     *
     * @param string $otp
     * @return string PNG 二进制
     * @throws \W8\Passport\Http\ApiException
     */
    public function qrCodePng($otp);

    /**
     * 扫码流程第三步：轮询 OTP 是否已被用户确认
     *
     * @param string $otp
     * @return array{status:string,identity:FanVerifyIdentity|null}
     *         status 取 ok / wait / rate_limit
     * @throws \W8\Passport\Http\ApiException
     */
    public function pollOtp($otp);

    /**
     * @return bool
     */
    public function isConfigured();

    /**
     * @return string
     */
    public function sourceName();
}
