<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 处理OPTIONS请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

// 包含数据库配置
require_once '../../php/config.php';

// 获取数据库连接
$pdo = getDBConnection();

// 自动创建services表
$createTableSql = "CREATE TABLE IF NOT EXISTS `services` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `url` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
$pdo->exec($createTableSql);

// 包含认证类
require_once '../../php/classes/Auth.php';
$auth = new Auth($pdo);

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'];

// 获取请求参数
$postData = json_decode(file_get_contents('php://input'), true);

// 处理不同的请求方法
try {
    switch ($method) {
        case 'GET':
            // 获取所有服务
            $stmt = $pdo->prepare('SELECT * FROM services ORDER BY id DESC');
            $stmt->execute();
            $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => ['services' => $services]
            ]);
            break;
            
        case 'POST':
            // 添加服务
            // 验证用户是否为秘书长
            $user = $auth->getCurrentUser();
            if (!$user || $user['role'] !== 'secretary_general') {
                throw new Exception('只有秘书长才能添加服务');
            }
            
            // 验证必填字段
            if (!isset($postData['name']) || !isset($postData['url'])) {
                throw new Exception('缺少必填字段');
            }
            
            // 插入服务
            $stmt = $pdo->prepare('INSERT INTO services (name, url, created_at) VALUES (?, ?, NOW())');
            $stmt->execute([$postData['name'], $postData['url']]);
            
            $newService = [
                'id' => $pdo->lastInsertId(),
                'name' => $postData['name'],
                'url' => $postData['url'],
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            echo json_encode([
                'success' => true,
                'data' => ['service' => $newService],
                'message' => '服务添加成功'
            ]);
            break;
            
        case 'PUT':
            // 更新服务
            // 验证用户是否为秘书长
            $user = $auth->getCurrentUser();
            if (!$user || $user['role'] !== 'secretary_general') {
                throw new Exception('只有秘书长才能编辑服务');
            }
            
            // 获取服务ID
            $serviceId = $_GET['id'] ?? null;
            if (!$serviceId) {
                throw new Exception('缺少服务ID');
            }
            
            // 验证必填字段
            if (!isset($postData['name']) || !isset($postData['url'])) {
                throw new Exception('缺少必填字段');
            }
            
            // 更新服务
            $stmt = $pdo->prepare('UPDATE services SET name = ?, url = ? WHERE id = ?');
            $stmt->execute([$postData['name'], $postData['url'], $serviceId]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('服务不存在');
            }
            
            echo json_encode([
                'success' => true,
                'message' => '服务更新成功'
            ]);
            break;
            
        case 'DELETE':
            // 删除服务
            // 验证用户是否为秘书长
            $user = $auth->getCurrentUser();
            if (!$user || $user['role'] !== 'secretary_general') {
                throw new Exception('只有秘书长才能删除服务');
            }
            
            // 获取服务ID
            $serviceId = $_GET['id'] ?? null;
            if (!$serviceId) {
                throw new Exception('缺少服务ID');
            }
            
            // 删除服务
            $stmt = $pdo->prepare('DELETE FROM services WHERE id = ?');
            $stmt->execute([$serviceId]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('服务不存在');
            }
            
            echo json_encode([
                'success' => true,
                'message' => '服务删除成功'
            ]);
            break;
            
        default:
            throw new Exception('不支持的请求方法');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>


