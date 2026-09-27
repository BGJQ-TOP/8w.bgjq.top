<?php

namespace W8\Passport\Support;

use RuntimeException;

/**
 * 配置读取器
 *
 * 只从 .env 读，绝不硬编码密钥。
 * 与 php/config.php 的 loadEnv 保持同样的解析规则，重复加载是幂等的。
 */
final class Config
{
    /** @var array<string,string> */
    private $values = array();

    /** @var string */
    private $rootPath;

    /** @var array<string,mixed> */
    private $defaults = array(
        'DB_HOST'                  => 'localhost',
        'DB_PORT'                  => '3306',
        'DB_NAME'                  => '',
        'DB_USER'                  => '',
        'DB_PASS'                  => '',
        'DB_CHARSET'               => 'utf8mb4',

        // ---------- 通行证自身 ----------
        'PASSPORT_BASE_URL'        => '',
        'PASSPORT_SESSION_TTL'     => '86400',      // 登录态有效期（秒）
        'PASSPORT_OAUTH_CODE_TTL'  => '300',        // 授权码有效期（秒）
        'PASSPORT_ACCESS_TTL'      => '7200',       // 访问令牌有效期（秒）
        'PASSPORT_REFRESH_TTL'     => '2592000',    // 刷新令牌有效期（秒）
        'PASSPORT_COOKIE_NAME'     => 'w8_passport',
        'PASSPORT_COOKIE_DOMAIN'   => '',
        'PASSPORT_ADMIN_ROLES'     => 'secretary_general',
        'PASSPORT_DEBUG'           => '0',
        // 仅本地联调用：必须与 PASSPORT_DEBUG 同时开启才生效，生产环境务必保持 0
        'PASSPORT_DEV_BYPASS_VERIFICATION' => '0',

        // ---------- 权威数据接口：游戏内玩家（TODO：接口待对接）----------
        'PLAYER_API_BASE'          => '',
        'PLAYER_API_PATH'          => '/player/{player}',
        'PLAYER_API_TOKEN'         => '',
        'PLAYER_API_TIMEOUT'       => '8',
        'PLAYER_API_SUCCESS_FIELD' => '',
        'PLAYER_API_NAME_FIELD'    => '',
        'PLAYER_API_ID_FIELD'      => '',
        'PLAYER_API_COUNTRY_FIELD' => '',

        // ---------- 权威数据接口：邦国（TODO：接口待对接）----------
        'COUNTRY_API_BASE'         => '',
        'COUNTRY_API_PATH'         => '/country/{country_id}',
        'COUNTRY_API_PATH_BY_NAME' => '',
        'COUNTRY_API_TOKEN'        => '',
        'COUNTRY_API_TIMEOUT'      => '8',
        'COUNTRY_API_SUCCESS_FIELD' => '',
        'COUNTRY_API_ID_FIELD'     => '',
        'COUNTRY_API_NAME_FIELD'   => '',
        'COUNTRY_API_DECLARATION_FIELD' => '',
        'COUNTRY_API_TERRITORY_FIELD'   => '',
        'COUNTRY_API_PLAYERS_FIELD'     => '',
        'COUNTRY_API_PLAYER_NAME_FIELD' => '',
        'COUNTRY_API_PLAYER_ID_FIELD'   => '',

        'DIRECTORY_CACHE_TTL'      => '600',        // 权威数据缓存有效期（秒）

        // ---------- 简幻通（TODO：接口待对接）----------
        'SIMPASS_API_URL'          => '',
        'SIMPPASS_ACCESS_TOKEN'    => '',
        'SIMPASS_API_TIMEOUT'      => '8',
        'SIMPASS_API_METHOD'       => 'POST',
        'SIMPASS_API_SUCCESS_CODE' => '200',
        'SIMPASS_API_CODE_FIELD'   => '',
        'SIMPASS_API_MESSAGE_FIELD' => '',
        'SIMPASS_API_UID_FIELD'    => '',
        'SIMPASS_API_LEVEL_FIELD'  => '',
        'SIMPASS_API_PLAYER_FIELD' => '',

        // ---------- 邮箱验证码（TODO：接口待对接）----------
        'EMAIL_API_URL'            => '',
        'EMAIL_API_TOKEN'          => '',
        'EMAIL_API_TIMEOUT'        => '8',
        'EMAIL_API_METHOD'         => 'POST',
        'EMAIL_API_BODY_TEMPLATE'  => '',
        'EMAIL_API_SUCCESS_FIELD'  => '',
        'EMAIL_API_MESSAGE_FIELD'  => '',
        'EMAIL_CODE_TTL'           => '600',
        'EMAIL_FROM_NAME'          => '8W通行证',
    );

    public function __construct($rootPath, array $values = array())
    {
        $this->rootPath = rtrim((string) $rootPath, "/\\");
        $this->values = $values;
    }

    /**
     * 从项目根目录的 .env 装配配置
     */
    public static function fromEnvFile($rootPath)
    {
        $rootPath = rtrim((string) $rootPath, "/\\");
        $envFile = $rootPath . '/.env';

        $values = array();
        if (is_file($envFile) && is_readable($envFile)) {
            $values = self::parseEnvFile($envFile);
        }

        // 已存在的真实环境变量优先级最高（便于容器/CI 覆盖）
        foreach (array_keys($values) as $key) {
            $fromProcess = getenv($key);
            if ($fromProcess !== false && $fromProcess !== '') {
                $values[$key] = $fromProcess;
            }
        }

        return new self($rootPath, $values);
    }

    /**
     * @return array<string,string>
     */
    private static function parseEnvFile($file)
    {
        $result = array();
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $result;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false || $pos < 1) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // 去掉行尾注释（仅当值未被引号包裹时）
            $quoted = ($value !== '' && ($value[0] === '"' || $value[0] === "'"));
            if (!$quoted) {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            } else {
                $quote = $value[0];
                if (substr($value, -1) === $quote && strlen($value) >= 2) {
                    $value = substr($value, 1, -1);
                }
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public function rootPath()
    {
        return $this->rootPath;
    }

    public function path($relative)
    {
        return $this->rootPath . '/' . ltrim((string) $relative, '/');
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        if (array_key_exists($key, $this->values) && $this->values[$key] !== '') {
            return $this->values[$key];
        }
        if (array_key_exists($key, $this->defaults) && $this->defaults[$key] !== '') {
            return $this->defaults[$key];
        }
        return $default;
    }

    public function getString($key, $default = '')
    {
        $value = $this->get($key, $default);
        return is_scalar($value) ? (string) $value : (string) $default;
    }

    public function getInt($key, $default = 0)
    {
        $value = $this->get($key, null);
        return $value === null || $value === '' ? (int) $default : (int) $value;
    }

    public function getBool($key, $default = false)
    {
        $value = $this->get($key, null);
        if ($value === null || $value === '') {
            return (bool) $default;
        }
        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
    }

    /**
     * 取必填配置，缺失时直接报错，避免带着空配置跑起来
     */
    public function requireString($key)
    {
        $value = $this->getString($key);
        if ($value === '') {
            throw new RuntimeException("缺少必填配置项 {$key}，请检查 .env");
        }
        return $value;
    }

    public function has($key)
    {
        return $this->getString($key) !== '';
    }

    /**
     * 站点基础地址，用于拼接 OAuth 回调
     */
    public function baseUrl()
    {
        $configured = $this->getString('PASSPORT_BASE_URL');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        }

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        return $scheme . '://' . $host;
    }

    public function isDebug()
    {
        return $this->getBool('PASSPORT_DEBUG', false);
    }
}
