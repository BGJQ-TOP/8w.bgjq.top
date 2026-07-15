<?php
require_once __DIR__ . '/../../php/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function getUsers($db, $auth) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能查看用户列表', 403);
    }
    $stmt = $db->prepare("SELECT u.*, c.name as country_name FROM users u LEFT JOIN countries c ON u.country_id = c.id ORDER BY u.username");
    $stmt->execute();
    $users = $stmt->fetchAll();

    jsonSuccess(['users' => $users]);
}

function getUser($db, $auth, $id) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能查看用户信息', 403);
    }
    $stmt = $db->prepare("SELECT u.*, c.name as country_name FROM users u LEFT JOIN countries c ON u.country_id = c.id WHERE u.id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonError('用户不存在', 404);
    }

    jsonSuccess(['user' => $user]);
}

function createUser($db, $auth) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能添加用户', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $gameId = sanitizeInput($input['game_id'] ?? '');
    $countryName = isset($input['country_name']) ? sanitizeInput($input['country_name']) : null;
    $role = sanitizeInput($input['role'] ?? 'observer');

    if (empty($username) || empty($password) || empty($gameId)) {
        jsonError('请填写所有必填项');
    }

    if (strlen($password) < 6) {
        jsonError('密码至少需要6个字符');
    }

    if (!in_array($role, ['observer', 'diplomat', 'peacekeeper', 'permanent_member', 'secretary_general'])) {
        jsonError('无效的角色');
    }

    // 检查用户名是否已存在
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        jsonError('用户名已存在');
    }

    $countryId = null;
    if ($countryName) {
        $stmt = $db->prepare("SELECT id FROM countries WHERE name = ? AND is_active = TRUE");
        $stmt->execute([$countryName]);
        $country = $stmt->fetch();
        if ($country) {
            $countryId = $country['id'];
        }
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (username, password, game_id, country_id, role) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$username, $hashedPassword, $gameId, $countryId, $role]);

    jsonSuccess(['user_id' => $db->lastInsertId()], '用户添加成功');
}

function updateUser($db, $auth, $id) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能编辑用户', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $updates = [];
    $params = [];

    if (isset($input['password']) && !empty($input['password'])) {
        if (strlen($input['password']) < 6) {
            jsonError('密码至少需要6个字符');
        }
        $updates[] = 'password = ?';
        $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
    }

    if (isset($input['role'])) {
        $role = sanitizeInput($input['role']);
        if (!in_array($role, ['observer', 'diplomat', 'peacekeeper', 'permanent_member', 'secretary_general'])) {
            jsonError('无效的角色');
        }
        $updates[] = 'role = ?';
        $params[] = $role;
    }

    if (isset($input['country_name'])) {
        $countryName = sanitizeInput($input['country_name']);
        $countryId = null;
        if ($countryName) {
            $stmt = $db->prepare("SELECT id FROM countries WHERE name = ? AND is_active = TRUE");
            $stmt->execute([$countryName]);
            $country = $stmt->fetch();
            if ($country) {
                $countryId = $country['id'];
            }
        }
        $updates[] = 'country_id = ?';
        $params[] = $countryId;
    }

    if (empty($updates)) {
        jsonError('没有要更新的内容');
    }

    $params[] = $id;
    $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    jsonSuccess(null, '用户更新成功');
}

function deleteUser($db, $auth, $id) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能删除用户', 403);
    }

    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess(null, '用户删除成功');
}

try {
    $db = getDBConnection();
    $auth = new Auth($db);

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    switch ($method) {
    case 'GET':
        if ($id) {
            getUser($db, $auth, $id);
        } else {
            getUsers($db, $auth);
        }
        break;
    case 'POST':
        createUser($db, $auth);
        break;
    case 'PUT':
        if ($id) {
            updateUser($db, $auth, $id);
        }
        break;
    case 'DELETE':
        if ($id) {
            deleteUser($db, $auth, $id);
        }
        break;
    default:
        jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('USERS', '未捕获异常', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
    exit;
}


