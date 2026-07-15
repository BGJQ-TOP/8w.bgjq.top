<?php
require_once __DIR__ . '/../../php/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function getTrades($db) {
    $stmt = $db->prepare("
        SELECT t.*, 
               c.name as country_name,
               u.username as poster_name
        FROM trades t
        JOIN users u ON t.posted_by_user_id = u.id
        LEFT JOIN countries c ON t.country_id = c.id
        WHERE t.status = 'active'
        ORDER BY t.created_at DESC
    ");
    $stmt->execute();
    $trades = $stmt->fetchAll();

    jsonSuccess(['trades' => $trades]);
}

function getTrade($db, $id) {
    $stmt = $db->prepare("
        SELECT t.*, 
               c.name as country_name,
               u.username as poster_name
        FROM trades t
        JOIN users u ON t.posted_by_user_id = u.id
        LEFT JOIN countries c ON t.country_id = c.id
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    $trade = $stmt->fetch();

    if (!$trade) {
        jsonError('交易信息不存在', 404);
    }

    jsonSuccess(['trade' => $trade]);
}

function createTrade($db, $auth) {
    if (!$auth->isLoggedIn()) {
        jsonError('请先登录', 401);
    }

    $user = $auth->getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $type = sanitizeInput($input['type'] ?? '');
    $itemName = sanitizeInput($input['item_name'] ?? '');
    $quantity = isset($input['quantity']) ? sanitizeInput($input['quantity']) : null;
    $exchangeMethod = isset($input['exchange_method']) ? sanitizeInput($input['exchange_method']) : null;

    if (!in_array($type, ['buy', 'sell'])) {
        jsonError('无效的交易类型');
    }

    if (empty($itemName)) {
        jsonError('请填写物品名称');
    }

    $stmt = $db->prepare("
        INSERT INTO trades (type, item_name, quantity, exchange_method, country_id, posted_by_user_id, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'active')
    ");
    $stmt->execute([$type, $itemName, $quantity, $exchangeMethod, $user['country_id'], $user['id']]);

    jsonSuccess(['trade_id' => $db->lastInsertId()], '交易信息发布成功');
}

function updateTrade($db, $auth, $id) {
    if (!$auth->isLoggedIn()) {
        jsonError('请先登录', 401);
    }

    $user = $auth->getCurrentUser();
    
    $stmt = $db->prepare("SELECT posted_by_user_id FROM trades WHERE id = ?");
    $stmt->execute([$id]);
    $trade = $stmt->fetch();
    
    if (!$trade) {
        jsonError('交易信息不存在', 404);
    }
    
    if ($trade['posted_by_user_id'] != $user['id'] && !$auth->hasRole('secretary_general')) {
        jsonError('只能更新自己发布的交易', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $status = sanitizeInput($input['status'] ?? '');

    if (!in_array($status, ['active', 'completed', 'cancelled'])) {
        jsonError('无效的状态');
    }

    $stmt = $db->prepare("UPDATE trades SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    jsonSuccess(null, '交易状态更新成功');
}

try {
    $db = getDBConnection();
    $auth = new Auth($db);

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    switch ($method) {
    case 'GET':
        if ($id) {
            getTrade($db, $id);
        } else {
            getTrades($db);
        }
        break;
    case 'POST':
        createTrade($db, $auth);
        break;
    case 'PUT':
        if ($id) {
            updateTrade($db, $auth, $id);
        }
        break;
    default:
        jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('TRADES', '未捕获异常', [
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



