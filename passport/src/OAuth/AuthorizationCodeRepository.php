<?php

namespace W8\Passport\OAuth;

use W8\Passport\Support\Config;
use W8\Passport\Support\Database;
use W8\Passport\Support\Str;

/**
 * OAuth2 授权码仓库
 */
final class AuthorizationCodeRepository
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
     * 签发授权码
     *
     * @param array<string,mixed> $attributes
     * @return string 明文授权码
     */
    public function issue(array $attributes)
    {
        $code = Str::randomUrlSafe(32);
        $ttl = max(60, $this->config->getInt('PASSPORT_OAUTH_CODE_TTL', 300));

        $this->db->insert('passport_oauth_codes', array(
            'code_hash'             => Str::hash($code),
            'client_id'             => (string) $attributes['client_id'],
            'account_id'            => (int) $attributes['account_id'],
            'redirect_uri'          => (string) $attributes['redirect_uri'],
            'scopes'                => (string) $attributes['scopes'],
            'code_challenge'        => isset($attributes['code_challenge']) ? $attributes['code_challenge'] : null,
            'code_challenge_method' => isset($attributes['code_challenge_method']) ? $attributes['code_challenge_method'] : null,
            'expires_at'            => date('Y-m-d H:i:s', time() + $ttl),
        ));

        return $code;
    }

    /**
     * 消费授权码（一次性）
     *
     * 用 UPDATE ... WHERE consumed_at IS NULL 保证并发下只有一个请求能拿到。
     *
     * @param string $code
     * @return array<string,mixed>|null 成功返回记录，失败返回 null
     */
    public function consume($code)
    {
        $hash = Str::hash((string) $code);

        $row = $this->db->selectOne(
            'SELECT * FROM `passport_oauth_codes` WHERE `code_hash` = ? LIMIT 1',
            array($hash)
        );

        if ($row === null) {
            return null;
        }

        // 已用过 / 已过期：直接判失败。若已用过，说明可能被重放，连带吊销该码签发的令牌
        if ($row['consumed_at'] !== null) {
            $this->revokeTokensIssuedFromCode($row);
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $affected = $this->db->execute(
            'UPDATE `passport_oauth_codes` SET `consumed_at` = NOW() WHERE `id` = ? AND `consumed_at` IS NULL',
            array((int) $row['id'])
        );

        return $affected === 1 ? $row : null;
    }

    /**
     * @param array<string,mixed> $codeRow
     */
    private function revokeTokensIssuedFromCode(array $codeRow)
    {
        $this->db->execute(
            'UPDATE `passport_oauth_tokens` SET `revoked_at` = NOW()
             WHERE `client_id` = ? AND `account_id` = ? AND `revoked_at` IS NULL',
            array($codeRow['client_id'], (int) $codeRow['account_id'])
        );
    }

    /**
     * 清理过期授权码
     *
     * @return int
     */
    public function pruneExpired()
    {
        return $this->db->execute(
            'DELETE FROM `passport_oauth_codes` WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
    }
}
