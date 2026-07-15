<?php
require_once __DIR__ . '/../../php/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function getCountries($db) {
    $stmt = $db->prepare("
        SELECT c.*, 
               COUNT(u.id) as member_count
        FROM countries c
        LEFT JOIN users u ON c.id = u.country_id
        WHERE c.is_active = TRUE
        GROUP BY c.id
        ORDER BY c.joined_at DESC
    ");
    $stmt->execute();
    $countries = $stmt->fetchAll();
    
    jsonSuccess(['countries' => $countries]);
}

function getCountry($db, $id) {
    $stmt = $db->prepare("
        SELECT c.*, 
               COUNT(u.id) as member_count
        FROM countries c
        LEFT JOIN users u ON c.id = u.country_id
        WHERE c.id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$id]);
    $country = $stmt->fetch();
    
    if (!$country) {
        jsonError('邦国不存在', 404);
    }
    
    $stmt = $db->prepare("
        SELECT u.* 
        FROM users u 
        WHERE u.country_id = ?
        ORDER BY u.username
    ");
    $stmt->execute([$id]);
    $members = $stmt->fetchAll();
    
    jsonSuccess(['country' => $country, 'members' => $members]);
}

function getDiplomaticRelations($db, $countryId) {
    $stmt = $db->prepare("
        SELECT dr.*, 
               c1.name as country1_name,
               c2.name as country2_name
        FROM diplomatic_relations dr
        JOIN countries c1 ON dr.country1_id = c1.id
        JOIN countries c2 ON dr.country2_id = c2.id
        WHERE dr.country1_id = ? OR dr.country2_id = ?
    ");
    $stmt->execute([$countryId, $countryId]);
    $relations = $stmt->fetchAll();
    
    jsonSuccess(['relations' => $relations]);
}

function setDiplomaticRelation($db, $auth, $countryId) {
    if (!$auth->hasRole('diplomat')) {
        jsonError('只有邦国外交官才能设置外交关系', 403);
    }
    
    $user = $auth->getCurrentUser();
    
    if ($user['country_id'] != $countryId && !$auth->hasRole('secretary_general')) {
        jsonError('只能设置自己邦国的外交关系', 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $targetCountryId = (int)($input['target_country_id'] ?? 0);
    $relation = sanitizeInput($input['relation'] ?? 'neutral');
    
    if (!in_array($relation, ['friendly', 'hostile', 'neutral', 'ceasefire'])) {
        jsonError('无效的外交关系');
    }
    
    if ($targetCountryId == $countryId) {
        jsonError('不能设置与自己的外交关系');
    }
    
    $stmt = $db->prepare("SELECT id FROM countries WHERE id = ? AND is_active = TRUE");
    $stmt->execute([$targetCountryId]);
    if (!$stmt->fetch()) {
        jsonError('目标邦国不存在');
    }
    
    $stmt = $db->prepare("
        SELECT id FROM diplomatic_relations 
        WHERE (country1_id = ? AND country2_id = ?) OR (country1_id = ? AND country2_id = ?)
    ");
    $stmt->execute([$countryId, $targetCountryId, $targetCountryId, $countryId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        $stmt = $db->prepare("
            UPDATE diplomatic_relations 
            SET relation = ?, set_by_user_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$relation, $user['id'], $existing['id']]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO diplomatic_relations (country1_id, country2_id, relation, set_by_user_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$countryId, $targetCountryId, $relation, $user['id']]);
    }
    
    jsonSuccess(null, '外交关系设置成功');
}

function updateCountry($db, $auth, $id) {
    if (!$auth->hasRole('diplomat')) {
        jsonError('只有邦国外交官才能更新邦国信息', 403);
    }
    
    $user = $auth->getCurrentUser();
    
    if ($user['country_id'] != $id && !$auth->hasRole('secretary_general')) {
        jsonError('只能更新自己邦国的信息', 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $updates = [];
    $params = [];
    
    if (isset($input['declaration'])) {
        $updates[] = 'declaration = ?';
        $params[] = sanitizeInput($input['declaration']);
    }
    if (isset($input['government_type'])) {
        $updates[] = 'government_type = ?';
        $params[] = sanitizeInput($input['government_type']);
    }
    if (isset($input['population'])) {
        $updates[] = 'population = ?';
        $params[] = (int)$input['population'];
    }
    if (isset($input['territory_chunks'])) {
        $updates[] = 'territory_chunks = ?';
        $params[] = (int)$input['territory_chunks'];
    }
    if (isset($input['flag_url'])) {
        $updates[] = 'flag_url = ?';
        $params[] = sanitizeInput($input['flag_url']);
    }
    
    if (empty($updates)) {
        jsonError('没有要更新的内容');
    }
    
    $params[] = $id;
    
    $sql = 'UPDATE countries SET ' . implode(', ', $updates) . ' WHERE id = ?';
    
    // 记录SQL语句和参数
    appLog('UPDATE_COUNTRY', '执行更新', [
        'sql' => $sql,
        'params' => $params,
        'country_id' => $id
    ]);
    
    $stmt = $db->prepare($sql);
    $result = $stmt->execute($params);
    
    if (!$result) {
        $errorInfo = $stmt->errorInfo();
        appLog('UPDATE_COUNTRY', '更新失败', [
            'error' => $errorInfo[2],
            'sql' => $sql,
            'params' => $params
        ]);
        jsonError('更新邦国信息失败: ' . $errorInfo[2]);
    }
    
    // 检查受影响的行数
    $rowCount = $stmt->rowCount();
    appLog('UPDATE_COUNTRY', '更新成功', [
        'country_id' => $id,
        'affected_rows' => $rowCount
    ]);
    
    jsonSuccess(null, '邦国信息更新成功');

}

function getAllCountriesAdmin($db) {
    $stmt = $db->prepare("
        SELECT c.*, 
               COUNT(u.id) as member_count
        FROM countries c
        LEFT JOIN users u ON c.id = u.country_id
        GROUP BY c.id
        ORDER BY c.joined_at DESC
    ");
    $stmt->execute();
    $countries = $stmt->fetchAll();
    
    jsonSuccess(['countries' => $countries]);
}

function createCountry($db, $auth) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能创建邦国', 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $name = sanitizeInput($input['name'] ?? '');
    $declaration = isset($input['declaration']) ? sanitizeInput($input['declaration']) : null;
    $governmentType = isset($input['government_type']) ? sanitizeInput($input['government_type']) : 'other';
    $population = isset($input['population']) ? (int)$input['population'] : null;
    $territoryChunks = isset($input['territory_chunks']) ? (int)$input['territory_chunks'] : null;
    $flagUrl = isset($input['flag_url']) ? sanitizeInput($input['flag_url']) : null;
    
    if (empty($name)) {
        jsonError('请填写邦国名称');
    }
    
    if (!in_array($governmentType, ['monarchy', 'democracy', 'guild', 'other'])) {
        jsonError('无效的政体类型');
    }
    
    $stmt = $db->prepare("SELECT id FROM countries WHERE name = ?");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        jsonError('邦国名称已存在');
    }
    
    $stmt = $db->prepare("
        INSERT INTO countries (name, declaration, government_type, population, territory_chunks, flag_url)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$name, $declaration, $governmentType, $population, $territoryChunks, $flagUrl]);
    
    jsonSuccess(['country_id' => $db->lastInsertId()], '邦国创建成功');
}

function toggleCountryActive($db, $auth, $id) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能管理邦国状态', 403);
    }
    
    $stmt = $db->prepare("SELECT id, is_active FROM countries WHERE id = ?");
    $stmt->execute([$id]);
    $country = $stmt->fetch();
    
    if (!$country) {
        jsonError('邦国不存在', 404);
    }
    
    $newStatus = !$country['is_active'];
    
    $stmt = $db->prepare("UPDATE countries SET is_active = ? WHERE id = ?");
    $stmt->execute([$newStatus ? 1 : 0, $id]);
    
    jsonSuccess(null, $newStatus ? '邦国已激活' : '邦国已停用');
}

function deleteCountry($db, $auth, $id) {
    if (!$auth->hasRole('secretary_general')) {
        jsonError('只有秘书长才能删除邦国', 403);
    }
    
    $stmt = $db->prepare("SELECT id FROM countries WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        jsonError('邦国不存在', 404);
    }
    
    $stmt = $db->prepare("UPDATE users SET country_id = NULL WHERE country_id = ?");
    $stmt->execute([$id]);
    
    $stmt = $db->prepare("DELETE FROM diplomatic_relations WHERE country1_id = ? OR country2_id = ?");
    $stmt->execute([$id, $id]);
    
    $stmt = $db->prepare("DELETE FROM countries WHERE id = ?");
    $stmt->execute([$id]);
    
    jsonSuccess(null, '邦国删除成功');
}

function getCountryByName($db, $name) {
    // 从外部API获取邦国信息
    $url = "https://api.bgjq.top/api/info/country/" . urlencode($name);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (is_array($data) && $data['success']) {
            jsonSuccess(['country' => $data['country']]);
        } else {
            jsonError('邦国不存在');
        }
    } else {
        jsonError('获取邦国信息失败，HTTP状态码: ' . $httpCode . ', 响应: ' . substr($response, 0, 100));
    }
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
            if ($action === 'relations') {
                getDiplomaticRelations($db, $id);
            } else if ($action === 'all' && $auth->hasRole('secretary_general')) {
                getAllCountriesAdmin($db);
            } else {
                getCountry($db, $id);
            }
        } else {
            if ($action === 'all' && $auth->hasRole('secretary_general')) {
                getAllCountriesAdmin($db);
            } else if ($action === 'name' && isset($_GET['name'])) {
                getCountryByName($db, $_GET['name']);
            } else {
                getCountries($db);
            }
        }
        break;
    case 'POST':
        if ($action === 'relation' && $id) {
            setDiplomaticRelation($db, $auth, $id);
        } else if ($auth->hasRole('secretary_general')) {
            createCountry($db, $auth);
        }
        break;
    case 'PUT':
        if ($id) {
            if ($action === 'toggle' && $auth->hasRole('secretary_general')) {
                toggleCountryActive($db, $auth, $id);
            } else {
                updateCountry($db, $auth, $id);
            }
        }
        break;
    case 'DELETE':
        if ($id && $auth->hasRole('secretary_general')) {
            deleteCountry($db, $auth, $id);
        }
        break;
    default:
        jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('COUNTRIES', '未捕获异常', [
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



