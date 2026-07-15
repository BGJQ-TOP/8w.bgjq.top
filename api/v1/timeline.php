<?php
require_once __DIR__ . '/../../php/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function getTimeline($db) {
    $stmt = $db->prepare(" 
        SELECT * 
        FROM timeline 
        ORDER BY date DESC, created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $timeline = $stmt->fetchAll();

    jsonSuccess(['timeline' => $timeline]);
}

function getTimelineItem($db, $id) {
    $stmt = $db->prepare("SELECT * FROM timeline WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if (!$item) {
        jsonError('时间轴条目不存在', 404);
    }

    jsonSuccess(['item' => $item]);
}

function createTimelineItem($db, $auth) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能创建时间轴条目', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $title = sanitizeInput($input['title'] ?? '');
    $description = isset($input['description']) ? sanitizeInput($input['description']) : null;
    $date = sanitizeInput($input['date'] ?? date('Y-m-d'));
    $eventType = sanitizeInput($input['event_type'] ?? 'other');

    if (empty($title) || empty($date)) {
        jsonError('请填写所有必填项');
    }

    if (!in_array($eventType, ['war', 'peace', 'construction', 'diplomatic', 'other'])) {
        jsonError('无效的事件类型');
    }

    $stmt = $db->prepare(" 
        INSERT INTO timeline (date, title, description, event_type) VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$date, $title, $description, $eventType]);

    jsonSuccess(['item_id' => $db->lastInsertId()], '时间轴条目创建成功');
}

function updateTimelineItem($db, $auth, $id) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能编辑时间轴条目', 403);
    }

    $stmt = $db->prepare("SELECT id FROM timeline WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        jsonError('时间轴条目不存在', 404);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $updates = [];
    $params = [];
    
    if (isset($input['title'])) {
        $updates[] = 'title = ?';
        $params[] = sanitizeInput($input['title']);
    }
    
    if (isset($input['description'])) {
        $updates[] = 'description = ?';
        $params[] = sanitizeInput($input['description']);
    }
    
    if (isset($input['date'])) {
        $updates[] = 'date = ?';
        $params[] = sanitizeInput($input['date']);
    }
    
    if (isset($input['event_type'])) {
        $eventType = sanitizeInput($input['event_type']);
        if (!in_array($eventType, ['war', 'peace', 'construction', 'diplomatic', 'other'])) {
            jsonError('无效的事件类型');
        }
        $updates[] = 'event_type = ?';
        $params[] = $eventType;
    }
    
    if (empty($updates)) {
        jsonError('没有要更新的内容');
    }
    
    $params[] = $id;
    
    $sql = 'UPDATE timeline SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    jsonSuccess(null, '时间轴条目更新成功');
}

function deleteTimelineItem($db, $auth, $id) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能删除时间轴条目', 403);
    }

    $stmt = $db->prepare("DELETE FROM timeline WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess(null, '时间轴条目删除成功');
}

try {
    $db = getDBConnection();
    $auth = new Auth($db);

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    switch ($method) {
    case 'GET':
        if ($id) {
            getTimelineItem($db, $id);
        } else {
            getTimeline($db);
        }
        break;
    case 'POST':
        createTimelineItem($db, $auth);
        break;
    case 'PUT':
        if ($id) {
            updateTimelineItem($db, $auth, $id);
        }
        break;
    case 'DELETE':
        if ($id) {
            deleteTimelineItem($db, $auth, $id);
        }
        break;
    default:
        jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('TIMELINE', '未捕获异常', [
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



