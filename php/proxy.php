<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$name = $_GET['name'] ?? '';

if (empty($action) || empty($name)) {
    echo json_encode(['success' => false, 'message' => '缺少必要参数']);
    exit;
}

$url = '';
if ($action === 'player') {
    $url = "https://api.bgjq.top/api/info/player/{$name}";
} elseif ($action === 'country') {
    $url = "https://api.bgjq.top/api/info/country/{$name}";
} else {
    echo json_encode(['success' => false, 'message' => '无效的操作']);
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $response) {
    echo $response;
} else {
    echo json_encode(['success' => false, 'message' => 'API请求失败']);
}
?>


