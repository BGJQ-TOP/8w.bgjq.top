<?php
require_once __DIR__ . '/php/config.php';
require_once __DIR__ . '/php/classes/Auth.php';

function checkAdmin() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user'])) {
        header('Location: /admin.html');
        exit;
    }
    
    if ($_SESSION['user']['username'] !== env('ADMIN_USERNAME', 'YOUR_ADMIN_USERNAME')) {
        die('只有管理员才能访问此功能');
    }
    
    return $_SESSION['user'];
}

function getApiKeys($db) {
    $stmt = $db->query("SELECT id, key_name, api_key, api_secret, allowed_ips, rate_limit, is_active, permissions, last_used_at, created_at, expires_at FROM api_keys ORDER BY id DESC");
    return $stmt->fetchAll();
}

function createApiKey($db) {
    $keyName = sanitizeInput($_POST['key_name'] ?? '');
    $allowedIps = sanitizeInput($_POST['allowed_ips'] ?? '');
    $rateLimit = isset($_POST['rate_limit']) ? (int)$_POST['rate_limit'] : 60;
    $permissions = isset($_POST['permissions']) ? implode(',', $_POST['permissions']) : 'users,countries,stats';
    $expiresAt = $_POST['expires_at'] ?? '';
    
    if (empty($keyName)) {
        return ['error' => '请输入密钥名称'];
    }
    
    if (!empty($expiresAt)) {
        $expiresAt = date('Y-m-d H:i:s', strtotime($expiresAt));
    } else {
        $expiresAt = null;
    }
    
    $apiKey = bin2hex(random_bytes(16));
    $apiSecret = bin2hex(random_bytes(24));
    
    try {
        $stmt = $db->prepare("INSERT INTO api_keys (key_name, api_key, api_secret, allowed_ips, rate_limit, permissions, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$keyName, $apiKey, $apiSecret, $allowedIps, $rateLimit, $permissions, $expiresAt]);
        return ['success' => true, 'api_key' => $apiKey, 'api_secret' => $apiSecret];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function toggleApiKey($db, $id, $isActive) {
    $id = (int)$id;
    $stmt = $db->prepare("UPDATE api_keys SET is_active = ? WHERE id = ?");
    $stmt->execute([$isActive ? 1 : 0, $id]);
    return ['success' => true];
}

function deleteApiKey($db, $id) {
    $id = (int)$id;
    $stmt = $db->prepare("DELETE FROM api_keys WHERE id = ?");
    $stmt->execute([$id]);
    return ['success' => true];
}

$message = '';
$messageType = '';

try {
    $user = checkAdmin();
    $db = getDBConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'create') {
                $result = createApiKey($db);
                if (isset($result['error'])) {
                    $message = $result['error'];
                    $messageType = 'error';
                } else {
                    $message = 'API密钥创建成功！API Key: ' . $result['api_key'] . ' | API Secret: ' . $result['api_secret'] . ' (请妥善保存， Secret不会再显示第二次)';
                    $messageType = 'success';
                }
            } elseif ($_POST['action'] === 'toggle') {
                $id = $_POST['id'] ?? 0;
                $isActive = $_POST['is_active'] ?? true;
                toggleApiKey($db, $id, $isActive);
                $message = $isActive ? '已启用' : '已禁用';
                $messageType = 'success';
            } elseif ($_POST['action'] === 'delete') {
                $id = $_POST['id'] ?? 0;
                deleteApiKey($db, $id);
                $message = '删除成功';
                $messageType = 'success';
            }
        }
    }
    
    $apiKeys = getApiKeys($db);
} catch (Exception $e) {
    $message = '错误: ' . $e->getMessage();
    $messageType = 'error';
    $apiKeys = [];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="8W社区API管理后台">
    <meta name="robots" content="noindex, nofollow">
    <title>API管理后台 - 8W社区</title>
    <link rel="stylesheet" href="/css/nes.min.css">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body { font-family: 'ZPix', sans-serif; background: #f5f5f5; }
        .api-key-list { margin-top: 20px; }
        .api-key-item {
            background: #fff;
            border: 2px solid #333;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .api-key-item.inactive { opacity: 0.6; }
        .api-key-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .api-key-name { font-size: 1.2em; font-weight: bold; }
        .api-key-value {
            background: #f0f0f0;
            padding: 10px;
            font-family: monospace;
            word-break: break-all;
            margin: 10px 0;
            border-radius: 4px;
        }
        .api-key-value .secret { color: #e74c3c; font-weight: bold; }
        .api-key-meta { font-size: 0.9em; color: #666; }
        .api-key-meta span { margin-right: 20px; }
        .form-actions { margin-top: 15px; }
        .section { margin-bottom: 30px; }
        .nes-field { margin-bottom: 15px; }
        .nes-field label { display: block; margin-bottom: 5px; font-weight: bold; }
        .nes-field .nes-input, .nes-field .nes-select { width: 100%; }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85em;
        }
        .status-active { background: #4caf50; color: white; }
        .status-inactive { background: #999; color: white; }
        .permissions-tag {
            display: inline-block;
            background: #2196f3;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            margin-right: 5px;
            font-size: 0.85em;
        }
        .message-box {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .message-box.success { background: #d4edda; border: 2px solid #28a745; color: #155724; }
        .message-box.error { background: #f8d7da; border: 2px solid #dc3545; color: #721c24; }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <a href="/">
                        <img src="/images/logo.webp" alt="社区Logo" class="logo-img" onerror="this.style.display='none'">
                        <div class="header-text">
                            <h1>8W社区</h1>
                            <p class="subtitle">API管理后台</p>
                        </div>
                    </a>
                </div>
                <div class="user-panel">
                    <span class="user-info"><?php echo htmlspecialchars($user['username']); ?></span>
                    <a href="/admin.html" class="nes-btn">返回管理后台</a>
                    <a href="/api/v1/auth.php?action=logout" class="nes-btn">退出</a>
                </div>
            </div>
        </div>
    </header>

    <nav>
        <div class="container">
            <ul class="nav-menu">
                <li><a href="/admin.html" class="nav-link">返回管理后台</a></li>
                <li><a href="#" class="nav-link active">API密钥管理</a></li>
            </ul>
        </div>
    </nav>

    <main>
        <div class="container">
            <?php if ($message): ?>
            <div class="message-box <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <section class="section">
                <h2 class="section-title">API密钥列表</h2>
                <div id="apiKeyList" class="api-key-list">
                    <?php if (empty($apiKeys)): ?>
                        <p>暂无API密钥，请创建一个</p>
                    <?php else: ?>
                        <?php foreach ($apiKeys as $key): ?>
                        <div class="api-key-item <?php echo $key['is_active'] ? '' : 'inactive'; ?>">
                            <div class="api-key-header">
                                <span class="api-key-name"><?php echo htmlspecialchars($key['key_name']); ?></span>
                                <span class="status-badge <?php echo $key['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $key['is_active'] ? '启用' : '禁用'; ?>
                                </span>
                            </div>
                            <div class="api-key-value">
                                <strong>API Key:</strong> <?php echo htmlspecialchars($key['api_key']); ?><br>
                                <strong class="secret">API Secret:</strong> <?php echo htmlspecialchars($key['api_secret']); ?>
                            </div>
                            <div class="api-key-meta">
                                <span>权限: 
                                    <?php 
                                    $perms = explode(',', $key['permissions']);
                                    foreach ($perms as $p): 
                                    ?>
                                        <span class="permissions-tag"><?php echo htmlspecialchars(trim($p)); ?></span>
                                    <?php endforeach; ?>
                                </span>
                                <span>速率限制: <?php echo (int)$key['rate_limit']; ?>次/分钟</span>
                                <span>IP限制: <?php echo $key['allowed_ips'] ?: '无'; ?></span>
                            </div>
                            <div class="api-key-meta" style="margin-top: 5px;">
                                <span>创建时间: <?php echo $key['created_at']; ?></span>
                                <span>最后使用: <?php echo $key['last_used_at'] ?: '从未'; ?></span>
                                <span>过期时间: <?php echo $key['expires_at'] ?: '永不过期'; ?></span>
                            </div>
                            <div class="form-actions">
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo $key['id']; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo $key['is_active'] ? '0' : '1'; ?>">
                                    <button type="submit" class="nes-btn is-primary">
                                        <?php echo $key['is_active'] ? '禁用' : '启用'; ?>
                                    </button>
                                </form>
                                <form method="post" style="display:inline;" onsubmit="return confirm('确定要删除这个API密钥吗？此操作不可恢复！');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $key['id']; ?>">
                                    <button type="submit" class="nes-btn is-error">删除</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">创建新API密钥</h2>
                <div class="nes-container with-title">
                    <h3 class="title">新建API Key</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="create">
                        <div class="nes-field">
                            <label for="keyName">密钥名称 <span class="required">*</span></label>
                            <input type="text" name="key_name" id="keyName" class="nes-input" placeholder="例如：第三方应用A" required>
                        </div>
                        <div class="nes-field">
                            <label for="allowedIps">允许的IP (留空表示不限)</label>
                            <input type="text" name="allowed_ips" id="allowedIps" class="nes-input" placeholder="例如：192.168.1.1,10.0.0.0/24">
                            <small style="color: #666;">多个IP用逗号分隔，支持CIDR格式</small>
                        </div>
                        <div class="nes-field">
                            <label for="rateLimit">每分钟调用次数限制</label>
                            <input type="number" name="rate_limit" id="rateLimit" class="nes-input" value="60" min="1" max="1000">
                        </div>
                        <div class="nes-field">
                            <label>权限范围</label>
                            <div style="margin-top: 5px;">
                                <label>
                                    <input type="checkbox" class="nes-checkbox" name="permissions[]" value="users" checked>
                                    <span>用户数据 (users)</span>
                                </label>
                                <label style="margin-left: 20px;">
                                    <input type="checkbox" class="nes-checkbox" name="permissions[]" value="countries" checked>
                                    <span>国家数据 (countries)</span>
                                </label>
                                <label style="margin-left: 20px;">
                                    <input type="checkbox" class="nes-checkbox" name="permissions[]" value="stats" checked>
                                    <span>统计数据 (stats)</span>
                                </label>
                            </div>
                        </div>
                        <div class="nes-field">
                            <label for="expiresAt">过期时间 (留空表示永不过期)</label>
                            <input type="datetime-local" name="expires_at" id="expiresAt" class="nes-input">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="nes-btn is-primary">创建API密钥</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
