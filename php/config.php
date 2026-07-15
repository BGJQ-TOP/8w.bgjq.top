<?php
/**
 * 数据库配置文件
 */

// 数据库配置
define('DB_HOST', 'localhost');
define('DB_NAME', 'bgjq');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

// 网站配置
define('SITE_NAME', '8W社区');
define('SITE_URL', 'https://8w.bgjq.top');

// 简幻通（SimpGate）配置
// 验证接口地址
define('SIMPPASS_API_URL', 'https://pass.xiaoli.top/api/simppass/auth');
// 后台提供的 API 调用令牌
define('SIMPPASS_ACCESS_TOKEN', 'YOUR_SIMPPASS_ACCESS_TOKEN');

// 会话配置
define('SESSION_LIFETIME', 86400); // 24小时
define('COOKIE_DOMAIN', '');
define('COOKIE_SECURE', false);

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'domain' => COOKIE_DOMAIN,
        'secure' => COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 角色定义
define('ROLE_SECRETARY_GENERAL', 'secretary_general');
define('ROLE_PERMANENT_MEMBER', 'permanent_member');
define('ROLE_DIPLOMAT', 'diplomat');
define('ROLE_OBSERVER', 'observer');
define('ROLE_PEACEKEEPER', 'peacekeeper');

// 权限检查函数
function hasPermission($requiredRole) {
    if (!isset($_SESSION['user'])) {
        return false;
    }
    
    $userRole = $_SESSION['user']['role'];
    
    $roleHierarchy = [
        ROLE_OBSERVER => 0,
        ROLE_DIPLOMAT => 1,
        ROLE_PEACEKEEPER => 2,
        ROLE_PERMANENT_MEMBER => 3,
        ROLE_SECRETARY_GENERAL => 4
    ];
    
    return $roleHierarchy[$userRole] >= $roleHierarchy[$requiredRole];
}

// 调试日志函数（写入 php/logs/app.log，便于排查问题）
function appLog($tag, $message, $context = []) {
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/app.log';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $ts = date('Y-m-d H:i:s');
    $ctx = empty($context) ? '' : ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $line = "[{$ts}] [{$tag}] {$message}{$ctx}\n";
    if (is_writable($logDir) && $logFile) {
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    } else {
        error_log("[{$tag}] {$message}" . $ctx);
    }
}

// 数据库连接函数
function getDBConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false // 禁用模拟预处理，使用真正的预处理
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        
        // 暂时注释掉自动检查并补全关键字段
        // ensureUsersExtraColumns($pdo);
        
        return $pdo;
    } catch (PDOException $e) {
        appLog('DB', '数据库连接失败', ['message' => $e->getMessage()]);
        throw $e;
    }
}

/**
 * 确保 users 表存在 jhtuid、level 字段，不存在时自动添加
 */
function ensureUsersExtraColumns(PDO $pdo) {
    try {
        $columnsToAdd = [
            'jhtuid' => 'TEXT NULL',
            'level'  => 'TEXT NULL'
        ];
        
        foreach ($columnsToAdd as $column => $definition) {
            // 使用字符串拼接而不是预处理语句
            $stmt = $pdo->query("SHOW COLUMNS FROM `users` LIKE '{$column}'");
            $exists = $stmt->fetch();
            
            if (!$exists) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `{$column}` {$definition}");
                if (function_exists('appLog')) appLog('DB', '已添加列', ['table' => 'users', 'column' => $column]);
            }
        }
    } catch (PDOException $e) {
        if (function_exists('appLog')) appLog('DB', 'ensureUsersExtraColumns 异常', ['message' => $e->getMessage(), 'code' => $e->getCode()]);
    }
}

// JSON响应函数
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 错误响应函数
function jsonError($message, $statusCode = 400) {
    jsonResponse(['error' => $message], $statusCode);
}

// 成功响应函数
function jsonSuccess($data = null, $message = '成功') {
    jsonResponse(['success' => true, 'message' => $message, 'data' => $data]);
}

// 验证输入
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// 自动加载
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/classes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});



