<?php
require_once __DIR__ . '/../../php/config.php';
require_once __DIR__ . '/../../php/classes/MCServerPing.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $db = getDBConnection();

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            getServerInfo($db);
            break;
        default:
            jsonError('不支持的请求方法');
    }
} catch (Throwable $e) {
    appLog('SERVER', '未捕获异常', [
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

function getServerInfo($db) {
    $serverHost = 'bgjq.simpfun.cn';
    $serverPort = 25565;

    $serverInfo = [
        'motd' => '',
        'online_players' => [],
        'player_count' => 0,
        'max_players' => 0,
        'version' => '',
        'protocol' => 0
    ];

    try {
        // 使用 Minecraft 官方协议 Ping
        $socket = @fsockopen($serverHost, $serverPort, $errno, $errstr, 3);
        if (!$socket) {
            throw new Exception("无法连接服务器: $errstr ($errno)");
        }

        // 发送握手包
        $data = pack('C', 0x00); // 包 ID
        $data .= packC(strlen($serverHost)) . $serverHost; // 地址
        $data .= pack('n', $serverPort); // 端口
        $data .= packC(1); // 状态（1 表示请求状态）
        sendPacket($socket, $data);

        // 发送请求包
        sendPacket($socket, pack('C', 0x00));

        // 读取响应
        $response = readPacket($socket);
        fclose($socket);

        // 解析 JSON 响应
        $json = json_decode($response, true);
        if (!$json) {
            throw new Exception("响应解析失败");
        }

        // 处理 MOTD
        if (isset($json['description'])) {
            if (is_array($json['description']) && isset($json['description']['text'])) {
                $serverInfo['motd'] = $json['description']['text'];
            } elseif (is_string($json['description'])) {
                $serverInfo['motd'] = $json['description'];
            }
        }
        
        $serverInfo['max_players'] = isset($json['players']['max']) ? (int)$json['players']['max'] : 0;
        $serverInfo['player_count'] = isset($json['players']['online']) ? (int)$json['players']['online'] : 0;
        $serverInfo['version'] = $json['version']['name'] ?? '';
        $serverInfo['protocol'] = isset($json['version']['protocol']) ? (int)$json['version']['protocol'] : 0;

        // 在线玩家列表
        if (!empty($json['players']['sample']) && is_array($json['players']['sample'])) {
            $userNames = array_column($json['players']['sample'], 'name');
            if (!empty($userNames)) {
                $placeholders = implode(',', array_fill(0, count($userNames), '?'));
                $stmt = $db->prepare("
                    SELECT u.username, c.name as country_name 
                    FROM users u 
                    LEFT JOIN countries c ON u.country_id = c.id
                    WHERE u.username IN ($placeholders)
                ");
                $stmt->execute($userNames);
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $userCountryMap = array_column($users, 'country_name', 'username');

                foreach ($json['players']['sample'] as $p) {
                    if (isset($p['name'])) {
                        $name = $p['name'];
                        $serverInfo['online_players'][] = [
                            'name' => $name,
                            'country' => $userCountryMap[$name] ?? '未知'
                        ];
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('获取MC服务器信息失败: ' . $e->getMessage());
        // 出错时保持空数据，不填充默认值
    }

    jsonSuccess(['server' => $serverInfo]);
}

// 辅助：发送数据包
function sendPacket($socket, $payload) {
    $len = strlen($payload);
    while ($len > 0) {
        $byte = $len & 0x7F;
        $len >>= 7;
        if ($len > 0) $byte |= 0x80;
        fwrite($socket, chr($byte));
    }
    fwrite($socket, $payload);
}

// 辅助：读取数据包
function readPacket($socket) {
    $len = readVarInt($socket);
    $data = fread($socket, $len);
    if (strlen($data) < $len) {
        throw new Exception("数据包读取不完整");
    }
    return $data;
}

// 辅助：读取 VarInt
function readVarInt($socket) {
    $value = 0;
    $shift = 0;
    do {
        $byteData = fread($socket, 1);
        if ($byteData === false || $byteData === '') {
            throw new Exception("读取 VarInt 失败");
        }
        $byte = ord($byteData);
        $value |= ($byte & 0x7F) << $shift;
        $shift += 7;
        if ($shift > 32) {
            throw new Exception("VarInt 过长");
        }
    } while ($byte & 0x80);
    return $value;
}

// 辅助：打包 VarInt
function packC($value) {
    $res = '';
    while ($value > 0x7F) {
        $res .= chr(($value & 0x7F) | 0x80);
        $value >>= 7;
    }
    $res .= chr($value);
    return $res;
}




