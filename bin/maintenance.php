<?php
/**
 * 8W通行证 —— 维护任务入口
 *
 * 清理过期的会话、授权码、令牌、邮箱验证码与调用日志。
 * 建议加到 crontab，每天凌晨跑一次：
 *
 *   0 3 * * * /usr/bin/php /var/www/8w.bgjq.top/bin/maintenance.php >> /var/log/8w-passport-cron.log 2>&1
 *
 * 可选参数：
 *   --log-days=30   调用日志保留天数（默认 30）
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../passport/src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Maintenance;

$logDays = 30;
foreach ($argv as $argument) {
    if (strpos($argument, '--log-days=') === 0) {
        $logDays = (int) substr($argument, 11);
    }
}

try {
    $app = Application::instance();
    $result = (new Maintenance($app))->run($logDays);

    $labels = array(
        'sessions'    => '过期登录会话',
        'codes'       => '过期授权码',
        'tokens'      => '过期令牌',
        'email_codes' => '过期邮箱验证码',
        'api_logs'    => '历史调用日志',
    );

    echo "8W通行证维护完成：\n";
    foreach ($result as $key => $count) {
        $label = isset($labels[$key]) ? $labels[$key] : $key;
        echo sprintf("  %-18s 清理 %d 行\n", $label, $count);
    }
    echo "\n";

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '维护任务失败：' . $e->getMessage() . "\n");
    exit(1);
}
