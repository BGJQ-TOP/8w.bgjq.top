<?php
require_once __DIR__ . '/../../php/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function getCases($db) {
    $stmt = $db->prepare("
        SELECT c.*, 
               p.username as plaintiff_name,
               co.name as defendant_country_name
        FROM cases c
        LEFT JOIN users p ON c.plaintiff_id = p.id
        LEFT JOIN countries co ON c.defendant_country_id = co.id
        ORDER BY c.filed_at DESC
    ");
    $stmt->execute();
    $cases = $stmt->fetchAll();

    jsonSuccess(['cases' => $cases]);
}

function getCase($db, $id) {
    $stmt = $db->prepare("
        SELECT c.*, 
               p.username as plaintiff_name,
               co.name as defendant_country_name
        FROM cases c
        LEFT JOIN users p ON c.plaintiff_id = p.id
        LEFT JOIN countries co ON c.defendant_country_id = co.id
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $case = $stmt->fetch();

    if (!$case) {
        jsonError('案件不存在', 404);
    }

    $stmt = $db->prepare("
        SELECT ce.*, u.username as uploader_name
        FROM case_evidence ce
        LEFT JOIN users u ON ce.uploaded_by_user_id = u.id
        WHERE ce.case_id = ?
        ORDER BY ce.uploaded_at
    ");
    $stmt->execute([$id]);
    $case['evidence'] = $stmt->fetchAll();

    jsonSuccess(['case' => $case]);
}

function createCase($db, $auth) {
    if (!$auth->isLoggedIn()) {
        jsonError('请先登录', 401);
    }

    $user = $auth->getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $title = sanitizeInput($input['title'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $defendantCountryId = isset($input['defendant_country_id']) ? (int)$input['defendant_country_id'] : null;

    if (empty($title) || empty($description)) {
        jsonError('请填写所有必填项');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO cases (title, description, plaintiff_id, defendant_country_id, status) 
            VALUES (?, ?, ?, ?, 'filed')
        ");
        $stmt->execute([$title, $description, $user['id'], $defendantCountryId]);
        
        $caseId = $db->lastInsertId();
        
        $stmt = $db->prepare("SELECT COALESCE(MAX(id), 0) as max_id FROM cases");
        $stmt->execute();
        $row = $stmt->fetch();
        $caseNumber = '案字第' . str_pad($row['max_id'], 3, '0', STR_PAD_LEFT) . '号';
        
        $stmt = $db->prepare("UPDATE cases SET case_number = ? WHERE id = ?");
        $stmt->execute([$caseNumber, $caseId]);
        
        $db->commit();
        
        jsonSuccess(['case_id' => $caseId, 'case_number' => $caseNumber], '案件提交成功');
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('提交失败: ' . $e->getMessage());
    }
}

function updateCase($db, $auth, $id) {
    if (!$auth->hasRole('peacekeeper')) {
        jsonError('只有维和部队才能更新案件', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $status = sanitizeInput($input['status'] ?? '');
    $judgment = isset($input['judgment']) ? sanitizeInput($input['judgment']) : null;

    $db->beginTransaction();
    try {
        $updateData = ['status' => $status];
        $sql = "UPDATE cases SET status = ?";
        $params = [$status];
        
        if ($judgment) {
            $sql .= ", judgment = ?, judged_at = NOW()";
            $params[] = $judgment;
        }
        
        if ($status === 'closed') {
            $sql .= ", judged_at = NOW()";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        if ($status === 'closed') {
            $stmt = $db->prepare("SELECT case_number, title, judgment FROM cases WHERE id = ?");
            $stmt->execute([$id]);
            $case = $stmt->fetch();
            
            $stmt = $db->prepare("
                INSERT INTO arbitration_archive (case_id, case_number, title, judgment) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$id, $case['case_number'], $case['title'], $case['judgment']]);
        }
        
        $db->commit();
        jsonSuccess(null, '案件更新成功');
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('更新失败: ' . $e->getMessage());
    }
}

try {
    $db = getDBConnection();
    $auth = new Auth($db);

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    switch ($method) {
        case 'GET':
            if ($id) {
                getCase($db, $id);
            } else {
                getCases($db);
            }
            break;
        case 'POST':
            createCase($db, $auth);
            break;
        case 'PUT':
            if ($id) {
                updateCase($db, $auth, $id);
            }
            break;
        default:
            jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('CASES', '未捕获异常', [
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



