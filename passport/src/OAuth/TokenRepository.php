<?php

namespace W8\Passport\OAuth;

use W8\Passport\Support\Config;
use W8\Passport\Support\Database;
use W8\Passport\Support\Str;

/**
 * OAuth2 令牌仓库
 *
 * 只存令牌的 SHA-256，明文令牌仅在签发那一刻返回给调用方一次。
 */
final class TokenRepository
{
    /** @var Database */
    private $db;

    /** @var Config */
    private $config;

    public function __construct(Database $db, Config $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * 签发令牌对
     *
     * @param string $clientId
     * @param int $accountId
     * @param array<int,string> $scopes
     * @param string $grantType
     * @return array<string,mixed> OAuth2 token 响应体
     */
    public function issue($clientId, $accountId, array $scopes, $grantType = 'authorization_code')
    {
        $accessTtl = max(60, $this->config->getInt('PASSPORT_ACCESS_TTL', 7200));
        $refreshTtl = max($accessTtl, $this->config->getInt('PASSPORT_REFRESH_TTL', 2592000));

        $accessToken = Str::randomUrlSafe(32);
        $wantsRefresh = in_array('offline_access', $scopes, true) || $grantType === 'authorization_code';
        $refreshToken = $wantsRefresh ? Str::randomUrlSafe(48) : null;

        $this->db->insert('passport_oauth_tokens', array(
            'client_id'          => (string) $clientId,
            'account_id'         => (int) $accountId,
            'access_token_hash'  => Str::hash($accessToken),
            'refresh_token_hash' => $refreshToken === null ? null : Str::hash($refreshToken),
            'scopes'             => Scope::toString($scopes),
            'grant_type'         => (string) $grantType,
            'access_expires_at'  => date('Y-m-d H:i:s', time() + $accessTtl),
            'refresh_expires_at' => $refreshToken === null ? null : date('Y-m-d H:i:s', time() + $refreshTtl),
        ));

        $response = array(
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => $accessTtl,
            'scope'        => Scope::toString($scopes),
        );

        if ($refreshToken !== null) {
            $response['refresh_token'] = $refreshToken;
        }

        return $response;
    }

    /**
     * 按访问令牌取记录（含有效性与吊销校验）
     *
     * @param string $accessToken
     * @return array<string,mixed>|null
     */
    public function findActiveByAccessToken($accessToken)
    {
        if ((string) $accessToken === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT * FROM `passport_oauth_tokens` WHERE `access_token_hash` = ? LIMIT 1',
            array(Str::hash($accessToken))
        );

        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }
        if (strtotime((string) $row['access_expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    /**
     * 按刷新令牌取记录
     *
     * @param string $refreshToken
     * @return array<string,mixed>|null
     */
    public function findActiveByRefreshToken($refreshToken)
    {
        if ((string) $refreshToken === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT * FROM `passport_oauth_tokens` WHERE `refresh_token_hash` = ? LIMIT 1',
            array(Str::hash($refreshToken))
        );

        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }
        if ($row['refresh_expires_at'] !== null && strtotime((string) $row['refresh_expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    /**
     * 刷新：旧令牌作废，签发新的（轮换，防重放）
     *
     * @param array<string,mixed> $row
     * @param array<int,string> $scopes
     * @return array<string,mixed>
     */
    public function rotate(array $row, array $scopes)
    {
        $this->db->execute(
            'UPDATE `passport_oauth_tokens` SET `revoked_at` = NOW() WHERE `id` = ?',
            array((int) $row['id'])
        );

        return $this->issue($row['client_id'], (int) $row['account_id'], $scopes, 'refresh_token');
    }

    /**
     * @param string $accessToken
     */
    public function touchUsed($accessToken)
    {
        $this->db->execute(
            'UPDATE `passport_oauth_tokens` SET `last_used_at` = NOW() WHERE `access_token_hash` = ?',
            array(Str::hash($accessToken))
        );
    }

    /**
     * 吊销令牌（RFC 7009：无论存在与否都返回成功）
     *
     * @param string $token
     */
    public function revoke($token)
    {
        $hash = Str::hash((string) $token);
        $this->db->execute(
            'UPDATE `passport_oauth_tokens` SET `revoked_at` = NOW()
             WHERE (`access_token_hash` = ? OR `refresh_token_hash` = ?) AND `revoked_at` IS NULL',
            array($hash, $hash)
        );
    }

    /**
     * 吊销某用户对某应用的全部授权
     */
    public function revokeAll($clientId, $accountId)
    {
        $this->db->execute(
            'UPDATE `passport_oauth_tokens` SET `revoked_at` = NOW()
             WHERE `client_id` = ? AND `account_id` = ? AND `revoked_at` IS NULL',
            array((string) $clientId, (int) $accountId)
        );
    }

    /**
     * 某用户已授权的应用列表
     *
     * @param int $accountId
     * @return array<int,array<string,mixed>>
     */
    public function authorizedApps($accountId)
    {
        return $this->db->select(
            'SELECT t.`client_id`, t.`scopes`, t.`created_at`, t.`last_used_at`,
                    c.`name`, c.`logo_url`, c.`homepage_url`
             FROM `passport_oauth_tokens` t
             LEFT JOIN `passport_oauth_clients` c ON c.`client_id` = t.`client_id`
             WHERE t.`account_id` = ? AND t.`revoked_at` IS NULL
               AND (t.`refresh_expires_at` IS NULL OR t.`refresh_expires_at` > NOW())
             GROUP BY t.`client_id`, t.`scopes`, t.`created_at`, t.`last_used_at`,
                      c.`name`, c.`logo_url`, c.`homepage_url`
             ORDER BY t.`created_at` DESC',
            array((int) $accountId)
        );
    }

    /**
     * 清理过期令牌
     *
     * @return int
     */
    public function pruneExpired()
    {
        return $this->db->execute(
            'DELETE FROM `passport_oauth_tokens`
             WHERE `access_expires_at` < DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND (`refresh_expires_at` IS NULL OR `refresh_expires_at` < DATE_SUB(NOW(), INTERVAL 30 DAY))'
        );
    }
}
