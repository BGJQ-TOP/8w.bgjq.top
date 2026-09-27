<?php
/**
 * 兼容层：把旧代码的 Auth 调用接到 8W通行证系统上
 *
 * 背景：旧站的身份表 users 已经被通行证取代（passport_accounts）。
 * 站点里仍有若干页面依赖 Auth 类与 $_SESSION['user']，为了不在一次重构里
 * 改动全部页面，这里保留旧类的公开方法签名，内部全部委托给通行证。
 *
 * 单一真源：登录态以通行证会话 Cookie 为准。
 * $_SESSION['user'] 只是给旧页面看的镜像，不参与鉴权判定。
 *
 * ⚠ 新代码请直接用 passport/src 里的 Passport 服务，不要再走这个类。
 */

require_once __DIR__ . '/../../passport/src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Directory\PlayerProfile;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Request;
use W8\Passport\Identity\Account;

class Auth
{
    /** @var Application */
    private $app;

    /** @var Account|null */
    private $account;

    /**
     * @param PDO|null $db 保留旧签名；通行证自带连接，此处不再使用
     */
    public function __construct($db = null)
    {
        $this->app = Application::instance();
        $this->account = $this->resolveAccount();

        if ($this->account !== null) {
            $this->syncLegacySession();
        }
    }

    // ========================================================================
    //  登录态
    // ========================================================================

    /**
     * @return Account|null
     */
    public function account()
    {
        return $this->account;
    }

    /**
     * 直接拿通行证账号仓库（后台管理用）
     *
     * @return \W8\Passport\Identity\AccountRepository
     */
    public function accountRepository()
    {
        return $this->app->accounts();
    }

    /**
     * 邦国名称（读本地缓存，不回源）
     *
     * @param int|null $countryId
     * @return string|null
     */
    public function countryName($countryId)
    {
        if ($countryId === null || (int) $countryId <= 0) {
            return null;
        }

        $name = $this->app->db()->selectValue(
            'SELECT `name` FROM `countries` WHERE `id` = ? LIMIT 1',
            array((int) $countryId)
        );

        return $name === null ? null : (string) $name;
    }

    public function isLoggedIn()
    {
        return $this->account !== null;
    }

    /**
     * 旧格式的用户数组
     *
     * @return array<string,mixed>|null
     */
    public function getCurrentUser()
    {
        return $this->account === null ? null : $this->toLegacyUser($this->account);
    }

    /**
     * 角色等级校验（旧站沿用）
     */
    public function hasRole($requiredRole)
    {
        if ($this->account === null) {
            return false;
        }
        return self::roleLevel($this->account->role()) >= self::roleLevel((string) $requiredRole);
    }

    /**
     * @param string $identifier 用户名或邮箱
     * @param string $password
     * @return array<string,mixed> ['success'=>true,'user'=>[...]] 或 ['error'=>'...']
     */
    public function login($identifier, $password)
    {
        try {
            $result = $this->app->authenticator()->login($identifier, $password, Request::fromGlobals());
        } catch (ApiException $e) {
            return array('error' => $e->getMessage());
        }

        $this->account = $result['account'];
        $this->syncLegacySession();

        return array(
            'success' => true,
            'user'    => $this->toLegacyUser($this->account),
            'token'   => $result['token'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function logout()
    {
        $this->app->authenticator()->logout(Request::fromGlobals());

        $this->account = null;
        unset($_SESSION['user'], $_SESSION['token']);

        return array('success' => true);
    }

    // ========================================================================
    //  账号
    // ========================================================================

    /**
     * 按用户名查账号（旧格式）
     *
     * @param string $username
     * @return array<string,mixed>|null
     */
    public function getUserByUsername($username)
    {
        $account = $this->app->accounts()->findByUsername((string) $username);
        return $account === null ? null : $this->toLegacyUser($account);
    }

    /**
     * 按通行证UID查账号（旧格式）
     *
     * @param int $id
     * @return array<string,mixed>|null
     */
    public function getUserById($id)
    {
        $account = $this->app->accounts()->find((int) $id);
        return $account === null ? null : $this->toLegacyUser($account);
    }

    /**
     * 修改密码（旧签名：需要提供原密码）
     *
     * @return array<string,mixed>
     */
    public function resetPassword($userId, $oldPassword, $newPassword)
    {
        $account = $this->app->accounts()->find((int) $userId);
        if ($account === null) {
            return array('error' => '用户不存在');
        }

        try {
            $this->app->authenticator()->changePassword($account, $oldPassword, $newPassword, Request::fromGlobals());
        } catch (ApiException $e) {
            return array('error' => $e->getMessage());
        }

        return array('success' => true);
    }

    /**
     * 管理员开户（不经过邮箱/简幻通/玩家名的外部验证）
     *
     * 仅供管理员在后台直接开号使用。所有字段仍需齐全，且玩家名必须真实存在
     * —— 这里会走一次权威接口校验，只是不需要邮箱验证码与简幻通验证码。
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed> ['success'=>true,'user_id'=>int] 或 ['error'=>'...']
     */
    public function provision(array $fields)
    {
        $username   = trim((string) ($fields['username'] ?? ''));
        $password   = (string) ($fields['password'] ?? '');
        $email      = strtolower(trim((string) ($fields['email'] ?? '')));
        $playerName = trim((string) ($fields['player_name'] ?? ''));
        $role       = trim((string) ($fields['role'] ?? 'observer'));
        $simpassUid = isset($fields['simpass_uid']) && $fields['simpass_uid'] !== ''
            ? (int) $fields['simpass_uid'] : null;

        if ($username === '' || $password === '' || $email === '' || $playerName === '') {
            return array('error' => '用户名、密码、邮箱、游戏内玩家名均为必填项');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return array('error' => '邮箱格式不正确');
        }
        if (strlen($password) < 8) {
            return array('error' => '密码至少需要 8 个字符');
        }
        if (!in_array($role, self::roles(), true)) {
            return array('error' => '无效的角色');
        }

        $accounts = $this->app->accounts();
        if ($accounts->findByUsername($username) !== null) {
            return array('error' => '用户名已存在');
        }
        if ($accounts->findByEmail($email) !== null) {
            return array('error' => '邮箱已被占用');
        }
        if ($accounts->findByPlayerName($playerName) !== null) {
            return array('error' => '该游戏内玩家名已被绑定');
        }
        if ($simpassUid !== null && $accounts->findBySimpassUid($simpassUid) !== null) {
            return array('error' => '该简幻通ID已被绑定');
        }

        // 玩家名仍走权威接口校验，避免管理员手抖写错名字
        try {
            $profile = $this->app->players()->find($playerName, true);
        } catch (ApiException $e) {
            return array('error' => $e->getMessage());
        }

        if ($profile === null) {
            return array('error' => '游戏内不存在名为「' . $playerName . '」的玩家');
        }

        $now = date('Y-m-d H:i:s');
        $accountId = $accounts->create(array(
            'username'          => $username,
            'email'             => $email,
            'email_verified_at' => $now,
            'password_hash'     => password_hash($password, PASSWORD_DEFAULT),
            'simpass_uid'       => $simpassUid,
            'player_name'       => $profile->name(),
            'player_id'         => $profile->id(),
            'country_id'        => $profile->countryId(),
            'player_synced_at'  => $now,
            'role'              => $role,
            'status'            => Account::STATUS_ACTIVE,
        ));

        return array('success' => true, 'user_id' => $accountId);
    }

    /**
     * 更新账号（管理员）
     *
     * @param int $id
     * @param array<string,mixed> $fields 可含 password / role / status / country_id
     * @return array<string,mixed>
     */
    public function updateAccount($id, array $fields)
    {
        $accounts = $this->app->accounts();
        $account = $accounts->find((int) $id);
        if ($account === null) {
            return array('error' => '用户不存在');
        }

        $data = array();

        if (isset($fields['password']) && $fields['password'] !== '') {
            if (strlen((string) $fields['password']) < 8) {
                return array('error' => '密码至少需要 8 个字符');
            }
            $data['password_hash'] = password_hash((string) $fields['password'], PASSWORD_DEFAULT);
        }

        if (isset($fields['role'])) {
            if (!in_array((string) $fields['role'], self::roles(), true)) {
                return array('error' => '无效的角色');
            }
            $data['role'] = (string) $fields['role'];
        }

        if (isset($fields['status'])) {
            $data['status'] = (int) $fields['status'];
        }

        if (array_key_exists('country_id', $fields)) {
            $data['country_id'] = $fields['country_id'] === null || $fields['country_id'] === ''
                ? null : (int) $fields['country_id'];
        }

        if ($data === array()) {
            return array('error' => '没有要更新的内容');
        }

        $accounts->update((int) $id, $data);

        // 改密或停用后，强制其它会话下线
        if (isset($data['password_hash']) || (isset($data['status']) && (int) $data['status'] !== Account::STATUS_ACTIVE)) {
            $this->app->sessions()->destroyAllForAccount((int) $id);
        }

        return array('success' => true);
    }

    /**
     * 删除账号
     *
     * @return array<string,mixed>
     */
    public function deleteAccount($id)
    {
        $account = $this->app->accounts()->find((int) $id);
        if ($account === null) {
            return array('error' => '用户不存在');
        }

        $this->app->accounts()->delete((int) $id);
        return array('success' => true);
    }

    // ========================================================================
    //  内部
    // ========================================================================

    /**
     * @return Account|null
     */
    private function resolveAccount()
    {
        try {
            return $this->app->authenticator()->current(Request::fromGlobals());
        } catch (Throwable $e) {
            // 数据库不可用时不应让页面直接白屏
            if (function_exists('appLog')) {
                appLog('AUTH', '读取通行证会话失败', array('message' => $e->getMessage()));
            }
            return null;
        }
    }

    /**
     * 把通行证账号镜像进旧会话变量，供旧页面读取
     */
    private function syncLegacySession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if ($this->account !== null) {
            $_SESSION['user'] = $this->toLegacyUser($this->account);
        }
    }

    /**
     * 转成旧代码期望的字段形状
     *
     * ⚠ 刻意不返回密码哈希：这个数组会直接被 jsonSuccess 序列化给前端，
     *   带上哈希等于把全站账号的密码哈希送到浏览器。需要校验密码请用
     *   passport/src 里的 Account::passwordHash()。
     *
     * @param Account $account
     * @return array<string,mixed>
     */
    private function toLegacyUser(Account $account)
    {
        return array(
            'id'          => $account->id(),
            'username'    => $account->username(),
            'email'       => $account->email(),
            'game_id'     => $account->playerName(),
            'player_id'   => $account->playerId(),
            'country_id'  => $account->countryId(),
            'role'        => $account->role(),
            'status'      => $account->status(),
            'jhtuid'      => $account->simpassUid(),
            'level'       => $account->simpassLevel(),
            'created_at'  => $account->createdAt(),
        );
    }

    /**
     * @return array<int,string>
     */
    public static function roles()
    {
        return array('observer', 'diplomat', 'peacekeeper', 'permanent_member', 'secretary_general');
    }

    /**
     * @param string $role
     * @return int
     */
    public static function roleLevel($role)
    {
        $levels = array(
            'observer'          => 0,
            'diplomat'          => 1,
            'peacekeeper'       => 2,
            'permanent_member'  => 3,
            'secretary_general' => 4,
        );

        return isset($levels[$role]) ? $levels[$role] : -1;
    }
}
