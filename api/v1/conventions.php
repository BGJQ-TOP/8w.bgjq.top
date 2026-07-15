<?php
require_once __DIR__ . '/../../php/config.php';

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
    $id = $_GET['id'] ?? null;

    switch ($method) {
        case 'GET':
            getConventions($db);
            break;
        default:
            jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('CONVENTIONS', '未捕获异常', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '服务器内部错误', 'debug' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

function getConventions($db) {
    $stmt = $db->prepare("
        SELECT c.*, 
               u.username as enacted_by_name
        FROM conventions c
        LEFT JOIN users u ON c.enacted_by_user_id = u.id
        ORDER BY c.enacted_at DESC
    ");
    $stmt->execute();
    $conventions = $stmt->fetchAll();

    jsonSuccess(['conventions' => $conventions]);
}



