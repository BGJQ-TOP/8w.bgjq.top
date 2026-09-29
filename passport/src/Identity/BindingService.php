<?php

namespace W8\Passport\Identity;

use W8\Passport\Contracts\FanVerifyVerifier;
use W8\Passport\Http\ApiException;
use W8\Passport\Support\Config;
use W8\Passport\Support\Logger;
use W8\Passport\Verification\EmailCodeService;
use W8\Passport\Verification\FanVerifyClient;
use W8\Passport\Verification\FanVerifyIdentity;

/**
 * 绑定管理
 *
 * 通行证的绑定分两类：
 *
 *   必填且不可解绑
 *     · 游戏内玩家名 —— 权威身份主键
 *     · 简幻通ID     —— 注册时校验通过，同时是默认的账号找回通道
 *
 *   可选绑定（用户自己决定绑不绑，随时可解绑）
 *     · 验证邮箱     —— ⚠ 邮件接口 TODO
 *     · FanVerify    —— ⚠ 接口 TODO
 *
 * 安全约定：绑定与解绑都会改变账号的找回途径，因此**都要求提供当前密码**。
 * 否则一个被盗用的登录态就能挂上攻击者的邮箱，再借找回流程夺走账号。
 */
final class BindingService
{
    /** 绑定类型 */
    const TYPE_EMAIL = 'email';
    const TYPE_FANVERIFY = 'fanverify';

    /** @var AccountRepository */
    private $accounts;

    /** @var EmailCodeService */
    private $emailCodes;

    /** @var FanVerifyVerifier */
    private $fanVerify;

    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    public function __construct(
        AccountRepository $accounts,
        EmailCodeService $emailCodes,
        FanVerifyVerifier $fanVerify,
        Config $config,
        Logger $logger
    ) {
        $this->accounts = $accounts;
        $this->emailCodes = $emailCodes;
        $this->fanVerify = $fanVerify;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * 当前账号的绑定全景，供前端渲染绑定卡片
     *
     * @param Account $account
     * @return array<string,mixed>
     */
    public function describe(Account $account)
    {
        return array(
            'player' => array(
                'label'     => '游戏内玩家名',
                'bound'     => true,
                'required'  => true,
                'bindable'  => false,
                'value'     => $account->playerName(),
                'detail'    => $account->playerId() !== null ? ('玩家ID ' . $account->playerId()) : null,
            ),
            'simpass' => array(
                'label'     => '简幻通',
                'bound'     => $account->simpassUid() !== null,
                'required'  => true,
                'bindable'  => false,
                'value'     => $account->simpassUid(),
                'detail'    => $account->simpassLevel() !== null ? ('等级 ' . $account->simpassLevel()) : null,
            ),
            'email' => array(
                'label'     => '验证邮箱',
                'bound'     => $account->hasEmail(),
                'required'  => false,
                'bindable'  => true,
                // 接口未接入时前端直接禁用按钮并说明原因，而不是让用户白点一次
                'available' => $this->emailCodes->isDeliverable(),
                'value'     => $account->email(),
                'detail'    => $account->isEmailVerified() ? '已验证' : null,
            ),
            'fanverify' => array(
                'label'     => 'FanVerify',
                'bound'     => $account->hasFanVerify(),
                'required'  => false,
                'bindable'  => true,
                'available' => $this->fanVerify->isConfigured(),
                'value'     => $account->fanverifyUid(),
                'detail'    => $this->fanVerifyDetail($account),
                'tag'       => $account->fanverifyTag(),
            ),
        );
    }

    // ========================================================================
    //  验证邮箱
    // ========================================================================

    /**
     * 绑定验证邮箱
     *
     * @param Account $account
     * @param string $email
     * @param string $code    邮箱验证码（scene = bind）
     * @param string $password 当前密码
     * @return Account 刷新后的账号
     */
    public function bindEmail(Account $account, $email, $code, $password)
    {
        $this->assertPassword($account, $password);

        $email = strtolower(trim((string) $email));
        if ($email === '' || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::validation('邮箱格式不正确', array('field' => 'email'));
        }

        if ($account->hasEmail() && strcasecmp($account->email(), $email) === 0) {
            throw ApiException::conflict('该邮箱已经绑定在当前通行证上', array('field' => 'email'));
        }

        $owner = $this->accounts->findByEmail($email);
        if ($owner !== null && $owner->id() !== $account->id()) {
            throw ApiException::conflict('该邮箱已被其它通行证绑定', array('field' => 'email'));
        }

        if (trim((string) $code) === '') {
            throw ApiException::validation('请填写邮箱验证码', array('field' => 'email_code'));
        }

        // 验证码校验失败会直接抛 422，不会写库
        $this->emailCodes->assertVerify($email, 'bind', $code);

        $this->accounts->update($account->id(), array(
            'email'             => $email,
            'email_verified_at' => date('Y-m-d H:i:s'),
        ));

        $this->logger->info('binding.email_bound', array(
            'account_id' => $account->id(),
            'replaced'   => $account->hasEmail(),
        ));

        return $this->reload($account);
    }

    /**
     * 解绑邮箱
     *
     * @param Account $account
     * @param string $password
     * @return Account
     */
    public function unbindEmail(Account $account, $password)
    {
        $this->assertPassword($account, $password);

        if (!$account->hasEmail()) {
            throw ApiException::conflict('当前通行证没有绑定邮箱');
        }

        $this->accounts->update($account->id(), array(
            'email'             => null,
            'email_verified_at' => null,
        ));

        $this->logger->info('binding.email_unbound', array('account_id' => $account->id()));

        return $this->reload($account);
    }

    // ========================================================================
    //  FanVerify
    // ========================================================================

    /**
     * 绑定 FanVerify 账号（手填：账号ID + 动态验证码）
     *
     * @param Account $account
     * @param int $fanverifyUid
     * @param string $code FanVerify 小程序里的动态验证码
     * @param string $password
     * @return Account
     */
    public function bindFanVerify(Account $account, $fanverifyUid, $code, $password)
    {
        $this->assertPassword($account, $password);

        $fanverifyUid = (int) $fanverifyUid;
        if ($fanverifyUid <= 0) {
            throw ApiException::validation('请填写正确的 FanVerify 账号ID', array('field' => 'fanverify_uid'));
        }
        if (trim((string) $code) === '') {
            throw ApiException::validation('请填写 FanVerify 动态验证码', array('field' => 'fanverify_code'));
        }

        $this->assertFanVerifyNotTaken($account, $fanverifyUid);

        // 走外部验证；失败会抛 422/500/501，不会写库
        $identity = $this->fanVerify->verify($fanverifyUid, $code, $account->playerName());

        return $this->attachFanVerify($account, $identity);
    }

    /**
     * 绑定 FanVerify 账号（扫码：身份已由 OTP 流程拿到）
     *
     * 扫码流程里"这个人是谁"是 FanVerify 侧确认的（用户在小程序里点了同意），
     * 我们只是把结果落库，因此这里只校验密码与唯一性。
     *
     * @param Account $account
     * @param FanVerifyIdentity $identity
     * @param string $password
     * @return Account
     */
    public function bindFanVerifyWithIdentity(Account $account, FanVerifyIdentity $identity, $password)
    {
        $this->assertPassword($account, $password);

        $this->assertFanVerifyNotTaken($account, $identity->uid());

        return $this->attachFanVerify($account, $identity);
    }

    /**
     * 绑定 FanVerify 账号（扫码：OTP 已被用户在小程序里确认）
     *
     * 扫码流程里"这个人是谁"是 FanVerify 侧确认的，我们只是把结果落库，
     * 但**仍然要求当前密码**，并在落库前重新轮询一次 OTP
     * —— 不轻信前端"已经确认了"的说法。
     *
     * @param Account $account
     * @param string $otp
     * @param string $password
     * @return Account
     */
    public function bindFanVerifyByOtp(Account $account, $otp, $password)
    {
        $this->assertPassword($account, $password);

        $otp = trim((string) $otp);
        if ($otp === '') {
            throw ApiException::validation('缺少 OTP，请重新扫码', array('field' => 'otp'));
        }

        $result = $this->fanVerify->pollOtp($otp);

        if ($result['status'] !== FanVerifyClient::STATUS_OK || $result['identity'] === null) {
            throw ApiException::validation(
                '扫码尚未确认或已超时，请重新扫码',
                array('field' => 'otp', 'status' => $result['status'])
            );
        }

        $this->assertFanVerifyNotTaken($account, $result['identity']->uid());

        return $this->attachFanVerify($account, $result['identity']);
    }

    /**
     * 落库
     *
     * @return Account
     */
    private function attachFanVerify(Account $account, FanVerifyIdentity $identity)
    {
        $required = $this->requiredFanVerifyLevel();
        if ($required > 0 && ($identity->level() === null || $identity->level() < $required)) {
            throw ApiException::validation(
                '该 FanVerify 账号等级不足（当前 '
                . ($identity->level() === null ? '未知' : $identity->level())
                . '，要求 ' . $required . '）',
                array('field' => 'fanverify_uid')
            );
        }

        $this->accounts->update($account->id(), array(
            'fanverify_uid'         => $identity->uid(),
            'fanverify_level'       => $identity->level(),
            'fanverify_tag'         => $identity->tag(),
            'fanverify_verified_at' => date('Y-m-d H:i:s'),
        ));

        $this->logger->info('binding.fanverify_bound', array(
            'account_id' => $account->id(),
            'fanverify_uid' => $identity->uid(),
            'level' => $identity->level(),
            'has_tag' => $identity->hasTag(),
            'replaced' => $account->hasFanVerify(),
        ));

        return $this->reload($account);
    }

    /**
     * 同一个 FanVerify 账号不能被两个通行证绑定
     *
     * @throws ApiException
     */
    private function assertFanVerifyNotTaken(Account $account, $fanverifyUid)
    {
        if ($account->fanverifyUid() === (int) $fanverifyUid) {
            throw ApiException::conflict('该 FanVerify 账号已经绑定在当前通行证上', array('field' => 'fanverify_uid'));
        }

        $owner = $this->accounts->findByFanverifyUid((int) $fanverifyUid);
        if ($owner !== null && $owner->id() !== $account->id()) {
            throw ApiException::conflict('该 FanVerify 账号已被其它通行证绑定', array('field' => 'fanverify_uid'));
        }
    }

    /**
     * 解绑 FanVerify 账号
     *
     * @param Account $account
     * @param string $password
     * @return Account
     */
    public function unbindFanVerify(Account $account, $password)
    {
        $this->assertPassword($account, $password);

        if (!$account->hasFanVerify()) {
            throw ApiException::conflict('当前通行证没有绑定 FanVerify 账号');
        }

        $this->accounts->update($account->id(), array(
            'fanverify_uid'         => null,
            'fanverify_level'       => null,
            'fanverify_tag'         => null,
            'fanverify_verified_at' => null,
        ));

        $this->logger->info('binding.fanverify_unbound', array('account_id' => $account->id()));

        return $this->reload($account);
    }

    // ========================================================================

    /**
     * 敏感变更必须验当前密码
     *
     * @throws ApiException
     */
    private function assertPassword(Account $account, $password)
    {
        if ((string) $password === '') {
            throw ApiException::validation('请填写当前密码', array('field' => 'password'));
        }

        if (!password_verify((string) $password, $account->passwordHash())) {
            $this->logger->info('binding.password_mismatch', array('account_id' => $account->id()));
            throw ApiException::validation('当前密码不正确', array('field' => 'password'));
        }
    }

    /**
     * FanVerify 绑定的补充说明（等级 / 风险标签）
     *
     * @return string|null
     */
    private function fanVerifyDetail(Account $account)
    {
        if (!$account->hasFanVerify()) {
            return null;
        }

        $parts = array();

        if ($account->fanverifyLevel() !== null) {
            $parts[] = '等级 ' . $account->fanverifyLevel();
        }
        if ($account->fanverifyTag() !== null) {
            $parts[] = '风险标签：' . $account->fanverifyTag();
        }

        return $parts === array() ? '已验证' : implode(' · ', $parts);
    }

    /**
     * 本站在 FanVerify 等级上的额外门槛（0 = 不额外限制）
     */
    private function requiredFanVerifyLevel()
    {
        return max(0, $this->config->getInt('FANVERIFY_REQUIRED_LEVEL', 0));
    }

    /**
     * 重新读取账号，保证返回给前端的是最新绑定状态
     *
     * @return Account
     */
    private function reload(Account $account)
    {
        $fresh = $this->accounts->find($account->id());
        return $fresh !== null ? $fresh : $account;
    }
}
