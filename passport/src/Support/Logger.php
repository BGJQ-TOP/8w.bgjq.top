<?php

namespace W8\Passport\Support;

/**
 * 极简文件日志
 *
 * 写 passport/storage/logs/passport-YYYY-MM-DD.log。
 * 目录不可写时退回 error_log，绝不让日志问题拖垮业务请求。
 */
final class Logger
{
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';

    /** @var string */
    private $directory;

    /** @var bool */
    private $debug;

    /**
     * 需要打码的键名（按完整键名匹配）
     *
     * 新增带密钥语义的字段名时必须同步加进这个名单。
     * 注意 URL 里的查询串不走这里，由 HttpClient::sanitizeUrl() 单独处理。
     *
     * @var array<int,string>
     */
    private $redactKeys = array(
        'password', 'password_hash', 'client_secret', 'secret',
        'token', 'access_token', 'refresh_token', 'accesstoken',
        'code', 'pass_code', 'passcode', 'verify_code',
        'email_code', 'simpass_code', 'fanverify_code',
        'otp', 'api_key', 'apikey', 'api_secret',
    );

    public function __construct($directory, $debug = false)
    {
        $this->directory = rtrim((string) $directory, "/\\");
        $this->debug = (bool) $debug;
    }

    public function debug($event, array $context = array())
    {
        if ($this->debug) {
            $this->write(self::DEBUG, $event, $context);
        }
    }

    public function info($event, array $context = array())
    {
        $this->write(self::INFO, $event, $context);
    }

    public function warning($event, array $context = array())
    {
        $this->write(self::WARNING, $event, $context);
    }

    public function error($event, array $context = array())
    {
        $this->write(self::ERROR, $event, $context);
    }

    private function write($level, $event, array $context)
    {
        $line = sprintf(
            "[%s] [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $event,
            empty($context) ? '' : ' ' . json_encode($this->redact($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if (!$this->ensureDirectory()) {
            error_log(trim($line));
            return;
        }

        @file_put_contents($this->directory . '/passport-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    private function ensureDirectory()
    {
        if (is_dir($this->directory)) {
            return is_writable($this->directory);
        }
        return @mkdir($this->directory, 0755, true) || is_dir($this->directory);
    }

    /**
     * 抹掉上下文里的敏感值，避免密钥/令牌进日志
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function redact(array $context)
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->redact($value);
                continue;
            }
            if (in_array(strtolower((string) $key), $this->redactKeys, true)) {
                $context[$key] = '***';
            }
        }
        return $context;
    }
}
