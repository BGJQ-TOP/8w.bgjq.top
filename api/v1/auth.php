<?php
require_once __DIR__ . '/../../php/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    appLog('AUTH', '请求开始', ['method' => $_SERVER['REQUEST_METHOD'], 'action' => $_GET['action'] ?? '', 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
    $db = getDBConnection();
    appLog('AUTH', '数据库连接成功');
    $auth = new Auth($db);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    switch ($method) {
    case 'POST':
        if ($action === 'register') {
            handleRegister($db, $auth);
        } elseif ($action === 'login') {
            handleLogin($db, $auth);
        } elseif ($action === 'reset-password') {
            handleResetPassword($db, $auth);
        } else {
            jsonError('无效的操作');
        }
        break;
    case 'GET':
        if ($action === 'current') {
            getCurrentUser($auth);
        } elseif ($action === 'verify-player' && isset($_GET['player'])) {
            verifyPlayer($_GET['player']);
        } else {
            jsonError('无效的操作');
        }
        break;
    case 'DELETE':
        if ($action === 'logout') {
            handleLogout($auth);
        } else {
            jsonError('无效的操作');
        }
        break;
    default:
        jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('AUTH', '未捕获异常', [
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

function handleRegister($db, $auth) {
    $rawInput = file_get_contents('php://input');
    appLog('AUTH', '注册 raw input', ['raw' => substr($rawInput, 0, 500)]);
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        appLog('AUTH', '注册 JSON 解析失败', ['raw' => $rawInput]);
        jsonError('请求体格式错误');
    }
    
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $gameId = sanitizeInput($input['game_id'] ?? '');
    $countryName = isset($input['country_name']) ? sanitizeInput($input['country_name']) : null;
    $jhtuid = isset($input['jhtuid']) ? (int)$input['jhtuid'] : 0;
    $verifyCode = isset($input['verify_code']) ? (int)$input['verify_code'] : 0;
    appLog('AUTH', '注册参数解析', ['username' => $username, 'game_id' => $gameId, 'jhtuid' => $jhtuid, 'verify_code' => $verifyCode, 'country' => $countryName]);
    
    $countryId = null;
    if ($countryName) {
        $stmt = $db->prepare("SELECT id FROM countries WHERE name = ? AND is_active = TRUE");
        $stmt->execute([$countryName]);
        $country = $stmt->fetch();
        if ($country) {
            $countryId = $country['id'];
        } else {
            $stmt = $db->prepare("INSERT INTO countries (name, declaration, government_type) VALUES (?, '新加入的邦国', 'other')");
            $stmt->execute([$countryName]);
            $countryId = $db->lastInsertId();
        }
    }

    if (empty($username) || empty($password) || empty($gameId)) {
        jsonError('请填写所有必填项');
    }

    if (strlen($password) < 6) {
        jsonError('密码至少需要6个字符');
    }

    if ($jhtuid <= 0 || $verifyCode <= 0) {
        jsonError('请填写正确的简幻通UID和验证码');
    }

    // 验证游戏玩家是否存在，以及是否属于用户提供的国家
    if ($countryName) {
        appLog('AUTH', '开始验证玩家信息', ['game_id' => $gameId, 'country_name' => $countryName]);
        $playerResult = verifyPlayerFaction($gameId, $countryName);
        appLog('AUTH', '玩家验证结果', $playerResult);
        
        if (!$playerResult['ok']) {
            jsonError($playerResult['message'], 400);
        }
    }

    // 调用简幻通接口验证用户身份
    appLog('AUTH', '开始调用简幻通验证', ['jhtuid' => $jhtuid, 'verify_code' => $verifyCode, 'mc_username' => $gameId]);
    $simppassResult = simppassVerify($jhtuid, $verifyCode, $gameId);
    appLog('AUTH', '简幻通验证结果', $simppassResult);
    
    if (!$simppassResult['ok']) {
        appLog('AUTH', '简幻通验证失败，拒绝注册', ['message' => $simppassResult['message']]);
        jsonError('简幻通验证失败：' . $simppassResult['message'], 400);
    }

    $verifiedUid = $simppassResult['uid'];
    $verifiedLevel = (string)$simppassResult['level'];
    appLog('AUTH', '准备写入数据库', ['verified_uid' => $verifiedUid, 'verified_level' => $verifiedLevel]);

    $result = $auth->register($username, $password, $gameId, $countryId, $verifiedUid, $verifiedLevel);
    appLog('AUTH', '数据库写入完成', ['user_id' => $result['user_id'] ?? null]);
    
    if (isset($result['error'])) {
        appLog('AUTH', '注册业务错误', ['error' => $result['error']]);
        jsonError($result['error']);
    }

    appLog('AUTH', '注册成功', ['user_id' => $result['user_id'] ?? null]);
    jsonSuccess($result, '注册成功');
}

/**
 * 调用简幻通（Simppass）接口验证用户身份
 *
 * @param int $userId      简幻通用户UID
 * @param int $verifyCode  小程序获取的验证码
 * @param string $mcUsername Minecraft 用户名
 * @return array ['ok' => bool, 'uid' => int, 'level' => int, 'message' => string]
 */
/**
 * 验证玩家是否存在且属于指定国家
 *
 * @param string $playerName 玩家游戏ID
 * @param string $countryName 用户提供的国家名称
 * @return array ['ok' => bool, 'faction' => string, 'message' => string]
 */
function verifyPlayerFaction($playerName, $countryName) {
    $apiUrl = env('INTERNAL_API_HOST', 'http://YOUR_API_HOST:PORT') . "/bgjq/data/player/" . urlencode($playerName);
    
    appLog('PLAYER_VERIFY', '开始查询玩家信息', ['player' => $playerName, 'url' => $apiUrl]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrNo = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // 网络错误处理
    if ($curlErrNo !== 0) {
        appLog('PLAYER_VERIFY', '网络错误', ['player' => $playerName, 'error_no' => $curlErrNo, 'error' => $curlError]);
        return [
            'ok' => false,
            'faction' => '',
            'message' => '玩家查询服务暂时不可用，请稍后重试'
        ];
    }
    
    // HTTP 错误处理
    if ($httpCode !== 200) {
        appLog('PLAYER_VERIFY', 'HTTP错误', ['player' => $playerName, 'http_code' => $httpCode, 'response' => substr($response, 0, 500)]);
        
        if ($httpCode === 404) {
            return [
                'ok' => false,
                'faction' => '',
                'message' => '玩家不存在，请检查游戏ID是否正确'
            ];
        }
        
        return [
            'ok' => false,
            'faction' => '',
            'message' => '玩家查询服务异常，请稍后重试'
        ];
    }
    
    // 解析响应
    $data = json_decode($response, true);
    if (!is_array($data)) {
        appLog('PLAYER_VERIFY', '响应解析失败', ['player' => $playerName, 'response' => $response]);
        return [
            'ok' => false,
            'faction' => '',
            'message' => '玩家信息查询失败'
        ];
    }
    
    if (!isset($data['success']) || !$data['success']) {
        appLog('PLAYER_VERIFY', '查询失败', ['player' => $playerName, 'response' => $data]);
        return [
            'ok' => false,
            'faction' => '',
            'message' => '玩家不存在或查询失败'
        ];
    }
    
    // 获取玩家邦国信息
    $playerData = $data['data'] ?? [];
    $faction = $playerData['faction'] ?? null;
    
    if (!$faction) {
        appLog('PLAYER_VERIFY', '玩家无邦国信息', ['player' => $playerName, 'data' => $playerData]);
        return [
            'ok' => false,
            'faction' => '',
            'message' => '该玩家尚未加入任何邦国'
        ];
    }
    
    // 比对国家名称
    if ($faction !== $countryName) {
        appLog('PLAYER_VERIFY', '国家不匹配', ['player' => $playerName, 'expected' => $countryName, 'actual' => $faction]);
        return [
            'ok' => false,
            'faction' => $faction,
            'message' => "该玩家属于「{$faction}」，而非您填写的「{$countryName}」"
        ];
    }
    
    appLog('PLAYER_VERIFY', '验证通过', ['player' => $playerName, 'faction' => $faction]);
    return [
        'ok' => true,
        'faction' => $faction,
        'message' => 'OK'
    ];
}

function simppassVerify($userId, $verifyCode, $mcUsername) {
    if (empty(SIMPPASS_API_URL) || empty(SIMPPASS_ACCESS_TOKEN)) {
        appLog('SIMPPASS', '未配置 API URL 或 Token');
        return [
            'ok' => false,
            'uid' => 0,
            'level' => 0,
            'message' => '服务器未配置简幻通验证参数'
        ];
    }

    // 新接口参数通过 query string 传递
    $queryParams = http_build_query([
        'token'       => SIMPPASS_ACCESS_TOKEN,
        'user_id'     => $userId,
        'verify_code' => $verifyCode,
        'mc_username' => $mcUsername,
        'mc_uuid'     => '',
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
    $requestUrl = SIMPPASS_API_URL . '?' . $queryParams;
    appLog('SIMPPASS', '请求参数', ['url' => SIMPPASS_API_URL, 'user_id' => $userId, 'verify_code' => $verifyCode, 'mc_username' => $mcUsername]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $requestUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $responseBody = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErr   = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    appLog('SIMPPASS', '响应', ['http_code' => $httpCode, 'curl_errno' => $curlErrNo, 'curl_error' => $curlErr, 'body_preview' => substr((string)$responseBody, 0, 500)]);

    if ($curlErrNo !== 0) {
        appLog('SIMPPASS', 'CURL 错误', ['errno' => $curlErrNo, 'error' => $curlErr]);
        return [
            'ok' => false,
            'uid' => 0,
            'level' => 0,
            'message' => '网络错误：' . $curlErr
        ];
    }

    if ($httpCode !== 200) {
        appLog('SIMPPASS', 'HTTP 非 200', ['http_code' => $httpCode, 'body' => $responseBody]);
        return [
            'ok' => false,
            'uid' => 0,
            'level' => 0,
            'message' => '远程服务返回状态码 ' . $httpCode
        ];
    }

    $data = json_decode($responseBody, true);
    if (!is_array($data)) {
        appLog('SIMPPASS', '响应 JSON 解析失败', ['body' => $responseBody]);
        return [
            'ok' => false,
            'uid' => 0,
            'level' => 0,
            'message' => '响应解析失败'
        ];
    }

    if (!isset($data['code']) || $data['code'] !== 200) {
        $msg = isset($data['msg']) ? $data['msg'] : '验证失败';
        appLog('SIMPPASS', '业务 code 非 200', ['code' => $data['code'] ?? null, 'msg' => $msg, 'data' => $data]);
        return [
            'ok' => false,
            'uid' => 0,
            'level' => 0,
            'message' => $msg
        ];
    }

    $userInfo = $data['user_info'] ?? null;
    if (!$userInfo || !isset($userInfo['simpass_uid'], $userInfo['level'])) {
        appLog('SIMPPASS', '返回数据缺少 user_info 或字段', ['user_info' => $userInfo]);
        return [
            'ok' => false,
            'uid' => 0,
            'level' => 0,
            'message' => '返回数据缺少用户信息'
        ];
    }

    return [
        'ok' => true,
        'uid' => (int)$userInfo['simpass_uid'],
        'level' => (int)$userInfo['level'],
        'message' => 'OK'
    ];
}

function handleLogin($db, $auth) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        jsonError('请填写用户名和密码');
    }

    $result = $auth->login($username, $password);
    
    if (isset($result['error'])) {
        jsonError($result['error'], 401);
    }

    jsonSuccess($result, '登录成功');
}

function handleLogout($auth) {
    $result = $auth->logout();
    jsonSuccess($result, '登出成功');
}

function getCurrentUser($auth) {
    $user = $auth->getCurrentUser();
    if (!$user) {
        jsonError('未登录', 401);
    }
    jsonSuccess(['user' => $user]);
}

function verifyPlayer($playerName) {
    $url = "https://api.bgjq.top/api/info/player/{$playerName}";
    
    appLog('PLAYER_VERIFY', '开始验证玩家', ['player' => $playerName, 'url' => $url]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrNo = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // 网络错误处理
    if ($curlErrNo !== 0) {
        appLog('PLAYER_VERIFY', '网络错误', ['player' => $playerName, 'error_no' => $curlErrNo, 'error' => $curlError]);
        jsonError('验证服务暂时不可用，请稍后重试');
    }
    
    // HTTP 错误处理
    if ($httpCode !== 200) {
        appLog('PLAYER_VERIFY', 'HTTP错误', ['player' => $playerName, 'http_code' => $httpCode, 'response' => substr($response, 0, 500)]);
        
        // 尝试解析外部API的错误信息
        $errorMessage = '验证服务异常，请稍后重试';
        if ($response) {
            $errorData = json_decode($response, true);
            if (is_array($errorData) && isset($errorData['error'])) {
                $errorMessage = $errorData['error'];
            } elseif (is_array($errorData) && isset($errorData['message'])) {
                $errorMessage = $errorData['message'];
            }
        }
        
        // 根据HTTP状态码提供更具体的错误信息
        if ($httpCode === 400) {
            $errorMessage = '请求参数错误：' . ($errorMessage !== '验证服务异常，请稍后重试' ? $errorMessage : '请检查游戏ID格式');
        } elseif ($httpCode === 404) {
            $errorMessage = '玩家ID不存在';
        } elseif ($httpCode >= 500) {
            $errorMessage = '验证服务暂时不可用，请稍后重试';
        }
        
        jsonError($errorMessage);
    }
    
    if ($response) {
        $data = json_decode($response, true);
        if (is_array($data) && $data['success']) {
            appLog('PLAYER_VERIFY', '验证成功', ['player' => $playerName]);
            jsonSuccess(['player' => $data]);
        } else {
            appLog('PLAYER_VERIFY', '玩家ID不存在', ['player' => $playerName, 'response' => $data]);
            jsonError('玩家ID不存在');
        }
    } else {
        appLog('PLAYER_VERIFY', '空响应', ['player' => $playerName]);
        jsonError('验证失败，请稍后重试');
    }
}

function handleResetPassword($db, $auth) {
    $rawInput = file_get_contents('php://input');
    appLog('AUTH', '重置密码 raw input', ['raw' => substr($rawInput, 0, 500)]);
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        appLog('AUTH', '重置密码 JSON 解析失败', ['raw' => $rawInput]);
        jsonError('请求体格式错误');
    }
    
    $username = sanitizeInput($input['username'] ?? '');
    $oldPassword = $input['old_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    $jhtuid = isset($input['jhtuid']) ? (int)$input['jhtuid'] : 0;
    $verifyCode = isset($input['verify_code']) ? (int)$input['verify_code'] : 0;
    appLog('AUTH', '重置密码参数解析', ['username' => $username, 'jhtuid' => $jhtuid, 'verify_code' => $verifyCode]);
    
    if (empty($username) || empty($oldPassword) || empty($newPassword)) {
        jsonError('请填写所有必填项');
    }

    if (strlen($newPassword) < 6) {
        jsonError('新密码至少需要6个字符');
    }

    if ($jhtuid <= 0 || $verifyCode <= 0) {
        jsonError('请填写正确的简幻通UID和验证码');
    }

    // 获取用户信息
    $user = $auth->getUserByUsername($username);
    if (!$user) {
        jsonError('用户不存在');
    }

    // 调用简幻通接口验证用户身份
    appLog('AUTH', '开始调用简幻通验证', ['jhtuid' => $jhtuid, 'verify_code' => $verifyCode, 'mc_username' => $user['game_id']]);
    $simppassResult = simppassVerify($jhtuid, $verifyCode, $user['game_id']);
    appLog('AUTH', '简幻通验证结果', $simppassResult);
    
    if (!$simppassResult['ok']) {
        appLog('AUTH', '简幻通验证失败，拒绝重置密码', ['message' => $simppassResult['message']]);
        jsonError('简幻通验证失败：' . $simppassResult['message'], 400);
    }

    // 验证简幻通UID是否与用户注册时的一致，或者为老用户添加简幻通ID
    if ($user['jhtuid'] && $simppassResult['uid'] != $user['jhtuid']) {
        jsonError('简幻通UID与注册时不一致');
    }

    // 如果用户没有绑定简幻通ID，更新用户数据
    if (!$user['jhtuid']) {
        $stmt = $db->prepare("UPDATE users SET jhtuid = ?, level = ? WHERE id = ?");
        $stmt->execute([$simppassResult['uid'], $simppassResult['level'], $user['id']]);
        appLog('AUTH', '为老用户添加简幻通ID', ['user_id' => $user['id'], 'jhtuid' => $simppassResult['uid'], 'level' => $simppassResult['level']]);
    }

    // 重置密码
    $result = $auth->resetPassword($user['id'], $oldPassword, $newPassword);
    appLog('AUTH', '重置密码结果', $result);
    
    if (isset($result['error'])) {
        appLog('AUTH', '重置密码业务错误', ['error' => $result['error']]);
        jsonError($result['error']);
    }

    appLog('AUTH', '重置密码成功', ['user_id' => $user['id']]);
    jsonSuccess($result, '密码重置成功');
}



