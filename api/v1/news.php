<?php
require_once __DIR__ . '/../../php/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function getNews($db) {
    $stmt = $db->prepare(" 
        SELECT n.*, 
               u.username as author_name
        FROM news n
        LEFT JOIN users u ON n.author_id = u.id
        ORDER BY n.published_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $news = $stmt->fetchAll();

    jsonSuccess(['news' => $news]);
}

function getNewsItem($db, $id) {
    $stmt = $db->prepare(" 
        SELECT n.*, 
               u.username as author_name
        FROM news n
        LEFT JOIN users u ON n.author_id = u.id
        WHERE n.id = ?
    ");
    $stmt->execute([$id]);
    $news = $stmt->fetch();

    if (!$news) {
        jsonError('新闻不存在', 404);
    }

    jsonSuccess(['news' => $news]);
}

function createNews($db, $auth) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能发布新闻', 403);
    }

    $user = $auth->getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $title = sanitizeInput($input['title'] ?? '');
    $content = sanitizeInput($input['content'] ?? '');
    $isHeadline = isset($input['is_headline']) ? (bool)$input['is_headline'] : false;

    if (empty($title) || empty($content)) {
        jsonError('请填写所有必填项');
    }

    $stmt = $db->prepare(" 
        INSERT INTO news (title, content, author_id, is_headline) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$title, $content, $user['id'], $isHeadline]);

    jsonSuccess(['news_id' => $db->lastInsertId()], '新闻发布成功');
}

function updateNews($db, $auth, $id) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能编辑新闻', 403);
    }

    $stmt = $db->prepare("SELECT id FROM news WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        jsonError('新闻不存在', 404);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $updates = [];
    $params = [];
    
    if (isset($input['title'])) {
        $updates[] = 'title = ?';
        $params[] = sanitizeInput($input['title']);
    }
    
    if (isset($input['content'])) {
        $updates[] = 'content = ?';
        $params[] = sanitizeInput($input['content']);
    }
    
    if (isset($input['is_headline'])) {
        $updates[] = 'is_headline = ?';
        $params[] = (bool)$input['is_headline'];
    }
    
    if (empty($updates)) {
        jsonError('没有要更新的内容');
    }
    
    $params[] = $id;
    $sql = 'UPDATE news SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    jsonSuccess(null, '新闻更新成功');
}

function deleteNews($db, $auth, $id) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能删除新闻', 403);
    }

    $stmt = $db->prepare("DELETE FROM news WHERE id = ?");
    $stmt->execute([$id]);

    jsonSuccess(null, '新闻删除成功');
}

try {
    $db = getDBConnection();
    $auth = new Auth($db);

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    switch ($method) {
    case 'GET':
        if ($id) {
            getNewsItem($db, $id);
        } else {
            getNews($db);
        }
        break;
    case 'POST':
        createNews($db, $auth);
        break;
    case 'PUT':
        if ($id) {
            updateNews($db, $auth, $id);
        }
        break;
    case 'DELETE':
        if ($id) {
            deleteNews($db, $auth, $id);
        }
        break;
    default:
        jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('NEWS', '未捕获异常', [
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



