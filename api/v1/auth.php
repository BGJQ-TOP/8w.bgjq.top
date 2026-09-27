<?php
/**
 * 认证接口（兼容层）
 *
 * 身份的唯一真源是 8W通行证系统（passport/）。
 * 本文件保留旧的 URL 与响应格式（{success, message, data}），
 * 让站内既有页面无需改动即可继续登录/注册，内部全部委托给通行证。
 *
 * 新接入请直接使用：
 *   POST /passport/api/v1/register
 *   POST /passport/api/v1/login
 *   GET  /passport/api/v1/me
 *   POST /passport/api/v1/logout
 */

require_once __DIR__ . '/../../php/config.php';
require_once __DIR__ . '/../../php/classes/Auth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Request;

try {
    $passport = Application::instance();
    $auth = new Auth();

    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    switch ($method) {
        case 'POST':
            if ($action === 'register') {
                handleRegister($passport);
            } elseif ($action === 'login') {
                handleLogin($auth);
            } elseif ($action === 'reset-password') {
                handleResetPassword($auth);
            } else {
                jsonError('无效的操作');
            }
            break;

        case 'GET':
            if ($action === 'current') {
                handleCurrent($auth);
            } elseif ($action === 'verify-player') {
                handleVerifyPlayer($passport);
            } else {
                jsonError('无效的操作');
            }
            break;

        case 'DELETE':
            if ($action === 'logout') {
                jsonSuccess($auth->logout(), '登出成功');
            } else {
                jsonError('无效的操作');
            }
            break;

        default:
            jsonError('不支持的请求方法');
    }
} catch (ApiException $e) {
    jsonError($e->getMessage(), $e->httpStatus());
} catch (Throwable $e) {
    appLog('AUTH', '未捕获异常', array(
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ));
    jsonError('服务器内部错误', 500);
}

// ============================================================================
//  处理函数
// ============================================================================

/**
 * 注册 —— 委托通行证 RegistrationService
 *
 * 必需字段：username / password / email / email_code / player_name / simpass_uid / simpass_code
 */
function handleRegister(Application $passport)
{
    $input = readJsonBody();

    $account = $passport->registration()->register($input, Request::fromGlobals());

    // 注册成功即登录，并同步旧会话镜像
    $passport->sessions()->create($account->id(), Request::fromGlobals());
    $passport->accounts()->touchLogin($account->id(), clientIp());

    appLog('AUTH', '注册成功', array('account_id' => $account->id(), 'player' => $account->playerName()));

    jsonSuccess(array(
        'user_id' => $account->id(),
        'user'    => $account->toPublicArray(),
    ), '注册成功');
}

function handleLogin(Auth $auth)
{
    $input = readJsonBody();

    $identifier = isset($input['identifier']) ? $input['identifier'] : (isset($input['username']) ? $input['username'] : '');
    $password = isset($input['password']) ? $input['password'] : '';

    if (trim((string) $identifier) === '' || $password === '') {
        jsonError('请填写账号和密码');
    }

    $result = $auth->login($identifier, $password);

    if (isset($result['error'])) {
        jsonError($result['error'], 401);
    }

    jsonSuccess($result, '登录成功');
}

function handleCurrent(Auth $auth)
{
    $user = $auth->getCurrentUser();
    if ($user === null) {
        jsonError('未登录', 401);
    }
    jsonSuccess(array('user' => $user));
}

/**
 * 查询游戏内玩家（走权威接口，带缓存）
 */
function handleVerifyPlayer(Application $passport)
{
    $playerName = isset($_GET['player']) ? trim((string) $_GET['player']) : '';
    if ($playerName === '') {
        jsonError('请提供玩家名');
    }

    try {
        $player = $passport->players()->find($playerName, false);
    } catch (ApiException $e) {
        jsonError($e->getMessage(), $e->httpStatus());
    }

    if ($player === null) {
        jsonError('玩家不存在，请检查游戏ID是否正确', 404);
    }

    jsonSuccess(array('player' => $player->toArray()));
}

/**
 * 重置密码 —— 现在只需要原密码 + 新密码
 *
 * 旧版要求提供简幻通UID与验证码；改由通行证统一托管后，
 * 简幻通只在注册时校验一次，改密凭原密码即可。
 */
function handleResetPassword(Auth $auth)
{
    $input = readJsonBody();

    $username = isset($input['username']) ? trim((string) $input['username']) : '';
    $oldPassword = isset($input['old_password']) ? (string) $input['old_password'] : '';
    $newPassword = isset($input['new_password']) ? (string) $input['new_password'] : '';

    if ($username === '' || $oldPassword === '' || $newPassword === '') {
        jsonError('请填写所有必填项');
    }

    $user = $auth->getUserByUsername($username);
    if ($user === null) {
        jsonError('用户不存在');
    }

    $result = $auth->resetPassword($user['id'], $oldPassword, $newPassword);
    if (isset($result['error'])) {
        jsonError($result['error']);
    }

    jsonSuccess(null, '密码重置成功');
}

// ============================================================================
//  工具
// ============================================================================

/**
 * @return array<string,mixed>
 */
function readJsonBody()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    // 兼容表单提交
    $parsed = array();
    parse_str($raw, $parsed);
    return is_array($parsed) ? $parsed : array();
}

function clientIp()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }
    return $ip;
}
