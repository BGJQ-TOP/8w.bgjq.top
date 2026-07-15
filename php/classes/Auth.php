<?php
class Auth {
    private $db;

    public function __construct($db) {
        $this->db = $db;
        $this->ensureTablesExist();
    }

    private function ensureTablesExist() {
        try {
            // 创建sessions表
            $this->db->exec("CREATE TABLE IF NOT EXISTS sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                ip_address VARCHAR(45) DEFAULT '',
                user_agent TEXT DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 创建online_players表
            $this->db->exec("CREATE TABLE IF NOT EXISTS online_players (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                game_id VARCHAR(255) NOT NULL,
                country_id INT DEFAULT NULL,
                last_seen DATETIME NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE SET NULL,
                UNIQUE KEY (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {
            if (function_exists('appLog')) {
                appLog('AUTH', '创建表失败', ['message' => $e->getMessage()]);
            }
        }
    }

    public function register($username, $password, $gameId, $countryId = null, $jhtuid = null, $level = null) {
        if (function_exists('appLog')) {
            appLog('AUTH_DB', 'register 开始', ['username' => $username, 'game_id' => $gameId, 'country_id' => $countryId, 'jhtuid' => $jhtuid, 'level' => $level]);
        }
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            if (function_exists('appLog')) appLog('AUTH_DB', '用户名已存在', ['username' => $username]);
            return ['error' => '用户名已存在'];
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        if (function_exists('appLog')) appLog('AUTH_DB', '执行 INSERT users');
        $stmt = $this->db->prepare("INSERT INTO users (username, password, game_id, country_id, jhtuid, level, role) VALUES (?, ?, ?, ?, ?, ?, 'observer')");
        $stmt->execute([$username, $hashedPassword, $gameId, $countryId, $jhtuid, $level]);
        $userId = $this->db->lastInsertId();
        if (function_exists('appLog')) appLog('AUTH_DB', 'INSERT 成功', ['user_id' => $userId]);
        
        return ['success' => true, 'user_id' => $userId];
    }

    public function login($username, $password) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                return ['error' => '用户名或密码错误'];
            }

            // 生成token
            if (function_exists('random_bytes')) {
                $token = bin2hex(random_bytes(32));
            } else {
                $token = bin2hex(openssl_random_pseudo_bytes(32));
            }
            $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

            // 插入会话记录
            try {
                $stmt = $this->db->prepare("INSERT INTO sessions (user_id, token, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $user['id'],
                    $token,
                    $expiresAt,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
            } catch (Exception $e) {
                if (function_exists('appLog')) {
                    appLog('AUTH', '插入会话失败', ['message' => $e->getMessage()]);
                }
                // 会话插入失败不影响登录
            }

            // 更新在线状态
            try {
                $this->updateOnlineStatus($user['id'], $user['game_id'], $user['country_id']);
            } catch (Exception $e) {
                if (function_exists('appLog')) {
                    appLog('AUTH', '更新在线状态失败', ['message' => $e->getMessage()]);
                }
                // 在线状态更新失败不影响登录
            }

            // 设置会话变量
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'game_id' => $user['game_id'],
                'country_id' => $user['country_id'],
                'role' => $user['role']
            ];
            $_SESSION['token'] = $token;

            return ['success' => true, 'user' => $_SESSION['user'], 'token' => $token];
        } catch (Exception $e) {
            if (function_exists('appLog')) {
                appLog('AUTH', '登录失败', ['message' => $e->getMessage()]);
            }
            return ['error' => '登录失败: ' . $e->getMessage()];
        }
    }

    public function logout() {
        if (isset($_SESSION['token'])) {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE token = ?");
            $stmt->execute([$_SESSION['token']]);
        }
        session_destroy();
        return ['success' => true];
    }

    public function getCurrentUser() {
        return $_SESSION['user'] ?? null;
    }

    public function isLoggedIn() {
        return isset($_SESSION['user']);
    }

    public function hasRole($requiredRole) {
        if (!$this->isLoggedIn()) {
            return false;
        }

        $roles = [
            'observer' => 0,
            'diplomat' => 1,
            'peacekeeper' => 2,
            'permanent_member' => 3,
            'secretary_general' => 4
        ];

        $userRole = $_SESSION['user']['role'];
        return $roles[$userRole] >= $roles[$requiredRole];
    }

    private function updateOnlineStatus($userId, $gameId, $countryId) {
        $stmt = $this->db->prepare("SELECT id FROM online_players WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        if ($stmt->fetch()) {
            $stmt = $this->db->prepare("UPDATE online_players SET last_seen = NOW() WHERE user_id = ?");
            $stmt->execute([$userId]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO online_players (user_id, game_id, country_id, last_seen) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$userId, $gameId, $countryId]);
        }
    }

    public function resetPassword($userId, $oldPassword, $newPassword) {
        try {
            $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($oldPassword, $user['password'])) {
                return ['error' => '原密码错误'];
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $userId]);

            return ['success' => true];
        } catch (Exception $e) {
            if (function_exists('appLog')) {
                appLog('AUTH', '重置密码失败', ['message' => $e->getMessage()]);
            }
            return ['error' => '重置密码失败: ' . $e->getMessage()];
        }
    }

    public function getUserByUsername($username) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->fetch();
    }
}



