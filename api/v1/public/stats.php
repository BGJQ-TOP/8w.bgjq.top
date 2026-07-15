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

    if (!$apiAuth->hasPermission('stats')) {
        $apiLogger->log(403, '权限不足');
        jsonError('权限不足', 403);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $data = getStats($db);
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
    appLog('PUBLIC_STATS_API', '未捕获异常', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    http_response_code(500);
    echo json_encode(['error' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

function getStats($db) {
    $stats = [];

    $stmt = $db->query("SELECT COUNT(*) as total FROM users");
    $stats['total_users'] = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM countries WHERE is_active = TRUE");
    $stats['total_countries'] = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM countries");
    $stats['total_countries_all'] = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
    $roleCounts = $stmt->fetchAll();
    $stats['users_by_role'] = [];
    foreach ($roleCounts as $row) {
        $stats['users_by_role'][$row['role']] = (int)$row['count'];
    }

    $stmt = $db->query("SELECT COUNT(*) as total FROM news");
    $stats['total_news'] = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM proposals");
    $stats['total_proposals'] = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT status, COUNT(*) as count FROM proposals GROUP BY status");
    $proposalStatus = $stmt->fetchAll();
    $stats['proposals_by_status'] = [];
    foreach ($proposalStatus as $row) {
        $stats['proposals_by_status'][$row['status']] = (int)$row['count'];
    }

    $stmt = $db->query("SELECT COUNT(*) as total FROM conventions");
    $stats['total_conventions'] = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM cases");
    $stats['total_cases'] = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT status, COUNT(*) as count FROM cases GROUP BY status");
    $caseStatus = $stmt->fetchAll();
    $stats['cases_by_status'] = [];
    foreach ($caseStatus as $row) {
        $stats['cases_by_status'][$row['status']] = (int)$row['count'];
    }

    $stmt = $db->query("SELECT COUNT(*) as total FROM trades WHERE status = 'active'");
    $stats['active_trades'] = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM online_players WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $stats['online_players'] = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM diplomatic_relations");
    $stats['total_diplomatic_relations'] = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT relation, COUNT(*) as count FROM diplomatic_relations GROUP BY relation");
    $relations = $stmt->fetchAll();
    $stats['diplomatic_relations_by_type'] = [];
    foreach ($relations as $row) {
        $stats['diplomatic_relations_by_type'][$row['relation']] = (int)$row['count'];
    }

    return ['stats' => $stats];
}
