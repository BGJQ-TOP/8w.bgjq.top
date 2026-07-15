<?php
class ApiLogger {
    private $db;
    private $startTime;
    private $apiKeyId;
    private $apiKey;
    private $endpoint;
    private $method;
    private $queryParams;

    public function __construct($db) {
        $this->db = $db;
        $this->startTime = microtime(true);
    }

    public function setContext($apiKeyId, $apiKey, $endpoint, $method, $queryParams = []) {
        $this->apiKeyId = $apiKeyId;
        $this->apiKey = $apiKey;
        $this->endpoint = $endpoint;
        $this->method = $method;
        $this->queryParams = json_encode($queryParams);
    }

    public function log($responseStatus, $errorMessage = null) {
        $responseTime = (int)((microtime(true) - $this->startTime) * 1000);
        $ipAddress = $this->getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        try {
            $stmt = $this->db->prepare("
                INSERT INTO api_logs (api_key_id, api_key, endpoint, method, query_params, response_status, response_time, ip_address, user_agent, error_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $this->apiKeyId,
                $this->apiKey,
                $this->endpoint,
                $this->method,
                $this->queryParams,
                $responseStatus,
                $responseTime,
                $ipAddress,
                $userAgent,
                $errorMessage
            ]);
        } catch (Exception $e) {
            appLog('API_LOG', '日志记录失败', ['message' => $e->getMessage()]);
        }
    }

    private function getClientIp() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        }
        return $ip;
    }
}
