<?php
class ApiAuth {
    private $db;
    private $apiKeyRecord = null;

    public function __construct($db) {
        $this->db = $db;
    }

    public function authenticate() {
        $apiKey = $_GET['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

        if (empty($apiKey)) {
            throw new ApiAuthException('缺少API Key', 401);
        }

        $stmt = $this->db->prepare("SELECT * FROM api_keys WHERE api_key = ? AND is_active = TRUE");
        $stmt->execute([$apiKey]);
        $record = $stmt->fetch();

        if (!$record) {
            throw new ApiAuthException('无效的API Key', 401);
        }

        if ($record['expires_at'] && strtotime($record['expires_at']) < time()) {
            throw new ApiAuthException('API Key已过期', 401);
        }

        $allowedIps = $record['allowed_ips'];
        if (!empty($allowedIps)) {
            $ipList = array_map('trim', explode(',', $allowedIps));
            $clientIp = $this->getClientIp();
            if (!in_array($clientIp, $ipList)) {
                throw new ApiAuthException('IP地址不在白名单中', 403);
            }
        }

        $this->apiKeyRecord = $record;
        return $record;
    }

    public function getKeyRecord() {
        return $this->apiKeyRecord;
    }

    public function updateLastUsed() {
        if ($this->apiKeyRecord) {
            $stmt = $this->db->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?");
            $stmt->execute([$this->apiKeyRecord['id']]);
        }
    }

    public function hasPermission($permission) {
        if (!$this->apiKeyRecord) {
            return false;
        }
        $perms = explode(',', $this->apiKeyRecord['permissions']);
        return in_array(trim($permission), $perms);
    }

    private function getClientIp() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        }
        return $ip;
    }

    public function checkRateLimit() {
        if (!$this->apiKeyRecord) {
            return;
        }

        $limit = (int)$this->apiKeyRecord['rate_limit'];
        $keyId = $this->apiKeyRecord['id'];
        $minute = date('Y-m-d H:i');

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as cnt FROM api_logs 
            WHERE api_key_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute([$keyId]);
        $result = $stmt->fetch();

        if ($result['cnt'] >= $limit) {
            throw new ApiAuthException('调用频率超限，请稍后重试', 429);
        }
    }
}

class ApiAuthException extends Exception {
    public function __construct($message, $code = 401) {
        parent::__construct($message, $code);
    }
}
