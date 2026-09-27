<?php

namespace W8\Passport\Identity;

use Throwable;
use W8\Passport\Contracts\SimpassVerifier;
use W8\Passport\Directory\CountryDirectory;
use W8\Passport\Directory\PlayerDirectory;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Request;
use W8\Passport\Support\Config;
use W8\Passport\Support\Database;
use W8\Passport\Support\Logger;
use W8\Passport\Verification\EmailCodeService;

/**
 * 通行证注册
 *
 * 注册必须同时提供并通过四项校验：
 *   ① 验证邮箱      —— 邮箱验证码（⚠ 发送接口 TODO）
 *   ② 游戏内玩家名  —— 权威接口实时查询，必须是真实存在的玩家（⚠ 查询接口 TODO）
 *   ③ 简幻通ID      —— 简幻通身份校验（⚠ 接口 TODO）
 *   ④ 验证码        —— 简幻通验证码
 *
 * 校验顺序刻意从"最可能失败、最贵"到"最便宜、一次性"：
 * 玩家 → 简幻通 → 邮箱验证码。
 * 邮箱验证码放最后，是为了不在前两项失败时白白烧掉一个验证码。
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
        Config $config,
        Logger $logger
    ) {
        $this->db = $db;
        $this->accounts = $accounts;
        $this->players = $players;
        $this->countries = $countries;
        $this->emailCodes = $emailCodes;
        $this->simpass = $simpass;
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
        $email      = strtolower(trim((string) $this->value($input, 'email')));
        $emailCode  = trim((string) $this->value($input, 'email_code'));
        $playerName = trim((string) $this->value($input, 'player_name'));
        $simpassUid = (int) $this->value($input, 'simpass_uid', 0);
        $simpassCode = trim((string) $this->value($input, 'simpass_code'));

        // ---------- ① 字段格式 ----------
        $this->assertUsername($username);
        $this->assertEmail($email);
        $this->assertPlayerName($playerName);
        $this->assertPassword($password);

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
        if ($this->accounts->findByEmail($email) !== null) {
            throw ApiException::conflict('该邮箱已被注册', array('field' => 'email'));
        }
        if ($this->accounts->findByPlayerName($playerName) !== null) {
            throw ApiException::conflict('该游戏内玩家名已被绑定', array('field' => 'player_name'));
        }
        if ($this->accounts->findBySimpassUid($simpassUid) !== null) {
            throw ApiException::conflict('该简幻通ID已被绑定', array('field' => 'simpass_uid'));
        }

        // ---------- ③ 游戏内玩家名：走权威接口实时校验 ----------
        $player = $this->resolvePlayer($playerName);

        // ---------- ④ 简幻通身份 ----------
        $identity = $this->resolveSimpass($simpassUid, $simpassCode, $playerName);

        // ---------- ⑤ 邮箱验证码（放最后，避免白白烧掉）----------
        $this->resolveEmail($email, $emailCode);

        // ---------- ⑥ 落库 ----------
        $accountId = $this->db->transaction(function () use ($username, $password, $email, $player, $identity) {
            return $this->accounts->create(array(
                'username'            => $username,
                'email'               => $email,
                'email_verified_at'   => date('Y-m-d H:i:s'),
                'password_hash'       => password_hash($password, PASSWORD_DEFAULT),
                'simpass_uid'         => $identity->uid(),
                'simpass_level'       => $identity->level(),
                'simpass_verified_at' => date('Y-m-d H:i:s'),
                'player_name'         => $player->name(),
                'player_id'           => $player->id(),
                'country_id'          => $player->countryId(),
                'player_synced_at'    => date('Y-m-d H:i:s'),
                'role'                => 'observer',
                'status'              => Account::STATUS_ACTIVE,
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
     * 邮箱验证码校验
     */
    private function resolveEmail($email, $emailCode)
    {
        if (!$this->verificationEnabled()) {
            $this->logger->warning('passport.verification_bypassed', array('step' => 'email'));
            return;
        }

        if ($emailCode === '') {
            throw ApiException::validation('请填写邮箱验证码', array('field' => 'email_code'));
        }

        $this->emailCodes->assertVerify($email, 'register', $emailCode);
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
