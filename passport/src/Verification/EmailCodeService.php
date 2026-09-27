<?php

namespace W8\Passport\Verification;

use W8\Passport\Contracts\EmailVerifier;
use W8\Passport\Http\ApiException;
use W8\Passport\Support\Config;
use W8\Passport\Support\Database;
use W8\Passport\Support\Logger;
use W8\Passport\Support\Str;

/**
 * 邮箱验证码服务
 *
 * 这里已经完整实现：生成、哈希落库、限流、过期、错误次数、一次性消费。
 * 唯一缺的是"把验证码发出去"——那一步委托给 EmailVerifier。
 *
 * ⚠ TODO：邮件发送接口待对接。
 *   接口到位后实现 Contracts\EmailVerifier 并在 Application 里换绑定即可，
 *   本文件不需要改动。
 */
final class EmailCodeService
{
    /** 同一邮箱同场景两次发送的最小间隔（秒） */
    const RESEND_INTERVAL = 60;

    /** 同一邮箱同场景每小时最多发送次数 */
    const HOURLY_LIMIT = 5;

    /** 单个验证码允许的最大校验失败次数 */
    const MAX_ATTEMPTS = 5;

    /** @var Database */
    private $db;

    /** @var EmailVerifier */
    private $sender;

    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    public function __construct(Database $db, EmailVerifier $sender, Config $config, Logger $logger)
    {
        $this->db = $db;
        $this->sender = $sender;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function sender()
    {
        return $this->sender;
    }

    public function isDeliverable()
    {
        return $this->sender->isConfigured();
    }

    /**
     * 下发验证码
     *
     * @param string $email
     * @param string $scene register / rebind / reset
     * @return int 有效期秒数
     */
    public function issue($email, $scene = 'register')
    {
        $email = $this->normalizeEmail($email);
        $scene = $this->normalizeScene($scene);

        $this->assertWithinRateLimit($email, $scene);

        $ttl = $this->config->getInt('EMAIL_CODE_TTL', 600);
        $code = Str::numericCode(6);

        // 同一邮箱同场景的旧码直接作废，避免多码并存
        $this->db->execute(
            'UPDATE `passport_email_codes` SET `consumed_at` = NOW()
             WHERE `email` = ? AND `scene` = ? AND `consumed_at` IS NULL',
            array($email, $scene)
        );

        $this->db->insert('passport_email_codes', array(
            'email'      => $email,
            'scene'      => $scene,
            'code_hash'  => Str::hash($code),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            'ip_address' => $this->clientIp(),
        ));

        // 发送失败时把刚写入的码作废，保持"库里只有能用的码"
        try {
            $this->sender->sendCode($email, $code, $scene, $ttl);
        } catch (\Throwable $e) {
            $this->db->execute(
                'UPDATE `passport_email_codes` SET `consumed_at` = NOW()
                 WHERE `email` = ? AND `scene` = ? AND `consumed_at` IS NULL',
                array($email, $scene)
            );
            throw $e;
        }

        $this->logger->info('email_code.issued', array('email' => Str::maskEmail($email), 'scene' => $scene));

        return $ttl;
    }

    /**
     * 校验验证码（成功即消费，不可重复使用）
     *
     * @param string $email
     * @param string $scene
     * @param string $code
     * @return bool
     */
    public function verify($email, $scene, $code)
    {
        $email = $this->normalizeEmail($email);
        $scene = $this->normalizeScene($scene);
        $code = trim((string) $code);

        if ($code === '') {
            return false;
        }

        $row = $this->db->selectOne(
            'SELECT `id`, `code_hash`, `attempts` FROM `passport_email_codes`
             WHERE `email` = ? AND `scene` = ? AND `consumed_at` IS NULL AND `expires_at` > NOW()
             ORDER BY `id` DESC LIMIT 1',
            array($email, $scene)
        );

        if ($row === null) {
            return false;
        }

        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            $this->consume((int) $row['id']);
            return false;
        }

        if (!Str::equals((string) $row['code_hash'], Str::hash($code))) {
            $this->db->execute(
                'UPDATE `passport_email_codes` SET `attempts` = `attempts` + 1 WHERE `id` = ?',
                array((int) $row['id'])
            );
            $this->logger->info('email_code.mismatch', array(
                'email' => Str::maskEmail($email), 'scene' => $scene, 'attempts' => (int) $row['attempts'] + 1,
            ));
            return false;
        }

        $this->consume((int) $row['id']);
        return true;
    }

    /**
     * 校验失败时抛异常（给 API 层用）
     *
     * @throws ApiException
     */
    public function assertVerify($email, $scene, $code)
    {
        if (!$this->isDeliverable()) {
            throw ApiException::notImplemented(
                '邮箱验证码发送接口尚未接入，无法完成邮箱验证。请在 .env 中配置 EMAIL_API_URL（见 passport/README.md）'
            );
        }

        if (!$this->verify($email, $scene, $code)) {
            throw ApiException::validation('邮箱验证码错误或已过期', array('field' => 'email_code'));
        }
    }

    private function consume($id)
    {
        $this->db->execute(
            'UPDATE `passport_email_codes` SET `consumed_at` = NOW() WHERE `id` = ?',
            array((int) $id)
        );
    }

    /**
     * @throws ApiException
     */
    private function assertWithinRateLimit($email, $scene)
    {
        $lastSentAt = $this->db->selectValue(
            'SELECT `created_at` FROM `passport_email_codes`
             WHERE `email` = ? AND `scene` = ? ORDER BY `id` DESC LIMIT 1',
            array($email, $scene)
        );

        if ($lastSentAt !== null) {
            $elapsed = time() - (int) strtotime((string) $lastSentAt);
            if ($elapsed < self::RESEND_INTERVAL) {
                throw ApiException::rateLimited(
                    '验证码已发送，请 ' . (self::RESEND_INTERVAL - $elapsed) . ' 秒后再试'
                );
            }
        }

        $sentInLastHour = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM `passport_email_codes`
             WHERE `email` = ? AND `scene` = ? AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            array($email, $scene)
        );

        if ($sentInLastHour >= self::HOURLY_LIMIT) {
            throw ApiException::rateLimited('该邮箱一小时内发送次数过多，请稍后再试');
        }
    }

    private function normalizeEmail($email)
    {
        return strtolower(trim((string) $email));
    }

    private function normalizeScene($scene)
    {
        $scene = strtolower(trim((string) $scene));
        return in_array($scene, array('register', 'rebind', 'reset'), true) ? $scene : 'register';
    }

    private function clientIp()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        }
        return substr($ip, 0, 45);
    }
}
