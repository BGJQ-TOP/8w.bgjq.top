<?php
/**
 * FanVerify 接入自检
 *
 * 用法：
 *   php bin/fanverify-check.php
 *
 * 会依次检查：
 *   ① .env 里的 FANVERIFY_ACCESS_TOKEN / FANVERIFY_API_BASE 是否填了
 *   ② 令牌能否通过 FanVerify 的 /openapi/devinfo 自检（401 说明令牌无效/未启用/IP 未放行）
 *   ③ 令牌的绑定账号、模式、要求的等级、服务公告
 *   ④ 是否能成功申请 OTP（验证 /openapi/otp 可用）
 *
 * 这个脚本不会打印完整令牌，只显示前 8 位与后 4 位。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../passport/src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;

$app = Application::instance();
$config = $app->config();
$client = $app->fanVerifyClient();

function line($label, $value = '')
{
    echo sprintf("  %-22s %s\n", $label, $value);
}

function mask($token)
{
    $token = (string) $token;
    if (strlen($token) <= 12) {
        return $token === '' ? '(空)' : str_repeat('*', strlen($token));
    }
    return substr($token, 0, 8) . '…' . substr($token, -4) . sprintf('（长度 %d）', strlen($token));
}

/**
 * 探测本机出口 IP
 *
 * FanVerify 的令牌白名单只允许绑一个 IP，出口 IP 对不上时所有接口都 401，
 * 所以自检时先把出口 IP 打出来，方便和白名单里的值比对。
 *
 * @return string|null 探测失败时返回 null（不影响后续检查）
 */
function detectEgressIp()
{
    $services = array(
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://ipinfo.io/ip',
    );

    foreach ($services as $service) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $service);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200 && is_string($result)) {
            $ip = trim($result);
            // ipinfo 会返回纯 IP；ifconfig.me 也是。做个保守校验避免把 HTML 当 IP
            if (preg_match('/^[0-9a-fA-F:.]{3,45}$/', $ip) === 1) {
                return $ip;
            }
        }
    }

    return null;
}

echo "\n=== FanVerify 接入自检 ===\n\n";

// ⓪ 运行环境
echo "[0/4] 运行环境\n";
if (function_exists('curl_init')) {
    line('cURL 扩展', '已加载');
    echo "  \033[32m✓ 可以发起外部请求\033[0m\n";
} else {
    line('cURL 扩展', '缺失');
    echo "\n  \033[31m✗ 当前 PHP 没有 cURL 扩展，FanVerify 调用必然失败。\033[0m\n";
    echo "    安装方式（Ubuntu/Debian）：sudo apt install php-curl 然后重启 php-fpm\n\n";
    exit(1);
}

// FanVerify 对令牌做了来源 IP 白名单（只允许绑一个 IP），
// 出口 IP 对不上时所有接口都返回 401 —— 所以这里先把出口 IP 打出来，方便和白名单比对。
$egressIp = detectEgressIp();
line('本机出口 IP', $egressIp === null ? '(探测失败，可忽略)' : $egressIp);

// ① 配置
echo "\n[1/4] 配置\n";
$base = $config->getString('FANVERIFY_API_BASE', 'https://api.fanverify.cn');
$token = $config->getString('FANVERIFY_ACCESS_TOKEN');

line('接口根地址', $base);
line('访问令牌', mask($token));
line('超时', $config->getInt('FANVERIFY_API_TIMEOUT', 10) . ' 秒');
line('本站等级门槛', $config->getInt('FANVERIFY_REQUIRED_LEVEL', 0) === 0
    ? '不限（0）'
    : $config->getInt('FANVERIFY_REQUIRED_LEVEL') . ' 级');

if (!$client->isConfigured()) {
    echo "\n\033[31m✗ 令牌未配置，无法继续。请在 .env 里填写 FANVERIFY_ACCESS_TOKEN。\033[0m\n\n";
    exit(1);
}
echo "  \033[32m✓ 配置齐全\033[0m\n";

// ② 连通性
echo "\n[2/4] 连通性（GET /openapi/devinfo）\n";
try {
    $info = $client->developerInfo();
    echo "  \033[32m✓ 调用成功\033[0m\n\n";
} catch (ApiException $e) {
    echo "\n  \033[31m✗ 调用失败：{$e->getMessage()}\033[0m\n";
    echo "\n  排查建议（按可能性排序）：\n";
    echo "    1. \033[33m来源 IP 白名单\033[0m —— FanVerify 的令牌只允许绑定一个出口 IP，\n";
    echo "       这是最常见的原因。本机出口 IP：" . ($egressIp === null ? '(探测失败，请手工确认)' : $egressIp) . "\n";
    echo "       请确认它和 FanVerify 开发者后台里登记的 IP 完全一致\n";
    echo "       （换服务器、走负载均衡或 CDN 出站都会导致不一致）\n";
    echo "    2. 令牌是否在 FanVerify 开发者后台处于启用状态\n";
    echo "    3. 令牌是否已过期或被重置\n";
    echo "    4. 用 curl 直接验证，排除应用层因素：\n";
    echo "        curl -i \"{$base}/openapi/devinfo?accesstoken=你的令牌\"\n\n";
    exit(1);
}

// ③ 令牌信息
echo "[3/4] 令牌信息\n";
line('签发时间', $info['issued_at'] === null ? '(未返回)' : $info['issued_at']);
line('绑定账号 UID', $info['bind_uid'] === null ? '(未返回)' : $info['bind_uid']);
line('对接模式', $info['mode'] === null ? '(未返回)' : $info['mode']);
line('要求的最低等级', $info['need_end_level'] === null ? '(未返回)' : $info['need_end_level']);
line('服务公告', $info['service_message'] === null ? '(无)' : $info['service_message']);
line('状态', $info['status'] === null ? '(未返回)' : $info['status']);

$required = $config->getInt('FANVERIFY_REQUIRED_LEVEL', 0);
if ($required > 0 && $info['need_end_level'] !== null && $required < $info['need_end_level']) {
    echo "\n  \033[33m! 本站门槛（{$required}）低于 FanVerify 要求的 {$info['need_end_level']}，实际会由 FanVerify 侧拦截\033[0m\n";
}

// ④ OTP 可用性
echo "\n[4/4] OTP 申请（GET /openapi/otp）\n";
try {
    $otp = $client->requestOtp();
    line('OTP', mask($otp) . '  ← 掩码显示');
    echo "  \033[32m✓ 扫码流程可用\033[0m\n";
} catch (ApiException $e) {
    echo "  \033[31m✗ 申请失败：{$e->getMessage()}\033[0m\n";
    echo "  （不影响手填流程 user_verify，可继续用 UID + 动态验证码绑定）\n";
}

echo "\n=== 自检结束 ===\n\n";
exit(0);
