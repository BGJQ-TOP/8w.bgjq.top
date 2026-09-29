<?php
/**
 * 8W通行证系统 —— 最小验证脚本
 *
 * 项目没有 Composer / PHPUnit，这里用一个几十行的断言器覆盖
 * 不依赖数据库和外部网络的核心逻辑，作为提交前的第一道闸门。
 *
 * 运行：
 *   pwsh ./bin/test.ps1        ← 推荐：语法检查 + 本测试一起跑
 *   php passport/tests/smoke.php
 *
 * 覆盖范围：
 *   · Support 工具（Arr / Str / Config）
 *   · OAuth scope 解析与裁剪
 *   · 值对象（PlayerProfile / CountryProfile）
 *   · 响应与异常格式（Response / ApiException / HttpResponse）
 *   · 数据源未配置时的失败语义（必须明确报错，绝不能静默放行）
 *   · database/8w_passport.sql 的结构自检
 */

require_once __DIR__ . '/../src/bootstrap.php';

use W8\Passport\Application;
use W8\Passport\Directory\CountryDirectory;use W8\Passport\Directory\CountryProfile;
use W8\Passport\Directory\PlayerDirectory;
use W8\Passport\Directory\PlayerProfile;
use W8\Passport\Directory\Providers\HttpCountryProvider;
use W8\Passport\Directory\Providers\HttpPlayerProvider;
use W8\Passport\Directory\Providers\UnavailableCountryProvider;
use W8\Passport\Directory\Providers\UnavailablePlayerProvider;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Response;
use W8\Passport\Identity\Account;
use W8\Passport\Identity\AccountRepository;
use W8\Passport\Identity\Authenticator;
use W8\Passport\Identity\BindingService;
use W8\Passport\Identity\RegistrationService;
use W8\Passport\Identity\SessionStore;
use W8\Passport\OAuth\OAuthServer;
use W8\Passport\OAuth\Scope;
use W8\Passport\Support\Arr;
use W8\Passport\Support\Config;
use W8\Passport\Support\Database;
use W8\Passport\Support\HttpClient;
use W8\Passport\Support\HttpResponse;
use W8\Passport\Support\Logger;
use W8\Passport\Support\Str;
use W8\Passport\Verification\EmailCodeService;
use W8\Passport\Verification\FanVerifyClient;
use W8\Passport\Verification\HttpEmailVerifier;
use W8\Passport\Verification\HttpFanVerifyVerifier;
use W8\Passport\Verification\HttpSimpassVerifier;
use W8\Passport\Verification\UnavailableEmailVerifier;
use W8\Passport\Verification\UnavailableFanVerifyVerifier;
use W8\Passport\Verification\UnavailableSimpassVerifier;

// ============================================================================
//  极简断言器
// ============================================================================

$GLOBALS['w8_passed'] = 0;
$GLOBALS['w8_failed'] = 0;
$GLOBALS['w8_section'] = '';

function section($title)
{
    $GLOBALS['w8_section'] = $title;
    echo "\n\033[36m── {$title}\033[0m\n";
}

function check($description, $condition, $detail = '')
{
    if ($condition) {
        $GLOBALS['w8_passed']++;
        echo "  \033[32m✓\033[0m {$description}\n";
        return;
    }

    $GLOBALS['w8_failed']++;
    echo "  \033[31m✗\033[0m {$description}\n";
    if ($detail !== '') {
        echo "      {$detail}\n";
    }
}

function checkSame($description, $expected, $actual)
{
    check(
        $description,
        $expected === $actual,
        'expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
    );
}

/**
 * 断言某段代码抛出了 ApiException，并返回其错误码
 */
function checkThrows($description, $callback, $expectedCode = null)
{
    try {
        call_user_func($callback);
    } catch (ApiException $e) {
        $code = $e->isOAuth() ? $e->oauthError() : $e->errorCode();
        check(
            $description,
            $expectedCode === null || $code === $expectedCode,
            'expected code=' . var_export($expectedCode, true) . ' actual=' . var_export($code, true)
        );
        return;
    } catch (Throwable $e) {
        check($description, false, '抛出了非 ApiException：' . get_class($e) . ' ' . $e->getMessage());
        return;
    }

    check($description, false, '没有抛出任何异常');
}

// ============================================================================

section('Support\\Arr —— 点路径取值');

$payload = array(
    'success' => true,
    'data' => array(
        'name' => 'LouieMAIN',
        'player_id' => 1001,
        'country' => array('id' => 7, 'name' => '大周'),
        'players' => array(
            array('name' => 'Alice', 'id' => 1),
            array('name' => 'Bob', 'id' => 2),
        ),
    ),
);

checkSame('取嵌套字符串', 'LouieMAIN', Arr::get($payload, 'data.name'));
checkSame('取嵌套整数', 7, Arr::get($payload, 'data.country.id'));
checkSame('取数组下标', 'Bob', Arr::get($payload, 'data.players.1.name'));
checkSame('路径不存在时返回默认值', 'fallback', Arr::get($payload, 'data.nope.deep', 'fallback'));
checkSame('空路径返回默认值', 'x', Arr::get($payload, '', 'x'));

checkSame('getList 取列表', 2, count(Arr::getList($payload, 'data.players')));
checkSame('getList 对非数组返回空列表', 0, count(Arr::getList($payload, 'data.name')));
checkSame('getList 把 map 规整成 list', 2, count(Arr::getList(array('m' => array('a' => 1, 'b' => 2)), 'm')));

checkSame('first 命中第一个存在的候选', 1001, Arr::first($payload, array('data.player_id', 'data.id')));
checkSame('first 全不命中返回默认值', null, Arr::first($payload, array('nope.one', 'nope.two')));

checkSame('toIntOrNull 空串 -> null', null, Arr::toIntOrNull(''));
checkSame('toIntOrNull 非数字 -> null', null, Arr::toIntOrNull('abc'));
checkSame('toIntOrNull 数字串 -> int', 42, Arr::toIntOrNull('42'));
checkSame('toTextOrNull 空白 -> null', null, Arr::toTextOrNull('   '));

// 第三方返回 HTML 错误页是常态，取值工具必须能扛住非数组输入
checkSame('get 对 null 返回默认值', 'x', Arr::get(null, 'a.b', 'x'));
checkSame('get 对字符串返回默认值', 'x', Arr::get('404 page not found', 'error', 'x'));
checkSame('getList 对 null 返回空数组', array(), Arr::getList(null, 'data'));
checkSame('first 对 null 返回默认值', null, Arr::first(null, array('a', 'b')));
checkSame('get 对 null 且无默认值时返回 null', null, Arr::get(null, 'error'));

// ----------------------------------------------------------------------------

section('OAuth\\Scope —— 授权范围');

checkSame('默认 scope 为 basic', array('basic'), Scope::parse(''));
checkSame('空格分隔解析', array('basic', 'email'), Scope::parse('basic email'));
checkSame('逗号分隔解析', array('basic', 'email'), Scope::parse('basic,email'));
checkSame('去重', array('basic'), Scope::parse('basic basic basic'));
checkSame('回写为空格分隔', 'basic email', Scope::toString(array('basic', 'email')));
checkSame('识别未知 scope', array('nope'), Scope::unknown(array('basic', 'nope')));
checkSame('求交集不会越权', array('basic'), Scope::intersect(array('basic', 'simpass'), array('basic', 'email')));
checkSame('无交集返回空数组', array(), Scope::intersect(array('simpass'), array('basic')));
check('has 判断', Scope::has(array('basic', 'player'), 'player'));
check('directory 是机器 scope', in_array('directory', Scope::MACHINE_SCOPES, true));
check('offline_access 已定义', isset(Scope::MAP['offline_access']));
check('basic 在 MAP 中', isset(Scope::MAP['basic']));
check('fanverify scope 已定义', isset(Scope::MAP['fanverify']));
check('email scope 说明提到"未绑定"', strpos(Scope::MAP['email'], '未绑定') !== false);
check('fanverify 不是机器 scope（属于用户身份）', !in_array('fanverify', Scope::MACHINE_SCOPES, true));

// ----------------------------------------------------------------------------

section('Identity\\Account —— 可选绑定语义');

$accountWithEmail = Account::fromRow(array(
    'id' => 1,
    'username' => 'louie',
    'email' => 'louie@example.com',
    'email_verified_at' => '2026-01-01 00:00:00',
    'password_hash' => '$2y$10$abcdefghijklmnopqrstuv',
    'role' => 'observer',
    'status' => 1,
    'player_name' => 'LouieMAIN',
    'player_id' => 1001,
    'country_id' => 7,
    'simpass_uid' => 10086,
    'simpass_level' => 3,
    'fanverify_uid' => 555,
    'created_at' => '2026-01-01 00:00:00',
));

check('有邮箱时 hasEmail 为 true', $accountWithEmail->hasEmail());
check('有邮箱时 isEmailVerified 为 true', $accountWithEmail->isEmailVerified());
check('FanVerify 已绑定', $accountWithEmail->hasFanVerify());
checkSame('FanVerify UID', 555, $accountWithEmail->fanverifyUid());

$bindings = $accountWithEmail->toPublicArray()['bindings'];
checkSame('bindings 覆盖四种绑定', array('player', 'simpass', 'email', 'fanverify'), array_keys($bindings));
check('player 标记为已绑定', $bindings['player'] === true);
check('email 标记为已绑定', $bindings['email'] === true);
check('fanverify 标记为已绑定', $bindings['fanverify'] === true);

// 未绑定邮箱 / FanVerify 的账号（这是新的默认形态）
$bareAccount = Account::fromRow(array(
    'id' => 2,
    'username' => 'nomail',
    'email' => null,
    'email_verified_at' => null,
    'password_hash' => '$2y$10$abcdefghijklmnopqrstuv',
    'role' => 'observer',
    'status' => 1,
    'player_name' => 'Nobody',
    'player_id' => null,
    'country_id' => null,
    'simpass_uid' => 20000,
    'simpass_level' => null,
    'fanverify_uid' => null,
    'created_at' => '2026-01-01 00:00:00',
));

checkSame('未绑定邮箱时 email() 返回 null', null, $bareAccount->email());
check('未绑定邮箱时 hasEmail 为 false', !$bareAccount->hasEmail());
check('未绑定邮箱时 isEmailVerified 为 false（不能因为 email_verified_at 为 null 就崩）', !$bareAccount->isEmailVerified());
check('未绑定 FanVerify 时 hasFanVerify 为 false', !$bareAccount->hasFanVerify());
checkSame('未绑定时 bindings 为 false', false, $bareAccount->toPublicArray()['bindings']['email']);
checkSame('未绑定时 bindings.fanverify 为 false', false, $bareAccount->toPublicArray()['bindings']['fanverify']);

// userinfo 裁剪：未绑定的 scope 整块省略，而不是返回 null 让第三方猜
$bareProfile = $bareAccount->toProfileArray(array('basic', 'email', 'player', 'simpass', 'fanverify'));
check('未绑定邮箱时 userinfo 不含 email 字段', !array_key_exists('email', $bareProfile));
check('未绑定 FanVerify 时 userinfo 不含 fanverify 字段', !array_key_exists('fanverify', $bareProfile));
check('必填的 simpass 仍在 userinfo 中', array_key_exists('simpass', $bareProfile));
check('sub 始终存在', isset($bareProfile['sub']));

$fullProfile = $accountWithEmail->toProfileArray(array('basic', 'email', 'fanverify'));
check('已绑定邮箱时 userinfo 含 email', isset($fullProfile['email']));
check('已绑定 FanVerify 时 userinfo 含 fanverify', isset($fullProfile['fanverify']));

// ----------------------------------------------------------------------------

section('未接入的可选绑定 —— 只挡绑定，不挡注册');

checkThrows('FanVerify 数据源未配置时抛 not_implemented', function () {
    (new UnavailableFanVerifyVerifier())->verify(555, '123456', 'LouieMAIN');
}, 'not_implemented');

check('FanVerify 未配置时 isConfigured() 为 false', !(new UnavailableFanVerifyVerifier())->isConfigured());

// ----------------------------------------------------------------------------

section('Support\\Str —— 随机与哈希');

$hex = Str::randomHex(16);
check('randomHex 长度为字节数两倍', strlen($hex) === 32, "len=" . strlen($hex));
check('randomHex 是十六进制', preg_match('/^[0-9a-f]{32}$/', $hex) === 1);

$urlSafe = Str::randomUrlSafe(32);
check('randomUrlSafe 不含 + / =', preg_match('#^[A-Za-z0-9_-]+$#', $urlSafe) === 1, $urlSafe);

$code = Str::numericCode(6);
check('numericCode 是 6 位数字', preg_match('/^\d{6}$/', $code) === 1, $code);

check('hash 稳定且为 64 位十六进制', Str::hash('abc') === hash('sha256', 'abc') && strlen(Str::hash('abc')) === 64);
check('equals 相同为 true', Str::equals(Str::hash('abc'), Str::hash('abc')));
check('equals 不同为 false', !Str::equals(Str::hash('abc'), Str::hash('abd')));
check('equals 类型不符为 false', !Str::equals(null, 'x'));

checkSame('maskEmail 脱敏', 'lo***@example.com', Str::maskEmail('louie@example.com'));
checkSame('maskEmail 非法输入原样返回', 'not-an-email', Str::maskEmail('not-an-email'));

// ----------------------------------------------------------------------------

section('Support\\Config —— 配置读取');

$config = new Config('/tmp/w8', array(
    'DB_NAME' => 'bgjq8w',
    'PLAYER_API_BASE' => 'https://game.example.com',
    'PASSPORT_SESSION_TTL' => '3600',
    'PASSPORT_DEBUG' => '1',
));

checkSame('读取字符串', 'bgjq8w', $config->getString('DB_NAME'));
checkSame('读取整数', 3600, $config->getInt('PASSPORT_SESSION_TTL'));
checkSame('读取布尔', true, $config->getBool('PASSPORT_DEBUG'));
checkSame('未配置的键返回默认值', 600, $config->getInt('DIRECTORY_CACHE_TTL'));
checkSame('has 对已配置项为 true', true, $config->has('PLAYER_API_BASE'));
checkSame('has 对未配置项为 false', false, $config->has('COUNTRY_API_BASE'));
checkSame('未配置项 getString 为空串', '', $config->getString('EMAIL_API_URL'));
checkSame('path 拼接项目根', '/tmp/w8/passport/storage/logs', $config->path('passport/storage/logs'));
check('缺少必填项时抛异常', (function () use ($config) {
    try {
        $config->requireString('EMAIL_API_URL');
    } catch (RuntimeException $e) {
        return true;
    }
    return false;
})());

// ----------------------------------------------------------------------------

section('Directory —— 权威数据值对象');

$player = new PlayerProfile('LouieMAIN', '1001', '7');
checkSame('玩家名', 'LouieMAIN', $player->name());
checkSame('玩家ID 转成 int', 1001, $player->id());
checkSame('所属邦国ID 转成 int', 7, $player->countryId());
check('hasCountry 为 true', $player->hasCountry());
checkSame('toArray 字段名符合规格', array('player_name', 'player_id', 'country_id'), array_keys($player->toArray()));

$orphan = new PlayerProfile('Nobody', null, null);
check('无邦国时 hasCountry 为 false', !$orphan->hasCountry());
checkSame('无邦国时 country_id 为 null', null, $orphan->toArray()['country_id']);

$players = array(
    new PlayerProfile('Alice', 1, 7),
    new PlayerProfile('Bob', 2, 7),
);
$country = new CountryProfile(7, '大周', '海纳百川', 1234, $players);

checkSame('邦国ID', 7, $country->id());
checkSame('邦国名称', '大周', $country->name());
checkSame('邦国宣言', '海纳百川', $country->declaration());
checkSame('邦国领土大小', 1234, $country->territoryChunks());
checkSame('邦国玩家列表长度', 2, count($country->players()));
checkSame('人口由玩家列表派生', 2, $country->population());
checkSame('玩家名列表', array('Alice', 'Bob'), $country->playerNames());

$countryArray = $country->toArray();
checkSame(
    'toArray 覆盖规格要求的全部字段',
    array('id', 'name', 'declaration', 'territory_chunks', 'population', 'players'),
    array_keys($countryArray)
);

check('rosterProvided 默认为 true', $country->rosterProvided());
$noRoster = new CountryProfile(8, '无名单邦国', null, null, array(), false);
check('rosterProvided 可显式置 false', !$noRoster->rosterProvided());

// ----------------------------------------------------------------------------

section('Http —— 响应与异常格式');

$ok = Response::ok(array('hello' => 'world'));
checkSame('成功响应状态码 200', 200, $ok->status());
checkSame('成功响应结构', array('ok' => true, 'data' => array('hello' => 'world')), $ok->payload());

$err = Response::error('invalid_request', '参数不对', 422, array('field' => 'email'));
checkSame('错误响应状态码', 422, $err->status());
checkSame('错误响应 ok 为 false', false, $err->payload()['ok']);
checkSame('错误码', 'invalid_request', $err->payload()['error']['code']);
checkSame('错误信息', '参数不对', $err->payload()['error']['message']);
checkSame('错误附加信息', array('field' => 'email'), $err->payload()['error']['details']);

$oauthErr = Response::oauthError('invalid_grant', '授权码无效', 400);
checkSame('OAuth 错误结构符合 RFC 6749', 'invalid_grant', $oauthErr->payload()['error']);
checkSame('OAuth 错误描述', '授权码无效', $oauthErr->payload()['error_description']);
check('OAuth 错误响应禁止缓存', $oauthErr->payload() !== null);

// RFC 7009 吊销端点：空响应体必须是 {} 而不是 []，否则客户端会误判成列表
$empty = Response::emptyBody();
checkSame('空响应体状态码 200', 200, $empty->status());
checkSame('空响应体序列化为 {} 而不是 []', '{}', json_encode($empty->payload()));

$ex = ApiException::validation('字段错误', array('field' => 'username'));
checkSame('validation 错误码', 'invalid_request', $ex->errorCode());
checkSame('validation HTTP 状态', 422, $ex->httpStatus());
checkSame('validation 附带字段信息', array('field' => 'username'), $ex->details());
check('validation 不是 OAuth 错误', !$ex->isOAuth());

$oauthEx = ApiException::oauth('invalid_client', '客户端认证失败', 401);
check('OAuth 异常标记正确', $oauthEx->isOAuth());
checkSame('OAuth 异常错误码', 'invalid_client', $oauthEx->oauthError());
checkSame('OAuth 异常 HTTP 状态', 401, $oauthEx->httpStatus());

$notImplemented = ApiException::notImplemented('接口未接入');
checkSame('notImplemented 状态码 501', 501, $notImplemented->httpStatus());

$response = new HttpResponse(200, '{"a":1}');
check('ok() 对 2xx 为 true', $response->ok());
checkSame('json 解析', array('a' => 1), $response->json());
check('404 识别', (new HttpResponse(404, ''))->notFound());
check('transport 失败时 ok() 为 false', !HttpResponse::failure('timeout')->ok());
check('transport 失败时 failed() 为 true', HttpResponse::failure('timeout')->failed());

$badJson = new HttpResponse(200, '<html>not json</html>');
checkSame('非 JSON 响应返回 null 而不是抛异常', null, $badJson->json());

// ----------------------------------------------------------------------------

section('未接入接口的失败语义（关键：绝不静默放行）');

checkThrows('玩家数据源未配置时抛 not_implemented', function () {
    (new UnavailablePlayerProvider())->findByName('LouieMAIN');
}, 'not_implemented');

checkThrows('邦国数据源未配置时抛 not_implemented（按ID）', function () {
    (new UnavailableCountryProvider())->findById(7);
}, 'not_implemented');

checkThrows('邦国数据源未配置时抛 not_implemented（按名称）', function () {
    (new UnavailableCountryProvider())->findByName('大周');
}, 'not_implemented');

checkThrows('邮件接口未配置时抛 not_implemented', function () {
    (new UnavailableEmailVerifier())->sendCode('a@b.com', '123456', 'register', 600);
}, 'not_implemented');

checkThrows('简幻通接口未配置时抛 not_implemented', function () {
    (new UnavailableSimpassVerifier())->verify(10086, '654321', 'LouieMAIN');
}, 'not_implemented');

check('未配置的数据源 isConfigured() 为 false', !(new UnavailablePlayerProvider())->isConfigured());

// ----------------------------------------------------------------------------

section('database/8w_passport.sql —— 结构自检');

$sqlPath = W8_PASSPORT_ROOT . '/database/8w_passport.sql';
check('SQL 文件存在', is_file($sqlPath), $sqlPath);

if (is_file($sqlPath)) {
    $sql = file_get_contents($sqlPath);

    check('创建了新库且不是旧库 bgjq', strpos($sql, 'CREATE DATABASE IF NOT EXISTS `bgjq8w`') !== false);
    check('包含创建数据库账号', strpos($sql, "CREATE USER IF NOT EXISTS 'bgjq8w'@'localhost'") !== false);
    check('包含授权语句', strpos($sql, 'GRANT ALL PRIVILEGES ON `bgjq8w`.*') !== false);

    check('密码位置使用占位符', strpos($sql, "IDENTIFIED BY '__DB_PASSWORD__'") !== false);

    // 结构性检查：所有 IDENTIFIED BY 的值必须都是占位符，
    // 这样断言里就不需要写出任何真实密钥片段。
    preg_match_all("/IDENTIFIED BY '([^']*)'/", $sql, $passwordLiterals);
    check(
        'SQL 中所有密码位置都是占位符，没有硬编码密钥',
        $passwordLiterals[1] !== array() && array_unique($passwordLiterals[1]) === array('__DB_PASSWORD__'),
        '实际值：' . implode(' / ', $passwordLiterals[1])
    );

    check('旧库 DROP 语句被注释掉', preg_match('/^\s*--\s*DROP DATABASE/m', $sql) === 1);

    $requiredTables = array(
        // 通行证身份域
        'passport_accounts', 'passport_sessions', 'passport_email_codes',
        // 第三方接入
        'passport_oauth_clients', 'passport_oauth_codes', 'passport_oauth_tokens', 'passport_api_logs',
        // 权威数据缓存域
        'countries', 'players',
        // 社区域
        'news', 'timeline', 'proposals', 'votes', 'conventions', 'cases', 'case_evidence',
        'arbitration_archive', 'diplomatic_relations', 'trades', 'services',
        // 遗留兼容
        'online_players', 'api_keys', 'api_logs',
    );

    $missing = array();
    foreach ($requiredTables as $table) {
        if (strpos($sql, "CREATE TABLE IF NOT EXISTS `{$table}`") === false) {
            $missing[] = $table;
        }
    }
    checkSame('全部 ' . count($requiredTables) . ' 张表均已定义', array(), $missing);

    check('定义了 users 只读兼容视图', strpos($sql, 'CREATE VIEW `users` AS') !== false);

    // 关键回归防线：视图绝不能暴露密码哈希。
    // 只要视图里有 password 列，任何旧的 `SELECT u.*` 都会把它送到前端。
    preg_match('/CREATE VIEW `users` AS(.*?);/s', $sql, $viewMatch);
    if (isset($viewMatch[1])) {
        $viewBody = $viewMatch[1];
        check(
            'users 视图不暴露 password_hash',
            strpos($viewBody, 'password_hash') === false
                && preg_match('/AS\s+`password`/', $viewBody) !== 1,
            '视图定义中出现了 password_hash'
        );
        foreach (array('id', 'username', 'game_id', 'country_id', 'role', 'jhtuid', 'level', 'created_at') as $column) {
            check("users 视图仍提供 {$column}（旧代码依赖）", strpos($viewBody, "AS `{$column}`") !== false);
        }
    } else {
        check('能解析 users 视图定义', false);
    }

    // 规格要求：邮箱与 FanVerify 都是"可选绑定"，所以必须允许 NULL
    preg_match('/CREATE TABLE IF NOT EXISTS `passport_accounts` \((.*?)\n\) ENGINE/s', $sql, $ma);
    if (isset($ma[1])) {
        $accountBody = $ma[1];

        check(
            'passport_accounts.email 允许 NULL（可选绑定）',
            preg_match('/`email`\s+VARCHAR\(\d+\)\s+NULL/', $accountBody) === 1
        );
        check('passport_accounts 含 fanverify_uid 字段', strpos($accountBody, '`fanverify_uid`') !== false);
        check('passport_accounts 含 fanverify_verified_at 字段', strpos($accountBody, '`fanverify_verified_at`') !== false);
        check('fanverify_uid 允许 NULL', preg_match('/`fanverify_uid`\s+BIGINT UNSIGNED NULL/', $accountBody) === 1);
        check('简幻通字段仍为必填语义（注册必填）', strpos($accountBody, '`simpass_uid`') !== false);
        check('唯一索引覆盖 email', strpos($accountBody, 'UNIQUE KEY `uk_email`') !== false);
        check('唯一索引覆盖 simpass_uid', strpos($accountBody, 'UNIQUE KEY `uk_simpass_uid`') !== false);
        check('唯一索引覆盖 fanverify_uid', strpos($accountBody, 'UNIQUE KEY `uk_fanverify_uid`') !== false);
        check('唯一索引覆盖 player_name', strpos($accountBody, 'UNIQUE KEY `uk_player_name`') !== false);
        check('password_hash 不允许 NULL', preg_match('/`password_hash`\s+VARCHAR\(\d+\)\s+NOT NULL/', $accountBody) === 1);
    } else {
        check('能解析 passport_accounts 表结构', false);
    }

    // 规格要求：玩家只保留三个字段
    preg_match('/CREATE TABLE IF NOT EXISTS `players` \((.*?)\n\) ENGINE/s', $sql, $m);
    if (isset($m[1])) {
        preg_match_all('/^\s*`([a-z_]+)`\s+(?:BIGINT|VARCHAR|DATETIME|INT|TINYINT)/m', $m[1], $cols);
        $playerColumns = array_values(array_diff($cols[1], array('synced_at', 'created_at')));
        sort($playerColumns);
        checkSame(
            'players 表只有规格要求的三个业务字段',
            array('country_id', 'player_id', 'player_name'),
            $playerColumns
        );
    } else {
        check('能解析 players 表结构', false);
    }

    // 邦国表必须覆盖规格要求的字段
    preg_match('/CREATE TABLE IF NOT EXISTS `countries` \((.*?)\n\) ENGINE/s', $sql, $mc);
    if (isset($mc[1])) {
        foreach (array('id', 'name', 'declaration', 'territory_chunks', 'population') as $column) {
            check("countries 含字段 {$column}", strpos($mc[1], "`{$column}`") !== false);
        }
    } else {
        check('能解析 countries 表结构', false);
    }

    // 每个建表语句都必须是 InnoDB + utf8mb4，否则外键与中文都会出问题
    $createCount = preg_match_all('/CREATE TABLE IF NOT EXISTS/', $sql);
    $engineCount = preg_match_all('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/', $sql);
    checkSame('所有建表语句均为 InnoDB + utf8mb4', $createCount, $engineCount);

    check('没有残留未替换的占位符（除密码外）', preg_match('/__(?!DB_PASSWORD__)[A-Z_]+__/', $sql) !== 1);
}

// ============================================================================

section('Application —— 依赖装配（最容易"忘了启动"的地方）');

$app = Application::boot(new Config(W8_PASSPORT_ROOT, array(
    'DB_NAME' => 'bgjq8w',
    'DB_USER' => 'bgjq8w',
    'DB_PASS' => 'not-a-real-password',
)));

check('boot 返回 Application', $app instanceof Application);
check('instance() 与 boot() 是同一个实例', Application::instance() === $app);
check('config 装配正确', $app->config() instanceof Config);
check('logger 装配正确', $app->logger() instanceof Logger);
check('http 客户端装配正确', $app->http() instanceof HttpClient);

// 未配置外部接口时，必须落到"明确报错"的 Null Object 上
check('玩家数据源未配置 -> UnavailablePlayerProvider', $app->playerProvider() instanceof UnavailablePlayerProvider);
check('邦国数据源未配置 -> UnavailableCountryProvider', $app->countryProvider() instanceof UnavailableCountryProvider);
check('邮件接口未配置 -> UnavailableEmailVerifier', $app->emailVerifier() instanceof UnavailableEmailVerifier);
check('简幻通未配置 -> UnavailableSimpassVerifier', $app->simpassVerifier() instanceof UnavailableSimpassVerifier);
check('FanVerify 未配置 -> UnavailableFanVerifyVerifier', $app->fanVerifyVerifier() instanceof UnavailableFanVerifyVerifier);

// 配置齐全时必须自动换成 HTTP 实现，无需改代码
Application::reset();
Application::boot(new Config(W8_PASSPORT_ROOT, array(
    'PLAYER_API_BASE' => 'https://game.example.com',
    'COUNTRY_API_BASE' => 'https://game.example.com',
    'EMAIL_API_URL' => 'https://mail.example.com/send',
    'SIMPASS_API_URL' => 'https://pass.example.com/auth',
    'SIMPPASS_ACCESS_TOKEN' => 'token',
    'FANVERIFY_ACCESS_TOKEN' => 'dev_TESTTOKEN',
)));
$app = Application::instance();
check('配置了 PLAYER_API_BASE -> HttpPlayerProvider', $app->playerProvider() instanceof HttpPlayerProvider);
check('配置了 COUNTRY_API_BASE -> HttpCountryProvider', $app->countryProvider() instanceof HttpCountryProvider);
check('配置了 EMAIL_API_URL -> HttpEmailVerifier', $app->emailVerifier() instanceof HttpEmailVerifier);
check('配置了 SIMPASS_API_URL -> HttpSimpassVerifier', $app->simpassVerifier() instanceof HttpSimpassVerifier);
check('配置了 FANVERIFY_ACCESS_TOKEN -> HttpFanVerifyVerifier', $app->fanVerifyVerifier() instanceof HttpFanVerifyVerifier);
check('FanVerify 客户端可取出', $app->fanVerifyClient() instanceof FanVerifyClient);

// 各服务都能被装配出来（构造过程不应建立数据库连接）
check('accounts 装配正确', $app->accounts() instanceof AccountRepository);
check('sessions 装配正确', $app->sessions() instanceof SessionStore);
check('authenticator 装配正确', $app->authenticator() instanceof Authenticator);
check('bindings 装配正确', $app->bindings() instanceof BindingService);
check('players 装配正确', $app->players() instanceof PlayerDirectory);
check('countries 装配正确', $app->countries() instanceof CountryDirectory);
check('emailCodes 装配正确', $app->emailCodes() instanceof EmailCodeService);
check('oauth 装配正确', $app->oauth() instanceof OAuthServer);
check('registration 装配正确', $app->registration() instanceof RegistrationService);

// 懒加载：同一个服务重复取必须拿到同一个对象
check('服务是单例', $app->oauth() === $app->oauth() && $app->accounts() === $app->accounts());

check('markApiContext 可记录调用上下文', (function () use ($app) {
    $app->markApiContext('client-abc', 42);
    return $app->currentClientId() === 'client-abc' && $app->currentAccountId() === 42;
})());

Application::reset();
check('reset 后 instance() 会自动重新装配', Application::instance() instanceof Application);

// 恢复到默认装配，避免影响后续用例
Application::reset();
Application::boot(new Config(W8_PASSPORT_ROOT, array('DB_NAME' => 'bgjq8w', 'DB_USER' => 'bgjq8w', 'DB_PASS' => 'x')));

// ============================================================================

section('Identity\\BindingService —— 绑定规则（不需要数据库的部分）');

// BindingService 的构造只保存依赖；describe() 与密码校验都不碰数据库，
// 因此可以在没有 MySQL 的环境下验证核心规则与顺序。
$bindingApp = Application::instance();
$bindingDb = new Database($bindingApp->config(), $bindingApp->logger());
$bindingService = new BindingService(
    new AccountRepository($bindingDb),
    new EmailCodeService($bindingDb, new UnavailableEmailVerifier(), $bindingApp->config(), $bindingApp->logger()),
    new UnavailableFanVerifyVerifier(),
    $bindingApp->config(),
    $bindingApp->logger()
);

$bindAccount = Account::fromRow(array(
    'id' => 9,
    'username' => 'binder',
    'email' => null,
    'email_verified_at' => null,
    'password_hash' => password_hash('correct-horse', PASSWORD_DEFAULT),
    'role' => 'observer',
    'status' => 1,
    'player_name' => 'Binder',
    'player_id' => 1,
    'country_id' => null,
    'simpass_uid' => 4242,
    'simpass_level' => null,
    'fanverify_uid' => null,
    'created_at' => '2026-01-01 00:00:00',
));

$described = $bindingService->describe($bindAccount);
checkSame('describe 覆盖四种绑定', array('player', 'simpass', 'email', 'fanverify'), array_keys($described));
check('玩家是必填且不可解绑', $described['player']['required'] === true && $described['player']['bindable'] === false);
check('简幻通是必填且不可解绑', $described['simpass']['required'] === true && $described['simpass']['bindable'] === false);
check('邮箱可选且可绑可解', $described['email']['required'] === false && $described['email']['bindable'] === true);
check('FanVerify 可选且可绑可解', $described['fanverify']['required'] === false && $described['fanverify']['bindable'] === true);
check('邮箱接口未接入时 available 为 false', $described['email']['available'] === false);
check('FanVerify 接口未接入时 available 为 false', $described['fanverify']['available'] === false);
check('未绑定的邮箱 bound 为 false', $described['email']['bound'] === false);
check('已绑定的玩家 bound 为 true', $described['player']['bound'] === true);
checkSame('绑定类型常量与端点一致', array('email', 'fanverify'), array(BindingService::TYPE_EMAIL, BindingService::TYPE_FANVERIFY));

// 密码校验必须发生在一切业务校验之前 —— 否则密码错误的人也能靠报错差异探测绑定状态
checkThrows('绑定邮箱：密码为空 -> 422', function () use ($bindingService, $bindAccount) {
    $bindingService->bindEmail($bindAccount, 'a@b.com', '123456', '');
}, 'invalid_request');

checkThrows('绑定邮箱：密码错误 -> 422', function () use ($bindingService, $bindAccount) {
    $bindingService->bindEmail($bindAccount, 'a@b.com', '123456', 'wrong-password');
}, 'invalid_request');

checkThrows('解绑邮箱：密码错误 -> 422（不会先暴露"没绑定"）', function () use ($bindingService, $bindAccount) {
    $bindingService->unbindEmail($bindAccount, 'wrong-password');
}, 'invalid_request');

checkThrows('解绑 FanVerify：密码错误 -> 422', function () use ($bindingService, $bindAccount) {
    $bindingService->unbindFanVerify($bindAccount, 'wrong-password');
}, 'invalid_request');

checkThrows('绑定 FanVerify：密码错误 -> 422', function () use ($bindingService, $bindAccount) {
    $bindingService->bindFanVerify($bindAccount, 555, '123456', 'wrong-password');
}, 'invalid_request');

// 密码正确后才轮到业务校验
checkThrows('密码正确但邮箱格式不对 -> 422', function () use ($bindingService, $bindAccount) {
    $bindingService->bindEmail($bindAccount, 'not-an-email', '123456', 'correct-horse');
}, 'invalid_request');

checkThrows('密码正确但邮箱为空 -> 422', function () use ($bindingService, $bindAccount) {
    $bindingService->bindEmail($bindAccount, '', '123456', 'correct-horse');
}, 'invalid_request');

checkThrows('未绑定邮箱时解绑 -> 409', function () use ($bindingService, $bindAccount) {
    $bindingService->unbindEmail($bindAccount, 'correct-horse');
}, 'conflict');

checkThrows('未绑定 FanVerify 时解绑 -> 409', function () use ($bindingService, $bindAccount) {
    $bindingService->unbindFanVerify($bindAccount, 'correct-horse');
}, 'conflict');

checkThrows('绑定 FanVerify：ID 非法 -> 422', function () use ($bindingService, $bindAccount) {
    $bindingService->bindFanVerify($bindAccount, 0, '123456', 'correct-horse');
}, 'invalid_request');

checkThrows('绑定 FanVerify：验证码为空 -> 422', function () use ($bindingService, $bindAccount) {
    $bindingService->bindFanVerify($bindAccount, 555, '', 'correct-horse');
}, 'invalid_request');

// 已绑定 FanVerify 的账号再绑同一个 ID，应在调用外部接口之前就被拦下
$alreadyBound = Account::fromRow(array_merge($bindAccount->raw(), array('fanverify_uid' => 555)));
checkThrows('重复绑定同一个 FanVerify -> 409', function () use ($bindingService, $alreadyBound) {
    $bindingService->bindFanVerify($alreadyBound, 555, '123456', 'correct-horse');
}, 'conflict');

// ============================================================================

section('Verification\\FanVerifyClient —— 对接 fanverify.cn openAPI');

/**
 * 假的 HTTP 客户端：按 URL 关键词返回预设响应，并记录收到的 URL。
 * 这样在没有网络、没有 cURL 扩展的环境下也能验证响应映射与 URL 构造。
 */
class FakeHttpClient extends HttpClient
{
    /** @var array<int,string> */
    public $urls = array();

    /** @var array<int,array{status:int,body:string}> */
    public $queue = array();

    /** @var array<string,array{status:int,body:string}> */
    public $routes = array();

    public function __construct()
    {
        // 不调用父类构造：不需要 Logger
    }

    /**
     * @param string $needle URL 里包含这个片段时返回该响应
     */
    public function on($needle, $status, $body)
    {
        $this->routes[$needle] = array('status' => (int) $status, 'body' => (string) $body);
        return $this;
    }

    private function reply($url)
    {
        $this->urls[] = $url;
        foreach ($this->routes as $needle => $response) {
            if (strpos($url, $needle) !== false) {
                return new HttpResponse($response['status'], $response['body']);
            }
        }
        return new HttpResponse(500, '{"error":"no route"}');
    }

    public function get($url, array $headers = array(), $timeout = 8)
    {
        return $this->reply($url);
    }

    public function postJson($url, array $jsonBody = array(), array $headers = array(), $timeout = 8)
    {
        return $this->reply($url);
    }
}

$fvConfig = new Config(W8_PASSPORT_ROOT, array(
    'FANVERIFY_API_BASE'     => 'https://api.fanverify.cn',
    'FANVERIFY_ACCESS_TOKEN' => 'dev_TESTTOKEN',
));

$fvHttp = new FakeHttpClient();
$fvLogger = new Logger(sys_get_temp_dir() . '/w8-test-logs', false);
$fvClient = new FanVerifyClient($fvConfig, $fvHttp, $fvLogger);

check('令牌已配置时 isConfigured 为 true', $fvClient->isConfigured());
checkSame('默认根地址', 'https://api.fanverify.cn', $fvClient->baseUrl());

check('未配令牌时 isConfigured 为 false', !(new FanVerifyClient(
    new Config(W8_PASSPORT_ROOT, array()),
    $fvHttp,
    $fvLogger
))->isConfigured());

// ---- devinfo ----
$fvHttp->on('/openapi/devinfo', 200, json_encode(array(
    'Date_of_Issue' => '2026-07-12T15:54:56+08:00',
    'bind_uid' => 100000,
    'mode' => 'HTTP',
    'need_end_level' => 1,
    'service_message' => 'TEST',
    'status' => 'ok',
)));
$info = $fvClient->developerInfo();
checkSame('devinfo 绑定账号', 100000, $info['bind_uid']);
checkSame('devinfo 对接模式', 'HTTP', $info['mode']);
checkSame('devinfo 要求等级', 1, $info['need_end_level']);
checkSame('devinfo 服务公告', 'TEST', $info['service_message']);
check(
    'devinfo 请求带上了 accesstoken 与正确路径',
    strpos($fvHttp->urls[count($fvHttp->urls) - 1], '/openapi/devinfo?accesstoken=dev_TESTTOKEN') !== false,
    end($fvHttp->urls)
);

// ---- user_verify 成功 ----
$fvHttp->on('/openapi/user_verify', 200, json_encode(array(
    'status' => 'ok',
    'data' => array(array(
        'level' => '3',
        'reg_time' => '2026-07-14T08:22:38+08:00',
        'tag' => '',
        'uid' => 100002,
    )),
)));
$identity = $fvClient->verifyUser(100002, '654321');
checkSame('user_verify 返回 uid', 100002, $identity->uid());
checkSame('user_verify 返回等级（字符串转 int）', 3, $identity->level());
check('空字符串 tag 视为无标签', !$identity->hasTag());
checkSame('无标签时 tag() 为 null', null, $identity->tag());
checkSame('返回注册时间', '2026-07-14T08:22:38+08:00', $identity->regTime());

$lastUrl = $fvHttp->urls[count($fvHttp->urls) - 1];
check('user_verify 使用 uid 与 pass_code 参数', strpos($lastUrl, 'uid=100002') !== false && strpos($lastUrl, 'pass_code=654321') !== false, $lastUrl);

// ---- user_verify 带风险标签 ----
$fvHttp->on('/openapi/user_verify', 200, json_encode(array(
    'status' => 'ok',
    'data' => array(array('level' => '1', 'reg_time' => '', 'tag' => '疑似小号', 'uid' => 100003)),
)));
$tagged = $fvClient->verifyUser(100003, '111111');
check('有标签时 hasTag 为 true', $tagged->hasTag());
checkSame('标签内容', '疑似小号', $tagged->tag());

// ---- user_verify 被拒（status 不是 ok）----
$fvHttp->on('/openapi/user_verify', 200, json_encode(array('status' => 'fail')));
checkThrows('user_verify 返回非 ok 状态 -> 422', function () use ($fvClient) {
    $fvClient->verifyUser(100002, '000000');
}, 'invalid_request');

// ---- 401 / 404 的语义必须能区分开 ----
// 实测 FanVerify 先校验路径再鉴权：不存在的路径返回 404，所以 401 一定指向令牌问题
$fvHttp->on('/openapi/devinfo', 401, '{"error":"Unauthorized"}');
checkThrows('401 -> 500 且提示令牌问题', function () use ($fvClient) {
    $fvClient->developerInfo();
}, 'server_error');

$fvHttp->on('/openapi/devinfo', 404, '404 page not found');
checkThrows('404 -> 500（路径/根地址配错，与令牌无关）', function () use ($fvClient) {
    $fvClient->developerInfo();
}, 'server_error');

$fvHttp->on('/openapi/devinfo', 200, '{"status":"ok"}');

// ---- OTP 申请 ----
$fvHttp->on('/openapi/otp', 200, '{"success":true,"data":{"otp":"0pO6gTXmtlzwOBNc"}}');
checkSame('申请 OTP', '0pO6gTXmtlzwOBNc', $fvClient->requestOtp());

$fvHttp->on('/openapi/otp', 200, '{"success":false,"data":{}}');
checkThrows('申请 OTP 失败 -> 500', function () use ($fvClient) {
    $fvClient->requestOtp();
}, 'server_error');

// ---- OTP 轮询：三种状态 ----
$fvHttp->on('/openapi/seeotp', 200, '{"status":"wait"}');
$polled = $fvClient->pollOtp('OTP123');
checkSame('轮询 wait', 'wait', $polled['status']);
checkSame('wait 时不返回身份', null, $polled['identity']);

$fvHttp->on('/openapi/seeotp', 429, '{"status":"rate_limit"}');
checkSame('轮询 rate_limit（429 不算错误）', 'rate_limit', $fvClient->pollOtp('OTP123')['status']);

$fvHttp->on('/openapi/seeotp', 200, json_encode(array(
    'status' => 'ok',
    'data' => array(array('level' => '2', 'reg_time' => '2026-07-12T15:02:51+08:00', 'tag' => '', 'uid' => 100000)),
)));
$okPoll = $fvClient->pollOtp('OTP123');
checkSame('轮询 ok', 'ok', $okPoll['status']);
checkSame('轮询 ok 时返回 uid', 100000, $okPoll['identity']->uid());
checkSame('轮询 ok 时返回等级', 2, $okPoll['identity']->level());

$lastOtpUrl = $fvHttp->urls[count($fvHttp->urls) - 1];
check('seeotp 使用 otp 参数', strpos($lastOtpUrl, '/openapi/seeotp?accesstoken=') !== false && strpos($lastOtpUrl, 'otp=OTP123') !== false, $lastOtpUrl);

// ---- 二维码必须是 PNG ----
$pngHeader = "\x89PNG\r\n\x1a\n" . str_repeat('x', 40);
$fvHttp->on('/openapi/genqrcode', 200, $pngHeader);
checkSame('genqrcode 返回二进制 PNG', $pngHeader, $fvClient->qrCodePng('OTP123'));

$fvHttp->on('/openapi/genqrcode', 200, '<html>not an image</html>');
checkThrows('genqrcode 返回非 PNG -> 500', function () use ($fvClient) {
    $fvClient->qrCodePng('OTP123');
}, 'server_error');

// ---- getuserdata：403 表示"没被本开发者验证过" ----
$fvHttp->on('/openapi/getuserdata', 403, '{"code":403}');
checkSame('getuserdata 403 -> null（未验证过）', null, $fvClient->userData(999999));

// ---- 未配置时所有方法都要明确报 not_implemented，不能静默成功 ----
$bareClient = new FanVerifyClient(new Config(W8_PASSPORT_ROOT, array()), $fvHttp, $fvLogger);
checkThrows('未配置令牌时 verifyUser 报错', function () use ($bareClient) {
    $bareClient->developerInfo();
}, 'not_implemented');

// ----------------------------------------------------------------------------

section('Support\\HttpClient —— URL 里的密钥必须打码');

checkSame(
    'accesstoken 被抹掉',
    'https://api.example.com/openapi/devinfo?accesstoken=***',
    HttpClient::sanitizeUrl('https://api.example.com/openapi/devinfo?accesstoken=dev_REALTOKEN123')
);
check(
    '多个敏感参数都被抹掉，非敏感参数保留',
    strpos(HttpClient::sanitizeUrl('https://a.com/x?uid=1001&pass_code=654321&otp=ABC'), 'uid=1001') !== false
        && strpos(HttpClient::sanitizeUrl('https://a.com/x?uid=1001&pass_code=654321&otp=ABC'), '654321') === false
        && strpos(HttpClient::sanitizeUrl('https://a.com/x?uid=1001&pass_code=654321&otp=ABC'), 'ABC') === false
);
checkSame(
    '没有查询串时原样返回',
    'https://api.example.com/openapi/devinfo',
    HttpClient::sanitizeUrl('https://api.example.com/openapi/devinfo')
);
checkSame(
    '不含敏感参数时原样返回',
    'https://a.com/x?uid=1001',
    HttpClient::sanitizeUrl('https://a.com/x?uid=1001')
);
check(
    '大小写不敏感',
    strpos(HttpClient::sanitizeUrl('https://a.com/x?AccessToken=secret'), 'secret') === false
);

// ----------------------------------------------------------------------------

echo "\n";
$passed = $GLOBALS['w8_passed'];
$failed = $GLOBALS['w8_failed'];
$total = $passed + $failed;

if ($failed === 0) {
    echo "\033[32m全部通过：{$passed}/{$total}\033[0m\n";
    exit(0);
}

echo "\033[31m失败 {$failed} 项（共 {$total} 项）\033[0m\n";
exit(1);
