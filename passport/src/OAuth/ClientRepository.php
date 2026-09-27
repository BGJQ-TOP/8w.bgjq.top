<?php

namespace W8\Passport\OAuth;

use W8\Passport\Support\Database;
use W8\Passport\Support\Str;

/**
 * 第三方应用仓库
 */
final class ClientRepository
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @param string $clientId
     * @return array<string,mixed>|null
     */
    public function findByClientId($clientId)
    {
        return $this->db->selectOne(
            'SELECT * FROM `passport_oauth_clients` WHERE `client_id` = ? LIMIT 1',
            array((string) $clientId)
        );
    }

    /**
     * @param int $id
     * @return array<string,mixed>|null
     */
    public function find($id)
    {
        return $this->db->selectOne('SELECT * FROM `passport_oauth_clients` WHERE `id` = ? LIMIT 1', array((int) $id));
    }

    /**
     * 应用是否可用
     *
     * @param array<string,mixed> $client
     * @return bool
     */
    public function isEnabled(array $client)
    {
        return (int) $client['status'] === 1;
    }

    /**
     * 校验客户端密钥
     *
     * @param array<string,mixed> $client
     * @param string $secret
     * @return bool
     */
    public function verifySecret(array $client, $secret)
    {
        if ((int) $client['is_confidential'] !== 1) {
            // 公开客户端没有密钥，安全性由 PKCE 保证
            return true;
        }

        $hash = isset($client['client_secret_hash']) ? (string) $client['client_secret_hash'] : '';
        if ($hash === '' || $secret === '') {
            return false;
        }

        // 兼容历史明文存储：首次校验通过后自动升级为哈希
        if (strncmp($hash, '$2y$', 4) === 0 || strncmp($hash, '$argon2', 7) === 0) {
            return password_verify((string) $secret, $hash);
        }

        if (Str::equals($hash, Str::hash((string) $secret))) {
            $this->db->execute(
                'UPDATE `passport_oauth_clients` SET `client_secret_hash` = ? WHERE `id` = ?',
                array(password_hash((string) $secret, PASSWORD_DEFAULT), (int) $client['id'])
            );
            return true;
        }

        return false;
    }

    /**
     * 回调地址是否在白名单内（防开放重定向）
     *
     * @param array<string,mixed> $client
     * @param string $redirectUri
     * @return bool
     */
    public function isRedirectAllowed(array $client, $redirectUri)
    {
        $redirectUri = trim((string) $redirectUri);
        if ($redirectUri === '') {
            return false;
        }

        foreach ($this->redirectUris($client) as $allowed) {
            if ($allowed === $redirectUri) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $client
     * @return array<int,string>
     */
    public function redirectUris(array $client)
    {
        $raw = isset($client['redirect_uris']) ? (string) $client['redirect_uris'] : '';
        $uris = array();
        foreach (preg_split('/[\r\n]+/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $uris[] = $line;
            }
        }
        return $uris;
    }

    /**
     * 默认回调地址（只有一个时可直接用）
     *
     * @param array<string,mixed> $client
     * @return string
     */
    public function defaultRedirectUri(array $client)
    {
        $uris = $this->redirectUris($client);
        return count($uris) === 1 ? $uris[0] : '';
    }

    /**
     * @param array<string,mixed> $client
     * @return array<int,string>
     */
    public function allowedScopes(array $client)
    {
        $raw = isset($client['allowed_scopes']) ? (string) $client['allowed_scopes'] : Scope::DEFAULT_SCOPE;
        return Scope::parse($raw);
    }

    /**
     * 创建应用
     *
     * @param array<string,mixed> $attributes
     * @return array{client_id:string,client_secret:string,id:int}
     */
    public function create(array $attributes)
    {
        $clientId = isset($attributes['client_id']) && $attributes['client_id'] !== ''
            ? (string) $attributes['client_id']
            : Str::randomHex(16);

        $secret = Str::randomUrlSafe(32);
        $isConfidential = array_key_exists('is_confidential', $attributes) ? (int) $attributes['is_confidential'] : 1;

        $id = $this->db->insert('passport_oauth_clients', array(
            'client_id'          => $clientId,
            'client_secret_hash' => $isConfidential === 1 ? password_hash($secret, PASSWORD_DEFAULT) : null,
            'name'               => (string) $attributes['name'],
            'description'        => isset($attributes['description']) ? $attributes['description'] : null,
            'homepage_url'       => isset($attributes['homepage_url']) ? $attributes['homepage_url'] : null,
            'logo_url'           => isset($attributes['logo_url']) ? $attributes['logo_url'] : null,
            'redirect_uris'      => isset($attributes['redirect_uris']) ? $attributes['redirect_uris'] : '',
            'allowed_scopes'     => isset($attributes['allowed_scopes'])
                ? Scope::toString(Scope::parse($attributes['allowed_scopes']))
                : Scope::DEFAULT_SCOPE,
            'is_confidential'    => $isConfidential,
            'rate_limit'         => isset($attributes['rate_limit']) ? (int) $attributes['rate_limit'] : 600,
            'status'             => 1,
            'owner_account_id'   => isset($attributes['owner_account_id']) ? (int) $attributes['owner_account_id'] : null,
        ));

        return array('id' => $id, 'client_id' => $clientId, 'client_secret' => $secret);
    }

    /**
     * 重置密钥
     *
     * @param int $id
     * @return string 新密钥
     */
    public function rotateSecret($id)
    {
        $secret = Str::randomUrlSafe(32);
        $this->db->execute(
            'UPDATE `passport_oauth_clients` SET `client_secret_hash` = ? WHERE `id` = ?',
            array(password_hash($secret, PASSWORD_DEFAULT), (int) $id)
        );
        return $secret;
    }

    /**
     * @param string $clientId
     */
    public function touchLastUsed($clientId)
    {
        $this->db->execute(
            'UPDATE `passport_oauth_clients` SET `last_used_at` = NOW() WHERE `client_id` = ?',
            array((string) $clientId)
        );
    }

    /**
     * 最近一分钟调用次数（限流用）
     *
     * @param string $clientId
     * @return int
     */
    public function callsInLastMinute($clientId)
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM `passport_api_logs`
             WHERE `client_id` = ? AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
            array((string) $clientId)
        );
    }

    /**
     * 对外输出（绝不带密钥哈希）
     *
     * @param array<string,mixed> $client
     * @return array<string,mixed>
     */
    public function toPublicArray(array $client)
    {
        return array(
            'client_id'      => $client['client_id'],
            'name'           => $client['name'],
            'description'    => isset($client['description']) ? $client['description'] : null,
            'homepage_url'   => isset($client['homepage_url']) ? $client['homepage_url'] : null,
            'logo_url'       => isset($client['logo_url']) ? $client['logo_url'] : null,
            'redirect_uris'  => $this->redirectUris($client),
            'allowed_scopes' => $this->allowedScopes($client),
            'is_confidential' => (int) $client['is_confidential'] === 1,
        );
    }
}
