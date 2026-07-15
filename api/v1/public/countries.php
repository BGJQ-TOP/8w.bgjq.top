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

    if (!$apiAuth->hasPermission('countries')) {
        $apiLogger->log(403, '权限不足');
        jsonError('权限不足', 403);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    if ($method === 'GET') {
        if ($id) {
            $data = getCountry($db, $id);
        } else {
            $data = getCountries($db);
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
    appLog('PUBLIC_COUNTRIES_API', '未捕获异常', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    http_response_code(500);
    echo json_encode(['error' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

function getCountries($db) {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $pageSize = isset($_GET['page_size']) ? min(100, max(1, (int)$_GET['page_size'])) : 20;
    $offset = ($page - 1) * $pageSize;

    $activeOnly = !isset($_GET['all']) || $_GET['all'] !== 'true';
    $fields = $_GET['fields'] ?? null;

    $where = [];
    $params = [];

    if ($activeOnly) {
        $where[] = 'c.is_active = TRUE';
    }

    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) as total FROM countries c {$whereClause}";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];

    $allowedFields = ['id', 'name', 'declaration', 'government_type', 'population', 'territory_chunks', 'flag_url', 'is_active', 'joined_at'];
    $selectFields = $allowedFields;

    if ($fields) {
        $requestedFields = array_map('trim', explode(',', $fields));
        $selectFields = array_intersect($requestedFields, $allowedFields);
        if (empty($selectFields)) {
            $selectFields = $allowedFields;
        }
    }

    $selectClause = 'c.' . implode(', c.', $selectFields);

    $sql = "SELECT {$selectClause},
            (SELECT COUNT(*) FROM users u WHERE u.country_id = c.id) as member_count
            FROM countries c
            {$whereClause}
            ORDER BY c.name ASC
            LIMIT {$pageSize} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $countries = $stmt->fetchAll();

    return [
        'list' => $countries,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => (int)$total,
            'total_pages' => ceil($total / $pageSize)
        ]
    ];
}

function getCountry($db, $id) {
    $id = (int)$id;
    $fields = $_GET['fields'] ?? null;

    $allowedFields = ['id', 'name', 'declaration', 'government_type', 'population', 'territory_chunks', 'flag_url', 'is_active', 'joined_at'];
    $selectFields = $allowedFields;

    if ($fields) {
        $requestedFields = array_map('trim', explode(',', $fields));
        $selectFields = array_intersect($requestedFields, $allowedFields);
        if (empty($selectFields)) {
            $selectFields = $allowedFields;
        }
    }

    $selectClause = 'c.' . implode(', c.', $selectFields);

    $sql = "SELECT {$selectClause},
            (SELECT COUNT(*) FROM users u WHERE u.country_id = c.id) as member_count
            FROM countries c
            WHERE c.id = ?";

    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $country = $stmt->fetch();

    if (!$country) {
        throw new ApiAuthException('国家不存在', 404);
    }

    return ['country' => $country];
}
