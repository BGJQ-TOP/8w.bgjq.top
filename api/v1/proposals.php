<?php
require_once __DIR__ . '/../../php/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function getProposals($db) {
    $stmt = $db->prepare(" 
        SELECT p.*, 
               c.name as country_name,
               u.username as proposer_name
        FROM proposals p
        JOIN users u ON p.proposer_id = u.id
        LEFT JOIN countries c ON p.country_id = c.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute();
    $proposals = $stmt->fetchAll();

    foreach ($proposals as &$proposal) {
        $proposal['votes'] = getVoteStats($db, $proposal['id']);
    }

    jsonSuccess(['proposals' => $proposals]);
}

function getProposal($db, $id) {
    $stmt = $db->prepare(" 
        SELECT p.*, 
               c.name as country_name,
               u.username as proposer_name
        FROM proposals p
        JOIN users u ON p.proposer_id = u.id
        LEFT JOIN countries c ON p.country_id = c.id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $proposal = $stmt->fetch();

    if (!$proposal) {
        jsonError('提案不存在', 404);
    }

    $proposal['votes'] = getVoteStats($db, $id);
    
    $stmt = $db->prepare(" 
        SELECT v.*, u.username, c.name as country_name
        FROM votes v
        JOIN users u ON v.user_id = u.id
        LEFT JOIN countries c ON v.country_id = c.id
        WHERE v.proposal_id = ?
    ");
    $stmt->execute([$id]);
    $proposal['vote_details'] = $stmt->fetchAll();

    jsonSuccess(['proposal' => $proposal]);
}

function getVoteStats($db, $proposalId) {
    $stmt = $db->prepare(" 
        SELECT 
            vote, 
            COUNT(*) as count
        FROM votes 
        WHERE proposal_id = ?
        GROUP BY vote
    ");
    $stmt->execute([$proposalId]);
    $results = $stmt->fetchAll();

    $stats = ['for' => 0, 'against' => 0, 'abstain' => 0];
    foreach ($results as $row) {
        $stats[$row['vote']] = (int)$row['count'];
    }
    return $stats;
}

function createProposal($db, $auth) {
    if (!$auth->hasRole('diplomat')) {
        jsonError('只有邦国外交官才能发布提案', 403);
    }

    $user = $auth->getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $title = sanitizeInput($input['title'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $type = sanitizeInput($input['type'] ?? 'other');

    if (empty($title) || empty($description)) {
        jsonError('请填写所有必填项');
    }

    $stmt = $db->prepare(" 
        INSERT INTO proposals (title, description, type, proposer_id, country_id, status) 
        VALUES (?, ?, ?, ?, ?, 'draft')
    ");
    $stmt->execute([$title, $description, $type, $user['id'], $user['country_id']]);

    jsonSuccess(['proposal_id' => $db->lastInsertId()], '提案创建成功');
}

function updateProposal($db, $auth, $id) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能更新提案状态', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $status = sanitizeInput($input['status'] ?? '');
    $votingStart = isset($input['voting_start']) ? $input['voting_start'] : null;
    $votingEnd = isset($input['voting_end']) ? $input['voting_end'] : null;

    $stmt = $db->prepare(" 
        UPDATE proposals 
        SET status = ?, voting_start = ?, voting_end = ?
        WHERE id = ?
    ");
    $stmt->execute([$status, $votingStart, $votingEnd, $id]);

    if ($status === 'passed') {
        $stmt = $db->prepare("SELECT title, description FROM proposals WHERE id = ?");
        $stmt->execute([$id]);
        $proposal = $stmt->fetch();
        
        $stmt = $db->prepare(" 
            INSERT INTO conventions (title, content, proposal_id, enacted_by_user_id) 
            VALUES (?, ?, ?, ?)
        ");
        $user = $auth->getCurrentUser();
        $stmt->execute([$proposal['title'], $proposal['description'], $id, $user['id']]);
    }

    jsonSuccess(null, '提案状态更新成功');
}

function handleVote($db, $auth, $proposalId) {
    if (!$auth->hasRole('diplomat')) {
        jsonError('只有邦国外交官才能投票', 403);
    }

    $user = $auth->getCurrentUser();
    $input = json_decode(file_get_contents('php://input'), true);
    $vote = sanitizeInput($input['vote'] ?? '');

    if (!in_array($vote, ['for', 'against', 'abstain'])) {
        jsonError('无效的投票选项');
    }

    $stmt = $db->prepare("SELECT status, voting_start, voting_end FROM proposals WHERE id = ?");
    $stmt->execute([$proposalId]);
    $proposal = $stmt->fetch();

    if (!$proposal || $proposal['status'] !== 'voting') {
        jsonError('该提案当前无法投票');
    }

    $stmt = $db->prepare("SELECT id FROM votes WHERE proposal_id = ? AND user_id = ?");
    $stmt->execute([$proposalId, $user['id']]);
    if ($stmt->fetch()) {
        jsonError('您已经投过票了');
    }

    $hasVeto = $auth->hasRole('permanent_member');

    $stmt = $db->prepare(" 
        INSERT INTO votes (proposal_id, user_id, country_id, vote, has_veto) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$proposalId, $user['id'], $user['country_id'], $vote, $hasVeto]);

    jsonSuccess(null, '投票成功');
}

try {
    $db = getDBConnection();
    $auth = new Auth($db);

    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? '';

    switch ($method) {
    case 'GET':
        if ($id) {
            getProposal($db, $id);
        } else {
            getProposals($db);
        }
        break;
    case 'POST':
        if ($action === 'vote' && $id) {
            handleVote($db, $auth, $id);
        } else {
            createProposal($db, $auth);
        }
        break;
    case 'PUT':
        if ($id) {
            updateProposal($db, $auth, $id);
        }
        break;
    default:
        jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('PROPOSALS', '未捕获异常', [
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



