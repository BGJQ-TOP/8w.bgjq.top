<?php

namespace W8\Passport\Identity;

use Throwable;
use W8\Passport\Contracts\FanVerifyVerifier;
use W8\Passport\Contracts\SimpassVerifier;
use W8\Passport\Directory\CountryDirectory;
use W8\Passport\Directory\PlayerDirectory;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Request;
use W8\Passport\Support\Config;
use W8\Passport\Support\Database;
use W8\Passport\Support\Logger;
use W8\Passport\Verification\EmailCodeService;
use W8\Passport\Verification\FanVerifyIdentity;

/**
 * 通行证注册
 *
 * 注册必填（都要通过外部校验）：
 *   ① 游戏内玩家名  —— 权威接口实时查询，必须是真实存在的玩家（⚠ 查询接口 TODO）
 *   ② 简幻通ID      —— 简幻通身份校验（⚠ 接口 TODO）
 *   ③ 简幻通验证码
 *
 * 注册可选（用户自己决定绑不绑）：
 *   ④ 验证邮箱 + 邮箱验证码（⚠ 邮件接口 TODO）
 *   ⑤ FanVerify 账号ID + 验证码（⚠ 接口 TODO）
 *
 * 校验顺序刻意从"最可能失败、最贵"到"最便宜、一次性"：
 * 玩家 → 简幻通 → 邮箱 → FanVerify。
 * 邮箱验证码放后面，是为了不在前两项失败时白白烧掉一个验证码。
 */
final class RegistrationService
{
    /** @var Database */
    private $db;

    /** @var AccountRepository */
    private $accounts;

    /** @var PlayerDirectory */
    private $players;

    /** @var CountryDirectory */
    private $countries;

    /** @var EmailCodeService */
    private $emailCodes;

    /** @var SimpassVerifier */
    private $simpass;

    /** @var FanVerifyVerifier */
    private $fanVerify;

    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    public function __construct(
        Database $db,
        AccountRepository $accounts,
        PlayerDirectory $players,
        CountryDirectory $countries,
        EmailCodeService $emailCodes,
        SimpassVerifier $simpass,
        FanVerifyVerifier $fanVerify,
        Config $config,
        Logger $logger
    ) {
        $this->db = $db;
        $this->accounts = $accounts;
        $this->players = $players;
        $this->countries = $countries;
        $this->emailCodes = $emailCodes;
        $this->simpass = $simpass;
        $this->fanVerify = $fanVerify;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * 注册
     *
     * @param array<string,mixed> $input
     * @param Request $request
     * @return Account
     */
    public function register(array $input, Request $request)
    {
        $username   = trim((string) $this->value($input, 'username'));
        $password   = (string) $this->value($input, 'password');
        $playerName = trim((string) $this->value($input, 'player_name'));
        $simpassUid = (int) $this->value($input, 'simpass_uid', 0);
        $simpassCode = trim((string) $this->value($input, 'simpass_code'));

        // 可选绑定
        $email      = strtolower(trim((string) $this->value($input, 'email')));
        $emailCode  = trim((string) $this->value($input, 'email_code'));
        $fanverifyUid = (int) $this->value($input, 'fanverify_uid', 0);
        $fanverifyCode = trim((string) $this->value($input, 'fanverify_code'));

        // ---------- ① 字段格式 ----------
        $this->assertUsername($username);
        $this->assertPlayerName($playerName);
        $this->assertPassword($password);

        // 邮箱只在填了的时候才校验格式
        if ($email !== '') {
            $this->assertEmail($email);
        }

        if ($simpassUid <= 0) {
            throw ApiException::validation('请填写正确的简幻通ID', array('field' => 'simpass_uid'));
        }
        if ($simpassCode === '') {
            throw ApiException::validation('请填写简幻通验证码', array('field' => 'simpass_code'));
        }

        // ---------- ② 唯一性（先查，给出友好提示；真正的唯一性由唯一索引兜底）----------
        if ($this->accounts->findByUsername($username) !== null) {
            throw ApiException::conflict('该用户名已被注册', array('field' => 'username'));
        }
        if ($email !== '' && $this->accounts->findByEmail($email) !== null) {
            throw ApiException::conflict('该邮箱已被注册', array('field' => 'email'));
        }
        if ($this->accounts->findByPlayerName($playerName) !== null) {
            throw ApiException::conflict('该游戏内玩家名已被绑定', array('field' => 'player_name'));
        }
        if ($this->accounts->findBySimpassUid($simpassUid) !== null) {
            throw ApiException::conflict('该简幻通ID已被绑定', array('field' => 'simpass_uid'));
        }
        if ($fanverifyUid > 0 && $this->accounts->findByFanverifyUid($fanverifyUid) !== null) {
            throw ApiException::conflict('该 FanVerify 账号已被绑定', array('field' => 'fanverify_uid'));
        }

        // ---------- ③ 游戏内玩家名：走权威接口实时校验 ----------
        $player = $this->resolvePlayer($playerName);

        // ---------- ④ 简幻通身份（必填）----------
        $identity = $this->resolveSimpass($simpassUid, $simpassCode, $playerName);

        // ---------- ⑤ 邮箱验证码（可选）----------
        $emailBound = $this->resolveOptionalEmail($email, $emailCode);

        // ---------- ⑥ FanVerify（可选）----------
        $fanverifyIdentity = $this->resolveOptionalFanVerify($fanverifyUid, $fanverifyCode, $playerName);

        // ---------- ⑦ 落库 ----------
        $now = date('Y-m-d H:i:s');
        $accountId = $this->db->transaction(function () use (
            $username, $password, $email, $emailBound, $player, $identity, $fanverifyIdentity, $now
        ) {
            return $this->accounts->create(array(
                'username'              => $username,
                'password_hash'         => password_hash($password, PASSWORD_DEFAULT),

                // 可选绑定：未绑定时落 NULL，而不是空串
                'email'                 => $emailBound ? $email : null,
                'email_verified_at'     => $emailBound ? $now : null,
                'fanverify_uid'         => $fanverifyIdentity !== null ? $fanverifyIdentity->uid() : null,
                'fanverify_level'       => $fanverifyIdentity !== null ? $fanverifyIdentity->level() : null,
                'fanverify_tag'         => $fanverifyIdentity !== null ? $fanverifyIdentity->tag() : null,
                'fanverify_verified_at' => $fanverifyIdentity !== null ? $now : null,

                // 必填绑定
                'simpass_uid'           => $identity->uid(),
                'simpass_level'         => $identity->level(),
                'simpass_verified_at'   => $now,

                'player_name'           => $player->name(),
                'player_id'             => $player->id(),
                'country_id'            => $player->countryId(),
                'player_synced_at'      => $now,

                'role'                  => 'observer',
                'status'                => Account::STATUS_ACTIVE,
            ));
        });

        // ---------- ⑦ 邦国缓存（尽力而为，失败不影响注册结果）----------
        if ($player->hasCountry()) {
            $this->warmCountryCache($player->countryId(), $player->name());
        }

        $this->logger->info('passport.registered', array(
            'account_id'  => $accountId,
            'player_name' => $player->name(),
            'country_id'  => $player->countryId(),
            'ip'          => $request->ip(),
        ));

        $account = $this->accounts->find($accountId);
        if ($account === null) {
            throw ApiException::serverError('注册成功但读取账号失败，请尝试登录');
        }

        return $account;
    }

    /**
     * 权威接口校验玩家名
     *
     * @param string $playerName
     * @return \W8\Passport\Directory\PlayerProfile
     */
    private function resolvePlayer($playerName)
    {
        $profile = $this->players->find($playerName, true);

        if ($profile === null) {
            throw ApiException::validation(
                '游戏内不存在名为「' . $playerName . '」的玩家，请检查玩家名是否正确',
                array('field' => 'player_name')
            );
        }

        // 权威接口返回的玩家名与用户输入不一致时，以权威为准，并告知用户
        if (strcasecmp($profile->name(), $playerName) !== 0) {
            throw ApiException::validation(
                '玩家名应为「' . $profile->name() . '」，请核对后重试',
                array('field' => 'player_name')
            );
        }

        return $profile;
    }

    /**
     * 简幻通校验
     *
     * @return \W8\Passport\Verification\SimpassIdentity
     */
    private function resolveSimpass($simpassUid, $simpassCode, $playerName)
    {
        if (!$this->verificationEnabled()) {
            $this->logger->warning('passport.verification_bypassed', array('step' => 'simpass'));
            return new \W8\Passport\Verification\SimpassIdentity($simpassUid, null, null);
        }

        return $this->simpass->verify($simpassUid, $simpassCode, $playerName);
    }

    /**
     * 邮箱验证码校验（可选绑定）
     *
     * 用户没填邮箱就跳过，账号的 email 落 NULL。
     * 填了邮箱则验证码必填 —— 否则等于绑了一个未验证的邮箱。
     *
     * @param string $email
     * @param string $emailCode
     * @return bool 是否绑定了邮箱
     */
    private function resolveOptionalEmail($email, $emailCode)
    {
        if ($email === '') {
            return false;
        }

        if ($emailCode === '') {
            throw ApiException::validation('填写了邮箱就必须填写邮箱验证码', array('field' => 'email_code'));
        }

        if (!$this->verificationEnabled()) {
            $this->logger->warning('passport.verification_bypassed', array('step' => 'email'));
            return true;
        }

        $this->emailCodes->assertVerify($email, 'register', $emailCode);

        return true;
    }

    /**
     * FanVerify 校验（可选绑定）
     *
     * 代码已接入 fanverify.cn openAPI；只有 FANVERIFY_ACCESS_TOKEN 没配时才抛 501。
     * 注意这是**可选**绑定，所以未配置不会挡住注册 —— 只要用户不填就行。
     *
     * @param int $fanverifyUid
     * @param string $fanverifyCode
     * @param string $playerName
     * @return FanVerifyIdentity|null
     */
    private function resolveOptionalFanVerify($fanverifyUid, $fanverifyCode, $playerName)
    {
        if ($fanverifyUid <= 0 && $fanverifyCode === '') {
            return null;
        }

        if ($fanverifyUid <= 0) {
            throw ApiException::validation('请填写正确的 FanVerify 账号ID', array('field' => 'fanverify_uid'));
        }
        if ($fanverifyCode === '') {
            throw ApiException::validation('请填写 FanVerify 动态验证码', array('field' => 'fanverify_code'));
        }

        if (!$this->verificationEnabled()) {
            $this->logger->warning('passport.verification_bypassed', array('step' => 'fanverify'));
            return new FanVerifyIdentity($fanverifyUid, null, null);
        }

        return $this->fanVerify->verify($fanverifyUid, $fanverifyCode, $playerName);
    }

    /**
     * 是否执行真实验证
     *
     * 默认永远是 true。只有当 PASSPORT_DEBUG=1 **且**
     * PASSPORT_DEV_BYPASS_VERIFICATION=1 时才放行，且每次都会记 WARNING 日志。
     * 这是给本地联调用的，生产环境两个开关都必须保持关闭。
     */
    private function verificationEnabled()
    {
        $bypass = $this->config->getBool('PASSPORT_DEBUG', false)
            && $this->config->getBool('PASSPORT_DEV_BYPASS_VERIFICATION', false);

        return !$bypass;
    }

    /**
     * 预热邦国缓存：权威接口可用就同步，不可用就至少落一行占位
     */
    private function warmCountryCache($countryId, $playerName)
    {
        try {
            $this->countries->find((int) $countryId, true);
            return;
        } catch (Throwable $e) {
            $this->logger->info('passport.country_warmup_skipped', array(
                'country_id' => $countryId,
                'player'     => $playerName,
                'reason'     => $e->getMessage(),
            ));
        }

        try {
            $this->countries->touch((int) $countryId, null);
        } catch (Throwable $e) {
            $this->logger->warning('passport.country_touch_failed', array('message' => $e->getMessage()));
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function value(array $input, $key, $default = '')
    {
        return array_key_exists($key, $input) && !is_array($input[$key]) ? $input[$key] : $default;
    }

    private function assertUsername($username)
    {
        if ($username === '') {
            throw ApiException::validation('请填写用户名', array('field' => 'username'));
        }
        if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $username)) {
            throw ApiException::validation(
                '用户名需为 3-32 位，仅限字母、数字、下划线和短横线',
                array('field' => 'username')
            );
        }
    }

    private function assertEmail($email)
    {
        if ($email === '') {
            throw ApiException::validation('请填写验证邮箱', array('field' => 'email'));
        }
        if (strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::validation('邮箱格式不正确', array('field' => 'email'));
        }
    }

    private function assertPlayerName($playerName)
    {
        if ($playerName === '') {
            throw ApiException::validation('请填写游戏内玩家名', array('field' => 'player_name'));
        }
        if (strlen($playerName) > 32) {
            throw ApiException::validation('游戏内玩家名过长', array('field' => 'player_name'));
        }
        // 真正的存在性由权威接口判定，这里只挡明显非法的字符
        if (preg_match('/[<>\x00-\x1F]/', $playerName)) {
            throw ApiException::validation('游戏内玩家名包含非法字符', array('field' => 'player_name'));
        }
    }

    private function assertPassword($password)
    {
        if (strlen((string) $password) < 8) {
            throw ApiException::validation('密码至少需要 8 个字符', array('field' => 'password'));
        }
        if (strlen((string) $password) > 72) {
            throw ApiException::validation('密码不能超过 72 个字符', array('field' => 'password'));
        }
        if (preg_match('/^[a-z]+$/i', (string) $password) || preg_match('/^\d+$/', (string) $password)) {
            throw ApiException::validation('密码不能是纯字母或纯数字', array('field' => 'password'));
        }
    }
}
