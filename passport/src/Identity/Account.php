<?php

namespace W8\Passport\Identity;

/**
 * 通行证账号（只读视图）
 *
 * 对外输出时永远走 toPublicArray() / toProfileArray()，
 * 从根上杜绝 password_hash 之类的字段被顺手 json_encode 出去。
 */
final class Account
{
    const STATUS_ACTIVE = 1;
    const STATUS_DISABLED = 2;
    const STATUS_DELETED = 3;

    /** @var array<string,mixed> */
    private $attributes;

    /**
     * @param array<string,mixed> $row
     */
    private function __construct(array $row)
    {
        $this->attributes = $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return Account
     */
    public static function fromRow(array $row)
    {
        return new self($row);
    }

    public function id()
    {
        return (int) $this->attributes['id'];
    }

    public function username()
    {
        return (string) $this->attributes['username'];
    }

    /**
     * 验证邮箱（可选绑定）
     *
     * @return string|null 未绑定时为 null
     */
    public function email()
    {
        $email = isset($this->attributes['email']) ? $this->attributes['email'] : null;
        return ($email === null || $email === '') ? null : (string) $email;
    }

    public function passwordHash()
    {
        return (string) $this->attributes['password_hash'];
    }

    public function emailVerifiedAt()
    {
        return isset($this->attributes['email_verified_at']) ? $this->attributes['email_verified_at'] : null;
    }

    public function hasEmail()
    {
        return $this->email() !== null;
    }

    public function isEmailVerified()
    {
        return $this->hasEmail() && $this->emailVerifiedAt() !== null;
    }

    public function fanverifyUid()
    {
        return isset($this->attributes['fanverify_uid']) && $this->attributes['fanverify_uid'] !== null
            ? (int) $this->attributes['fanverify_uid'] : null;
    }

    public function fanverifyVerifiedAt()
    {
        return isset($this->attributes['fanverify_verified_at']) ? $this->attributes['fanverify_verified_at'] : null;
    }

    public function hasFanVerify()
    {
        return $this->fanverifyUid() !== null;
    }

    public function role()
    {
        return (string) $this->attributes['role'];
    }

    public function status()
    {
        return (int) $this->attributes['status'];
    }

    public function isActive()
    {
        return $this->status() === self::STATUS_ACTIVE;
    }

    public function playerName()
    {
        return isset($this->attributes['player_name']) ? (string) $this->attributes['player_name'] : '';
    }

    public function playerId()
    {
        return isset($this->attributes['player_id']) && $this->attributes['player_id'] !== null
            ? (int) $this->attributes['player_id'] : null;
    }

    public function countryId()
    {
        return isset($this->attributes['country_id']) && $this->attributes['country_id'] !== null
            ? (int) $this->attributes['country_id'] : null;
    }

    public function simpassUid()
    {
        return isset($this->attributes['simpass_uid']) && $this->attributes['simpass_uid'] !== null
            ? (int) $this->attributes['simpass_uid'] : null;
    }

    public function simpassLevel()
    {
        return isset($this->attributes['simpass_level']) && $this->attributes['simpass_level'] !== null
            ? (int) $this->attributes['simpass_level'] : null;
    }

    public function createdAt()
    {
        return isset($this->attributes['created_at']) ? $this->attributes['created_at'] : null;
    }

    public function lastLoginAt()
    {
        return isset($this->attributes['last_login_at']) ? $this->attributes['last_login_at'] : null;
    }

    /**
     * 完整字段（仅供内部使用，例如修改密码时取哈希）
     *
     * @return array<string,mixed>
     */
    public function raw()
    {
        return $this->attributes;
    }

    /**
     * 对外安全字段
     *
     * bindings 让前端一眼看清"哪些已绑定、哪些还能绑"，
     * 不用去猜 email 为 null 到底是没绑还是没验证。
     *
     * @return array<string,mixed>
     */
    public function toPublicArray()
    {
        return array(
            'id'                => $this->id(),
            'username'          => $this->username(),
            'email'             => $this->email(),
            'email_verified'    => $this->isEmailVerified(),
            'role'              => $this->role(),
            'status'            => $this->status(),
            'player_name'       => $this->playerName(),
            'player_id'         => $this->playerId(),
            'country_id'        => $this->countryId(),
            'simpass_uid'       => $this->simpassUid(),
            'simpass_level'     => $this->simpassLevel(),
            'fanverify_uid'     => $this->fanverifyUid(),
            'bindings'          => array(
                // 必填且不可解绑
                'player'    => true,
                'simpass'   => $this->simpassUid() !== null,
                // 可选绑定
                'email'     => $this->hasEmail(),
                'fanverify' => $this->hasFanVerify(),
            ),
            'created_at'        => $this->createdAt(),
            'last_login_at'     => $this->lastLoginAt(),
        );
    }

    /**
     * OAuth2 userinfo 输出，按 scope 裁剪
     *
     * @param array<int,string> $scopes
     * @return array<string,mixed>
     */
    public function toProfileArray(array $scopes)
    {
        $profile = array('sub' => (string) $this->id());

        if (in_array('basic', $scopes, true)) {
            $profile['username'] = $this->username();
            $profile['role'] = $this->role();
        }

        if (in_array('email', $scopes, true) && $this->hasEmail()) {
            // 未绑定邮箱时整块省略，第三方据此判断"该用户没有邮箱"，
            // 而不是拿到一个 null 去猜
            $profile['email'] = $this->email();
            $profile['email_verified'] = $this->isEmailVerified();
        }

        if (in_array('player', $scopes, true)) {
            $profile['player'] = array(
                'player_name' => $this->playerName(),
                'player_id'   => $this->playerId(),
                'country_id'  => $this->countryId(),
            );
        }

        if (in_array('country', $scopes, true)) {
            $profile['country_id'] = $this->countryId();
        }

        if (in_array('simpass', $scopes, true)) {
            $profile['simpass'] = array(
                'uid'   => $this->simpassUid(),
                'level' => $this->simpassLevel(),
            );
        }

        if (in_array('fanverify', $scopes, true) && $this->hasFanVerify()) {
            $profile['fanverify'] = array(
                'uid' => $this->fanverifyUid(),
            );
        }

        return $profile;
    }
}
