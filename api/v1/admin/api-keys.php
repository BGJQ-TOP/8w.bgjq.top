<?php
require_once __DIR__ . '/../../../php/config.php';
require_once __DIR__ . '/../../../php/classes/Auth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function requireAdmin() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user'])) {
        jsonError('请先登录', 401);
    }
    
    if ($_SESSION['user']['username'] !== env('ADMIN_USERNAME', 'YOUR_ADMIN_USERNAME')) {
        jsonError('只有管理员才能访问此功能', 403);
    }
}

try {
    $db = getDBConnection();
    $auth = new Auth($db);
    
    requireAdmin();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;
    
    switch ($method) {
    case 'GET':
        if ($id) {
            getApiKey($db, $id);
        } else {
            getApiKeys($db);
        }
        break;
    case 'POST':
        createApiKey($db);
        break;
    case 'PUT':
        if ($id) {
            updateApiKey($db, $id);
        }
        break;
    case 'DELETE':
        if ($id) {
            deleteApiKey($db, $id);
        }
        break;
    default:
        jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('ADMIN_API', '未捕获异常', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

function getApiKeys($db) {
    $stmt = $db->query("SELECT id, key_name, api_key, api_secret, allowed_ips, rate_limit, is_active, permissions, last_used_at, created_at, expires_at FROM api_keys ORDER BY id DESC");
    $keys = $stmt->fetchAll();
    jsonSuccess(['keys' => $keys]);
}

function getApiKey($db, $id) {
    $id = (int)$id;
    $stmt = $db->prepare("SELECT id, key_name, api_key, api_secret, allowed_ips, rate_limit, is_active, permissions, last_used_at, created_at, expires_at FROM api_keys WHERE id = ?");
    $stmt->execute([$id]);
    $key = $stmt->fetch();
    
    if (!$key) {
        jsonError('API Key不存在', 404);
    }
    
    jsonSuccess(['key' => $key]);
}

function createApiKey($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $keyName = sanitizeInput($input['key_name'] ?? '');
    $allowedIps = sanitizeInput($input['allowed_ips'] ?? '');
    $rateLimit = isset($input['rate_limit']) ? (int)$input['rate_limit'] : 60;
    $permissions = sanitizeInput($input['permissions'] ?? 'users,countries,stats');
    $expiresAt = $input['expires_at'] ?? null;
    
    if (empty($keyName)) {
        jsonError('请输入密钥名称');
    }
    
    $apiKey = bin2hex(random_bytes(16));
    $apiSecret = bin2hex(random_bytes(24));
    
    $stmt = $db->prepare("INSERT INTO api_keys (key_name, api_key, api_secret, allowed_ips, rate_limit, permissions, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$keyName, $apiKey, $apiSecret, $allowedIps, $rateLimit, $permissions, $expiresAt]);
    
    jsonSuccess(['key_id' => $db->lastInsertId(), 'api_key' => $apiKey, 'api_secret' => $apiSecret], 'API Key创建成功');
}

function updateApiKey($db, $id) {
    $id = (int)$id;
    $input = json_decode(file_get_contents('php://input'), true);
    
    $updates = [];
    $params = [];
    
    if (isset($input['key_name'])) {
        $updates[] = 'key_name = ?';
        $params[] = sanitizeInput($input['key_name']);
    }
    
    if (isset($input['allowed_ips'])) {
        $updates[] = 'allowed_ips = ?';
        $params[] = sanitizeInput($input['allowed_ips']);
    }
    
    if (isset($input['rate_limit'])) {
        $updates[] = 'rate_limit = ?';
        $params[] = (int)$input['rate_limit'];
    }
    
    if (isset($input['permissions'])) {
        $updates[] = 'permissions = ?';
        $params[] = sanitizeInput($input['permissions']);
    }
    
    if (isset($input['is_active'])) {
        $updates[] = 'is_active = ?';
        $params[] = $input['is_active'] ? 1 : 0;
    }
    
    if (isset($input['expires_at'])) {
        $updates[] = 'expires_at = ?';
        $params[] = $input['expires_at'] ?: null;
    }
    
    if (empty($updates)) {
        jsonError('没有要更新的内容');
    }
    
    $params[] = $id;
    $sql = 'UPDATE api_keys SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    jsonSuccess(null, 'API Key更新成功');
}

function deleteApiKey($db, $id) {
    $id = (int)$id;
    
    $stmt = $db->prepare("DELETE FROM api_keys WHERE id = ?");
    $stmt->execute([$id]);
    
    jsonSuccess(null, 'API Key删除成功');
}
