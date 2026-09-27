<?php

namespace W8\Passport\Identity;

use W8\Passport\Http\ApiException;
use W8\Passport\Http\Request;
use W8\Passport\Support\Logger;

/**
 * 登录 / 登出 / 当前用户
 */
final class Authenticator
{
    /** @var AccountRepository */
    private $accounts;

    /** @var SessionStore */
    private $sessions;

    /** @var Logger */
    private $logger;

    public function __construct(AccountRepository $accounts, SessionStore $sessions, Logger $logger)
    {
        $this->accounts = $accounts;
        $this->sessions = $sessions;
        $this->logger = $logger;
    }

    /**
     * 登录
     *
     * @param string $identifier 用户名或邮箱
     * @param string $password
     * @param Request $request
     * @return array{account:Account,token:string}
     */
    public function login($identifier, $password, Request $request)
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '' || $password === '') {
            throw ApiException::validation('请填写账号和密码');
        }

        $account = $this->accounts->findByLogin($identifier);

        // 账号不存在时也走一次哈希校验，避免通过响应时间枚举账号
        $hash = $account !== null ? $account->passwordHash() : '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $valid = password_verify($password, $hash);

        if ($account === null || !$valid) {
            $this->logger->info('auth.login_failed', array('identifier' => $identifier, 'ip' => $request->ip()));
            throw ApiException::unauthorized('账号或密码错误');
        }

        if (!$account->isActive()) {
            throw ApiException::forbidden('该通行证已被停用，请联系管理员');
        }

        $token = $this->sessions->create($account->id(), $request);
        $this->accounts->touchLogin($account->id(), $request->ip());

        $this->logger->info('auth.login', array('account_id' => $account->id(), 'ip' => $request->ip()));

        // 重新读取，保证 last_login_at 等字段是最新的
        $fresh = $this->accounts->find($account->id());

        return array(
            'account' => $fresh !== null ? $fresh : $account,
            'token'   => $token,
        );
    }

    public function logout(Request $request)
    {
        $account = $this->sessions->resolve($request);
        $this->sessions->destroy($request);

        if ($account !== null) {
            $this->logger->info('auth.logout', array('account_id' => $account->id()));
        }
    }

    /**
     * @return Account|null
     */
    public function current(Request $request)
    {
        return $this->sessions->resolve($request);
    }

    /**
     * @return Account
     * @throws ApiException
     */
    public function requireCurrent(Request $request)
    {
        $account = $this->sessions->resolve($request);
        if ($account === null) {
            throw ApiException::unauthorized();
        }
        return $account;
    }

    /**
     * 修改密码
     */
    public function changePassword(Account $account, $oldPassword, $newPassword, Request $request)
    {
        if (!password_verify((string) $oldPassword, $account->passwordHash())) {
            throw ApiException::validation('原密码不正确', array('field' => 'old_password'));
        }

        $this->assertPasswordStrength($newPassword);

        $this->accounts->update($account->id(), array(
            'password_hash' => password_hash((string) $newPassword, PASSWORD_DEFAULT),
        ));

        // 改密后踢掉其它会话，只保留当前这一个
        $token = $request->cookie($this->sessions->cookieName());
        $this->sessions->destroyAllForAccount($account->id());
        if ($token !== '') {
            $this->sessions->create($account->id(), $request);
        }

        $this->logger->info('auth.password_changed', array('account_id' => $account->id()));
    }

    /**
     * 密码强度底线：8 位以上，且不能全是同一种字符
     *
     * @throws ApiException
     */
    public function assertPasswordStrength($password)
    {
        $password = (string) $password;
        if (strlen($password) < 8) {
            throw ApiException::validation('密码至少需要 8 个字符', array('field' => 'password'));
        }
        if (strlen($password) > 72) {
            // bcrypt 只取前 72 字节，超长部分静默失效反而危险
            throw ApiException::validation('密码不能超过 72 个字符', array('field' => 'password'));
        }
        if (preg_match('/^[a-z]+$/i', $password) || preg_match('/^\d+$/', $password)) {
            throw ApiException::validation('密码不能是纯字母或纯数字', array('field' => 'password'));
        }
    }
}
