<?php

namespace W8\Passport\Identity;

use W8\Passport\Directory\PlayerProfile;
use W8\Passport\Support\Database;

/**
 * 通行证账号仓库
 *
 * 所有对 passport_accounts 的读写都收敛在这里，别处不再直接写 SQL。
 */
final class AccountRepository
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @param int $id
     * @return Account|null
     */
    public function find($id)
    {
        $row = $this->db->selectOne('SELECT * FROM `passport_accounts` WHERE `id` = ? LIMIT 1', array((int) $id));
        return $row === null ? null : Account::fromRow($row);
    }

    /**
     * @param string $username
     * @return Account|null
     */
    public function findByUsername($username)
    {
        $row = $this->db->selectOne('SELECT * FROM `passport_accounts` WHERE `username` = ? LIMIT 1', array((string) $username));
        return $row === null ? null : Account::fromRow($row);
    }

    /**
     * @param string $email
     * @return Account|null
     */
    public function findByEmail($email)
    {
        $row = $this->db->selectOne(
            'SELECT * FROM `passport_accounts` WHERE `email` = ? LIMIT 1',
            array(strtolower(trim((string) $email)))
        );
        return $row === null ? null : Account::fromRow($row);
    }

    /**
     * @param string $playerName
     * @return Account|null
     */
    public function findByPlayerName($playerName)
    {
        $row = $this->db->selectOne(
            'SELECT * FROM `passport_accounts` WHERE `player_name` = ? LIMIT 1',
            array((string) $playerName)
        );
        return $row === null ? null : Account::fromRow($row);
    }

    /**
     * @param int $simpassUid
     * @return Account|null
     */
    public function findBySimpassUid($simpassUid)
    {
        $row = $this->db->selectOne(
            'SELECT * FROM `passport_accounts` WHERE `simpass_uid` = ? LIMIT 1',
            array((int) $simpassUid)
        );
        return $row === null ? null : Account::fromRow($row);
    }

    /**
     * 用户名 / 邮箱都能登录
     *
     * @param string $identifier
     * @return Account|null
     */
    public function findByLogin($identifier)
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT * FROM `passport_accounts` WHERE `username` = ? OR `email` = ? LIMIT 1',
            array($identifier, strtolower($identifier))
        );
        return $row === null ? null : Account::fromRow($row);
    }

    /**
     * @param array<string,mixed> $data
     * @return int 新账号ID
     */
    public function create(array $data)
    {
        $now = date('Y-m-d H:i:s');
        $data += array(
            'role'       => 'observer',
            'status'     => Account::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
        );

        return $this->db->insert('passport_accounts', $data);
    }

    /**
     * @param int $id
     * @param array<string,mixed> $data
     */
    public function update($id, array $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('passport_accounts', $data, '`id` = :id', array('id' => (int) $id));
    }

    /**
     * 用权威接口结果刷新账号上的玩家绑定
     */
    public function updatePlayerBinding($id, PlayerProfile $profile)
    {
        $this->update($id, array(
            'player_name'      => $profile->name(),
            'player_id'        => $profile->id(),
            'country_id'       => $profile->countryId(),
            'player_synced_at' => date('Y-m-d H:i:s'),
        ));
    }

    public function markEmailVerified($id)
    {
        $this->update($id, array('email_verified_at' => date('Y-m-d H:i:s')));
    }

    public function touchLogin($id, $ip)
    {
        $this->update($id, array(
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => substr((string) $ip, 0, 45),
        ));
    }

    /**
     * @param int $id
     */
    public function delete($id)
    {
        $this->db->execute('DELETE FROM `passport_accounts` WHERE `id` = ?', array((int) $id));
    }

    /**
     * 按邦国统计成员数（用于社区域的 member_count）
     *
     * @param int $countryId
     * @return int
     */
    public function countByCountry($countryId)
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM `passport_accounts` WHERE `country_id` = ? AND `status` = ?',
            array((int) $countryId, Account::STATUS_ACTIVE)
        );
    }

    /**
     * 后台账号列表（含邦国名称），密码哈希不外泄
     *
     * @return array<int,array<string,mixed>>
     */
    public function listAll()
    {
        $rows = $this->db->select(
            'SELECT a.`id`, a.`username`, a.`email`, a.`email_verified_at`, a.`role`, a.`status`,
                    a.`player_name`, a.`player_id`, a.`country_id`, a.`simpass_uid`, a.`simpass_level`,
                    a.`last_login_at`, a.`created_at`, c.`name` AS `country_name`
             FROM `passport_accounts` a
             LEFT JOIN `countries` c ON c.`id` = a.`country_id`
             ORDER BY a.`username`'
        );

        $list = array();
        foreach ($rows as $row) {
            $list[] = array(
                'id'                => (int) $row['id'],
                'username'          => $row['username'],
                'email'             => $row['email'],
                'email_verified'    => $row['email_verified_at'] !== null,
                'role'              => $row['role'],
                'status'            => (int) $row['status'],
                // 旧前端字段名，保持兼容
                'game_id'           => $row['player_name'],
                'player_name'       => $row['player_name'],
                'player_id'         => $row['player_id'] !== null ? (int) $row['player_id'] : null,
                'country_id'        => $row['country_id'] !== null ? (int) $row['country_id'] : null,
                'country_name'      => $row['country_name'],
                'jhtuid'            => $row['simpass_uid'] !== null ? (int) $row['simpass_uid'] : null,
                'level'             => $row['simpass_level'] !== null ? (int) $row['simpass_level'] : null,
                'last_login_at'     => $row['last_login_at'],
                'created_at'        => $row['created_at'],
            );
        }

        return $list;
    }
}
