<?php

namespace W8\Passport;

use RuntimeException;
use W8\Passport\Contracts\CountryProvider;
use W8\Passport\Contracts\EmailVerifier;
use W8\Passport\Contracts\PlayerProvider;
use W8\Passport\Contracts\SimpassVerifier;
use W8\Passport\Directory\CountryDirectory;
use W8\Passport\Directory\PlayerDirectory;
use W8\Passport\Directory\Providers\HttpCountryProvider;
use W8\Passport\Directory\Providers\HttpPlayerProvider;
use W8\Passport\Directory\Providers\UnavailableCountryProvider;
use W8\Passport\Directory\Providers\UnavailablePlayerProvider;
use W8\Passport\Identity\AccountRepository;
use W8\Passport\Identity\Authenticator;
use W8\Passport\Identity\RegistrationService;
use W8\Passport\Identity\SessionStore;
use W8\Passport\OAuth\AuthorizationCodeRepository;
use W8\Passport\OAuth\ClientRepository;
use W8\Passport\OAuth\OAuthServer;
use W8\Passport\OAuth\TokenRepository;
use W8\Passport\Support\Config;
use W8\Passport\Support\Database;
use W8\Passport\Support\HttpClient;
use W8\Passport\Support\Logger;
use W8\Passport\Verification\EmailCodeService;
use W8\Passport\Verification\HttpEmailVerifier;
use W8\Passport\Verification\HttpSimpassVerifier;
use W8\Passport\Verification\UnavailableEmailVerifier;
use W8\Passport\Verification\UnavailableSimpassVerifier;

/**
 * 通行证应用容器
 *
 * 项目没有 Composer / DI 框架，这里用最朴素的方式把依赖一次性装配好：
 * 全部懒加载（用到才 new），这样单个请求不会白白建立连接或构造对象。
 *
 * 想换掉某个数据源（例如接入新的玩家查询接口），只需要改这里的 resolve* 方法，
 * 或者直接在外部注入实现。
 */
final class Application
{
    /** @var Application|null */
    private static $instance;

    /** @var Config */
    private $config;

    /** @var Logger|null */
    private $logger;

    /** @var Database|null */
    private $db;

    /** @var HttpClient|null */
    private $http;

    /** @var PlayerProvider|null */
    private $playerProvider;

    /** @var CountryProvider|null */
    private $countryProvider;

    /** @var EmailVerifier|null */
    private $emailVerifier;

    /** @var SimpassVerifier|null */
    private $simpassVerifier;

    /** @var array<string,object> */
    private $services = array();

    /** @var string|null 当前请求的第三方应用，仅用于日志 */
    private $apiClientId;

    /** @var int|null 当前请求的通行证UID，仅用于日志 */
    private $apiAccountId;

    private function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 装配应用（幂等）
     *
     * @param Config|null $config
     * @return Application
     */
    public static function boot(Config $config = null)
    {
        if (self::$instance === null) {
            if ($config === null) {
                $config = Config::fromEnvFile(W8_PASSPORT_ROOT);
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * @return Application
     */
    public static function instance()
    {
        if (self::$instance === null) {
            throw new RuntimeException('通行证应用尚未启动，请先 require bootstrap.php');
        }
        return self::$instance;
    }

    /**
     * 仅供测试：重置单例
     */
    public static function reset()
    {
        self::$instance = null;
    }

    public function config()
    {
        return $this->config;
    }

    public function logger()
    {
        if ($this->logger === null) {
            $this->logger = new Logger(
                $this->config->path('passport/storage/logs'),
                $this->config->isDebug()
            );
        }
        return $this->logger;
    }

    public function db()
    {
        if ($this->db === null) {
            $this->db = new Database($this->config, $this->logger());
        }
        return $this->db;
    }

    public function http()
    {
        if ($this->http === null) {
            $this->http = new HttpClient($this->logger());
        }
        return $this->http;
    }

    // ========================================================================
    //  身份
    // ========================================================================

    public function accounts()
    {
        return $this->shared('accounts', function () {
            return new AccountRepository($this->db());
        });
    }

    public function sessions()
    {
        return $this->shared('sessions', function () {
            return new SessionStore($this->db(), $this->config, $this->logger());
        });
    }

    public function authenticator()
    {
        return $this->shared('authenticator', function () {
            return new Authenticator($this->accounts(), $this->sessions(), $this->logger());
        });
    }

    public function registration()
    {
        return $this->shared('registration', function () {
            return new RegistrationService(
                $this->db(),
                $this->accounts(),
                $this->players(),
                $this->countries(),
                $this->emailCodes(),
                $this->simpassVerifier(),
                $this->config,
                $this->logger()
            );
        });
    }

    // ========================================================================
    //  权威数据目录
    // ========================================================================

    public function playerProvider()
    {
        if ($this->playerProvider === null) {
            $this->playerProvider = $this->config->has('PLAYER_API_BASE')
                ? new HttpPlayerProvider($this->config, $this->http(), $this->logger())
                : new UnavailablePlayerProvider();
        }
        return $this->playerProvider;
    }

    public function countryProvider()
    {
        if ($this->countryProvider === null) {
            $this->countryProvider = $this->config->has('COUNTRY_API_BASE')
                ? new HttpCountryProvider($this->config, $this->http(), $this->logger())
                : new UnavailableCountryProvider();
        }
        return $this->countryProvider;
    }

    public function players()
    {
        return $this->shared('players', function () {
            return new PlayerDirectory($this->db(), $this->playerProvider(), $this->config, $this->logger());
        });
    }

    public function countries()
    {
        return $this->shared('countries', function () {
            return new CountryDirectory(
                $this->db(),
                $this->countryProvider(),
                $this->players(),
                $this->config,
                $this->logger()
            );
        });
    }

    // ========================================================================
    //  验证
    // ========================================================================

    public function emailVerifier()
    {
        if ($this->emailVerifier === null) {
            $this->emailVerifier = $this->config->has('EMAIL_API_URL')
                ? new HttpEmailVerifier($this->config, $this->http(), $this->logger())
                : new UnavailableEmailVerifier();
        }
        return $this->emailVerifier;
    }

    public function emailCodes()
    {
        return $this->shared('email_codes', function () {
            return new EmailCodeService($this->db(), $this->emailVerifier(), $this->config, $this->logger());
        });
    }

    public function simpassVerifier()
    {
        if ($this->simpassVerifier === null) {
            $this->simpassVerifier = $this->config->has('SIMPASS_API_URL')
                ? new HttpSimpassVerifier($this->config, $this->http(), $this->logger())
                : new UnavailableSimpassVerifier();
        }
        return $this->simpassVerifier;
    }

    // ========================================================================
    //  OAuth2
    // ========================================================================

    public function oauthClients()
    {
        return $this->shared('oauth_clients', function () {
            return new ClientRepository($this->db());
        });
    }

    public function oauthCodes()
    {
        return $this->shared('oauth_codes', function () {
            return new AuthorizationCodeRepository($this->db(), $this->config);
        });
    }

    public function oauthTokens()
    {
        return $this->shared('oauth_tokens', function () {
            return new TokenRepository($this->db(), $this->config);
        });
    }

    public function oauth()
    {
        return $this->shared('oauth', function () {
            return new OAuthServer(
                $this->oauthClients(),
                $this->oauthCodes(),
                $this->oauthTokens(),
                $this->accounts(),
                $this->config,
                $this->logger()
            );
        });
    }

    // ========================================================================
    //  请求级上下文（仅用于 API 日志）
    // ========================================================================

    public function markApiContext($clientId = null, $accountId = null)
    {
        $this->apiClientId = $clientId !== null ? (string) $clientId : null;
        $this->apiAccountId = $accountId !== null ? (int) $accountId : null;
    }

    public function currentClientId()
    {
        return $this->apiClientId;
    }

    public function currentAccountId()
    {
        return $this->apiAccountId;
    }

    /**
     * 运行期覆盖绑定（测试 / 定制部署用）
     *
     * @param string $name players|countries|email_verifier|simpass_verifier|player_provider|country_provider
     * @param object $instance
     */
    public function bind($name, $instance)
    {
        switch ($name) {
            case 'player_provider':
                $this->playerProvider = $instance;
                break;
            case 'country_provider':
                $this->countryProvider = $instance;
                break;
            case 'email_verifier':
                $this->emailVerifier = $instance;
                break;
            case 'simpass_verifier':
                $this->simpassVerifier = $instance;
                break;
            default:
                $this->services[$name] = $instance;
        }
    }

    /**
     * 懒加载单例
     *
     * @param string $key
     * @param callable $factory
     * @return object
     */
    private function shared($key, $factory)
    {
        if (!isset($this->services[$key])) {
            $this->services[$key] = call_user_func($factory);
        }
        return $this->services[$key];
    }
}
