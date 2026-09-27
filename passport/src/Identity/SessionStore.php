<?php

namespace W8\Passport\Identity;

use W8\Passport\Http\Request;
use W8\Passport\Support\Config;
use W8\Passport\Support\Database;
use W8\Passport\Support\Logger;
use W8\Passport\Support\Str;

/**
 * 通行证登录态
 *
 * 浏览器侧只放一个随机令牌（HttpOnly Cookie），数据库里只存它的 SHA-256。
 * 即使数据库泄露，也无法据此伪造登录态。
 */
final class SessionStore
{
    /** @var Database */
    private $db;

    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    public function __construct(Database $db, Config $config, Logger $logger)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function cookieName()
    {
        return $this->config->getString('PASSPORT_COOKIE_NAME', 'w8_passport');
    }

    public function ttl()
    {
        return max(300, $this->config->getInt('PASSPORT_SESSION_TTL', 86400));
    }

    /**
     * 建立会话，返回明文令牌并下发 Cookie
     *
     * @param int $accountId
     * @param Request $request
     * @return string 明文令牌
     */
    public function create($accountId, Request $request)
    {
        $token = Str::randomUrlSafe(32);
        $ttl = $this->ttl();
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        $this->db->insert('passport_sessions', array(
            'account_id'   => (int) $accountId,
            'token_hash'   => Str::hash($token),
            'ip_address'   => substr($request->ip(), 0, 45),
            'user_agent'   => $request->userAgent(),
            'expires_at'   => $expiresAt,
            'last_seen_at' => date('Y-m-d H:i:s'),
        ));

        $this->writeCookie($token, time() + $ttl);

        return $token;
    }

    /**
     * 从 Cookie 还原登录账号
     *
     * @param Request $request
     * @return Account|null
     */
    public function resolve(Request $request)
    {
        $token = $request->cookie($this->cookieName());
        if ($token === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT s.`id` AS `session_id`, s.`last_seen_at`, a.*
             FROM `passport_sessions` s
             INNER JOIN `passport_accounts` a ON a.`id` = s.`account_id`
             WHERE s.`token_hash` = ? AND s.`expires_at` > NOW() AND a.`status` = ?
             LIMIT 1',
            array(Str::hash($token), Account::STATUS_ACTIVE)
        );

        if ($row === null) {
            // 令牌无效/过期：顺手清掉浏览器里那块没用的 Cookie
            $this->clearCookie();
            return null;
        }

        $this->touch((int) $row['session_id'], isset($row['last_seen_at']) ? $row['last_seen_at'] : null);

        unset($row['session_id'], $row['last_seen_at']);
        return Account::fromRow($row);
    }

    /**
     * 注销当前会话
     */
    public function destroy(Request $request)
    {
        $token = $request->cookie($this->cookieName());
        if ($token !== '') {
            $this->db->execute('DELETE FROM `passport_sessions` WHERE `token_hash` = ?', array(Str::hash($token)));
        }
        $this->clearCookie();
    }

    /**
     * 注销某账号的全部会话（改密码、封禁时用）
     */
    public function destroyAllForAccount($accountId)
    {
        $this->db->execute('DELETE FROM `passport_sessions` WHERE `account_id` = ?', array((int) $accountId));
    }

    /**
     * 清理过期会话
     *
     * @return int 删除行数
     */
    public function pruneExpired()
    {
        return $this->db->execute('DELETE FROM `passport_sessions` WHERE `expires_at` < NOW()');
    }

    /**
     * 在线账号数（"在线代表"的来源）
     *
     * @param int $withinSeconds
     * @return int
     */
    public function onlineCount($withinSeconds = 300)
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(DISTINCT `account_id`) FROM `passport_sessions`
             WHERE `expires_at` > NOW() AND `last_seen_at` > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            array(max(1, (int) $withinSeconds))
        );
    }

    /**
     * 节流更新 last_seen_at：60 秒内不重复写库
     */
    private function touch($sessionId, $lastSeenAt)
    {
        if ($lastSeenAt !== null) {
            $previous = strtotime((string) $lastSeenAt);
            if ($previous !== false && (time() - $previous) < 60) {
                return;
            }
        }

        $this->db->execute(
            'UPDATE `passport_sessions` SET `last_seen_at` = NOW() WHERE `id` = ?',
            array((int) $sessionId)
        );
    }

    private function writeCookie($value, $expiresAt)
    {
        if (headers_sent()) {
            return;
        }

        $options = array(
            'expires'  => $expiresAt,
            'path'     => '/',
            'domain'   => $this->config->getString('PASSPORT_COOKIE_DOMAIN'),
            'secure'   => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        );

        setcookie($this->cookieName(), $value, $options);
        $_COOKIE[$this->cookieName()] = $value;
    }

    private function clearCookie()
    {
        if (headers_sent()) {
            return;
        }

        $options = array(
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => $this->config->getString('PASSPORT_COOKIE_DOMAIN'),
            'secure'   => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        );

        setcookie($this->cookieName(), '', $options);
        unset($_COOKIE[$this->cookieName()]);
    }

    private function isSecureRequest()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
        }
        return false;
    }
}
