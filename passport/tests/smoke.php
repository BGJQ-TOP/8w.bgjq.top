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

use W8\Passport\Directory\CountryProfile;
use W8\Passport\Directory\PlayerProfile;
use W8\Passport\Directory\Providers\UnavailableCountryProvider;
use W8\Passport\Directory\Providers\UnavailablePlayerProvider;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Response;
use W8\Passport\OAuth\Scope;
use W8\Passport\Support\Arr;
use W8\Passport\Support\Config;
use W8\Passport\Support\HttpResponse;
use W8\Passport\Support\Str;
use W8\Passport\Verification\UnavailableEmailVerifier;
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
