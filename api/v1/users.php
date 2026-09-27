<?php
/**
 * 用户管理接口（管理员）
 *
 * 身份表已由 users 迁移到通行证的 passport_accounts，
 * 本文件改为通过 Auth 兼容层操作通行证账号，URL 与响应格式保持不变。
 *
 * 注意：管理员在此处开号属于"直接开户"，不经过邮箱验证码与简幻通验证，
 * 但玩家名仍会走权威接口校验，确保绑定的确实是服务器内真实存在的玩家。
 */

require_once __DIR__ . '/../../php/config.php';
require_once __DIR__ . '/../../php/classes/Auth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $db = getDBConnection();
    $auth = new Auth($db);

    $method = $_SERVER['REQUEST_METHOD'];
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    switch ($method) {
        case 'GET':
            if ($id > 0) {
                getUser($auth, $id);
            } else {
                getUsers($auth);
            }
            break;

        case 'POST':
            createUser($auth);
            break;

        case 'PUT':
            if ($id > 0) {
                updateUser($auth, $id);
            } else {
                jsonError('缺少用户ID');
            }
            break;

        case 'DELETE':
            if ($id > 0) {
                deleteUser($auth, $id);
            } else {
                jsonError('缺少用户ID');
            }
            break;

        default:
            jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('USERS', '未捕获异常', array(
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ));
    jsonError('服务器内部错误', 500);
}

// ============================================================================

function getUsers(Auth $auth)
{
    requireSecretaryGeneral($auth, '查看用户列表');

    $rows = $auth->accountRepository()->listAll();

    jsonSuccess(array('users' => $rows));
}

function getUser(Auth $auth, $id)
{
    requireSecretaryGeneral($auth, '查看用户信息');

    $user = $auth->getUserById($id);
    if ($user === null) {
        jsonError('用户不存在', 404);
    }

    // 补上邦国名称，保持与旧响应一致
    $user['country_name'] = $auth->countryName($user['country_id']);

    jsonSuccess(array('user' => $user));
}

function createUser(Auth $auth)
{
    requireSecretaryGeneral($auth, '添加用户');

    $input = readJsonBody();

    $playerName = sanitizeInput(isset($input['player_name']) ? $input['player_name']
        : (isset($input['game_id']) ? $input['game_id'] : ''));

    $result = $auth->provision(array(
        'username'    => sanitizeInput(isset($input['username']) ? $input['username'] : ''),
        'password'    => isset($input['password']) ? (string) $input['password'] : '',
        'email'       => sanitizeInput(isset($input['email']) ? $input['email'] : ''),
        'player_name' => $playerName,
        'simpass_uid' => isset($input['simpass_uid']) ? $input['simpass_uid'] : null,
        'role'        => sanitizeInput(isset($input['role']) ? $input['role'] : 'observer'),
    ));

    if (isset($result['error'])) {
        jsonError($result['error']);
    }

    jsonSuccess(array('user_id' => $result['user_id']), '用户添加成功');
}

function updateUser(Auth $auth, $id)
{
    requireSecretaryGeneral($auth, '编辑用户');

    $input = readJsonBody();
    $fields = array();

    if (isset($input['password']) && $input['password'] !== '') {
        $fields['password'] = (string) $input['password'];
    }

    if (isset($input['role'])) {
        $fields['role'] = sanitizeInput($input['role']);
    }

    if (isset($input['status'])) {
        $fields['status'] = (int) $input['status'];
    }

    // 兼容旧的 country_name 写法
    if (isset($input['country_id'])) {
        $fields['country_id'] = $input['country_id'];
    } elseif (isset($input['country_name'])) {
        $countryName = sanitizeInput($input['country_name']);
        $fields['country_id'] = $countryName === '' ? null : countryIdByName($countryName);
    }

    $result = $auth->updateAccount($id, $fields);
    if (isset($result['error'])) {
        jsonError($result['error']);
    }

    jsonSuccess(null, '用户更新成功');
}

function deleteUser(Auth $auth, $id)
{
    requireSecretaryGeneral($auth, '删除用户');

    $result = $auth->deleteAccount($id);
    if (isset($result['error'])) {
        jsonError($result['error'], 404);
    }

    jsonSuccess(null, '用户删除成功');
}

// ============================================================================

function requireSecretaryGeneral(Auth $auth, $action)
{
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能' . $action, 403);
    }
}

/**
 * @return int|null
 */
function countryIdByName($countryName)
{
    $db = getDBConnection();
    $stmt = $db->prepare('SELECT `id` FROM `countries` WHERE `name` = ? AND `is_active` = 1 LIMIT 1');
    $stmt->execute(array($countryName));
    $row = $stmt->fetch();

    return $row ? (int) $row['id'] : null;
}

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
    return is_array($decoded) ? $decoded : array();
}
