<?php

namespace W8\Passport;

/**
 * 通行证维护任务
 *
 * 定期清理过期数据，避免会话、授权码、令牌、调用日志无限增长。
 * 建议每天跑一次（cron 或 systemd timer）：
 *
 *   php bin/maintenance.php
 *
 * 所有清理都是幂等的，重复执行不会有副作用。
 */
final class Maintenance
{
    /** @var Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * 执行全部清理
     *
     * @param int $logRetentionDays 调用日志保留天数
     * @return array<string,int> 各项清理的行数
     */
    public function run($logRetentionDays = 30)
    {
        $result = array(
            'sessions'   => $this->pruneSessions(),
            'codes'      => $this->pruneAuthorizationCodes(),
            'tokens'     => $this->pruneTokens(),
            'email_codes' => $this->pruneEmailCodes(),
            'api_logs'   => $this->pruneApiLogs($logRetentionDays),
        );

        $this->app->logger()->info('maintenance.done', $result);

        return $result;
    }

    /**
     * 过期登录会话
     *
     * @return int
     */
    public function pruneSessions()
    {
        return $this->app->sessions()->pruneExpired();
    }

    /**
     * 过期授权码（保留 1 天便于排查）
     *
     * @return int
     */
    public function pruneAuthorizationCodes()
    {
        return $this->app->oauthCodes()->pruneExpired();
    }

    /**
     * 过期令牌（保留 30 天）
     *
     * @return int
     */
    public function pruneTokens()
    {
        return $this->app->oauthTokens()->pruneExpired();
    }

    /**
     * 过期邮箱验证码
     *
     * @return int
     */
    public function pruneEmailCodes()
    {
        return $this->app->db()->execute(
            'DELETE FROM `passport_email_codes` WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
    }

    /**
     * 调用日志
     *
     * 日志同时用于限流计数，所以不能清得太激进 —— 默认保留 30 天。
     *
     * @param int $days
     * @return int
     */
    public function pruneApiLogs($days = 30)
    {
        $days = max(1, (int) $days);

        // 天数不能参数化，先夹紧成整数再拼进 SQL，杜绝注入
        return $this->app->db()->execute(
            "DELETE FROM `passport_api_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
    }
}
