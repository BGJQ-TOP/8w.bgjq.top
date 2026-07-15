<?php
require_once __DIR__ . '/../../../php/config.php';
require_once __DIR__ . '/../../../php/classes/ApiAuth.php';
require_once __DIR__ . '/../../../php/classes/ApiLogger.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $db = getDBConnection();
    $apiAuth = new ApiAuth($db);
    $apiLogger = new ApiLogger($db);

    $keyRecord = $apiAuth->authenticate();
    $apiAuth->checkRateLimit();

    $apiLogger->setContext(
        $keyRecord['id'],
        $keyRecord['api_key'],
        $_SERVER['REQUEST_URI'],
        $_SERVER['REQUEST_METHOD'],
        $_GET
    );

    if (!$apiAuth->hasPermission('users')) {
        $apiLogger->log(403, '权限不足');
        jsonError('权限不足', 403);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    if ($method === 'GET') {
        if ($id) {
            $data = getUser($db, $id);
        } else {
            $data = getUsers($db);
        }
        $apiLogger->log(200);
        $apiAuth->updateLastUsed();
        jsonSuccess($data);
    } else {
        $apiLogger->log(405, '不支持的请求方法');
        jsonError('不支持的请求方法', 405);
    }
} catch (ApiAuthException $e) {
    http_response_code($e->getCode());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    appLog('PUBLIC_USERS_API', '未捕获异常', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    http_response_code(500);
    echo json_encode(['error' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

function getUsers($db) {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $pageSize = isset($_GET['page_size']) ? min(100, max(1, (int)$_GET['page_size'])) : 20;
    $offset = ($page - 1) * $pageSize;

    $role = $_GET['role'] ?? null;
    $countryId = isset($_GET['country_id']) ? (int)$_GET['country_id'] : null;
    $fields = $_GET['fields'] ?? null;

    $where = [];
    $params = [];

    if ($role) {
        $where[] = 'u.role = ?';
        $params[] = $role;
    }

    if ($countryId) {
        $where[] = 'u.country_id = ?';
        $params[] = $countryId;
    }

    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) as total FROM users u {$whereClause}";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];

    $allowedFields = ['id', 'username', 'game_id', 'country_id', 'role', 'jhtuid', 'level', 'created_at'];
    $selectFields = $allowedFields;

    if ($fields) {
        $requestedFields = array_map('trim', explode(',', $fields));
        $selectFields = array_intersect($requestedFields, $allowedFields);
        if (empty($selectFields)) {
            $selectFields = $allowedFields;
        }
    }

    $selectClause = 'u.' . implode(', u.', $selectFields);

    $sql = "SELECT {$selectClause}, c.name as country_name 
            FROM users u 
            LEFT JOIN countries c ON u.country_id = c.id 
            {$whereClause} 
            ORDER BY u.id DESC 
            LIMIT {$pageSize} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    foreach ($users as &$user) {
        if (isset($user['country_id']) && $user['country_id'] && isset($user['country_name'])) {
            $user['country'] = [
                'id' => $user['country_id'],
                'name' => $user['country_name']
            ];
            unset($user['country_id'], $user['country_name']);
        }
    }

    return [
        'list' => $users,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => (int)$total,
            'total_pages' => ceil($total / $pageSize)
        ]
    ];
}

function getUser($db, $id) {
    $fields = $_GET['fields'] ?? null;
    $id = (int)$id;

    $allowedFields = ['id', 'username', 'game_id', 'country_id', 'role', 'jhtuid', 'level', 'created_at'];
    $selectFields = $allowedFields;

    if ($fields) {
        $requestedFields = array_map('trim', explode(',', $fields));
        $selectFields = array_intersect($requestedFields, $allowedFields);
        if (empty($selectFields)) {
            $selectFields = $allowedFields;
        }
    }

    $selectClause = 'u.' . implode(', u.', $selectFields);

    $sql = "SELECT {$selectClause}, c.name as country_name 
            FROM users u 
            LEFT JOIN countries c ON u.country_id = c.id 
            WHERE u.id = ?";

    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new ApiAuthException('用户不存在', 404);
    }

    if (isset($user['country_id']) && $user['country_id'] && isset($user['country_name'])) {
        $user['country'] = [
            'id' => $user['country_id'],
            'name' => $user['country_name']
        ];
        unset($user['country_id'], $user['country_name']);
    }

    return ['user' => $user];
}
