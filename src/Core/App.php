<?php

declare(strict_types=1);

namespace Sso\Core;

use Sso\Data\Database;
use Sso\Services\AppService;
use Sso\Services\AuditService;
use Sso\Services\AuthService;
use Sso\Services\PasswordBroker;
use Sso\Services\RateLimiter;
use Sso\Services\TokenService;
use Sso\Services\UserService;

/**
 * ظرف برنامه (Container / Service locator بسیار ساده).
 */
final class App
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $config;

    private ?Database $db = null;

    /** @var array<class-string, object> */
    private array $services = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        self::$instance = $this;
    }

    public static function boot(?string $configFile = null): self
    {
        $file = $configFile ?? SSO_CONFIG_FILE;
        if (!is_file($file)) {
            throw new \RuntimeException('فایل تنظیمات یافت نشد. ابتدا نصب را انجام دهید: /setup.php');
        }
        /** @var array<string, mixed> $config */
        $config = require $file;

        $defaults = self::defaults();
        $config = array_replace_recursive($defaults, $config);

        $app = new self($config);

        date_default_timezone_set((string) $app->config('timezone', 'UTC'));
        if ($app->config('debug', false)) {
            error_reporting(E_ALL);
            ini_set('display_errors', '0');
        } else {
            error_reporting(E_ALL & ~E_DEPRECATED);
            ini_set('display_errors', '0');
        }
        // تنظیمات session فقط پیش از ارسال خروجی قابل تغییرند (در CLI/embed بعد از خروجی نادیده گرفته می‌شود)
        if (!headers_sent()) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.gc_maxlifetime', (string) max(1800, (int) $app->config('security.session_idle_minutes', 120) * 60));
        }

        return $app;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'debug' => false,
            'app_name' => 'سامانه احراز هویت',
            'base_url' => '',
            'app_key' => '',
            'timezone' => 'UTC',
            'db' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'sso',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
                'unix_socket' => null,
                'path' => null,
            ],
            'security' => [
                'password_min_length' => 8,
                'password_algorithm' => 'auto',
                'login_max_attempts' => 5,
                'login_lockout_minutes' => 15,
                'access_token_ttl' => 3600,
                'refresh_token_ttl' => 2592000,
                'api_rate_limit_per_minute' => 600,
                'admin_session_idle_minutes' => 120,
                'admin_ip_whitelist' => [],
                'trusted_proxies' => [],
            ],
        ];
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('برنامه هنوز راه‌اندازی نشده است.');
        }
        return self::$instance;
    }

    public static function isBooted(): bool
    {
        return self::$instance !== null;
    }

    /**
     * خواندن تنظیمات با مسیر نقطه‌ای: db.host
     */
    public function config(string $key = '', mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->config;
        }
        $segments = explode('.', $key);
        $value = $this->config;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function db(): Database
    {
        if ($this->db === null) {
            /** @var array<string, mixed> $dbConfig */
            $dbConfig = (array) $this->config('db', []);
            if (($dbConfig['path'] ?? null) === null) {
                $dbConfig['path'] = SSO_STORAGE . '/database/sso.sqlite';
            }
            $this->db = new Database($dbConfig);
        }
        return $this->db;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function service(string $class): object
    {
        if (isset($this->services[$class])) {
            /** @var T */
            return $this->services[$class];
        }

        $service = match ($class) {
            TokenService::class => new TokenService($this->db()),
            AuthService::class => new AuthService($this->db()),
            UserService::class => new UserService($this->db()),
            AppService::class => new AppService($this->db()),
            AuditService::class => new AuditService($this->db()),
            RateLimiter::class => new RateLimiter($this->db()),
            PasswordBroker::class => new PasswordBroker($this->db()),
            default => throw new \InvalidArgumentException('سرویس ناشناخته: ' . $class),
        };

        $this->services[$class] = $service;
        return $service;
    }

    public function tokens(): TokenService
    {
        return $this->service(TokenService::class);
    }

    public function auth(): AuthService
    {
        return $this->service(AuthService::class);
    }

    public function users(): UserService
    {
        return $this->service(UserService::class);
    }

    public function apps(): AppService
    {
        return $this->service(AppService::class);
    }

    public function audit(): AuditService
    {
        return $this->service(AuditService::class);
    }

    public function limiter(): RateLimiter
    {
        return $this->service(RateLimiter::class);
    }

    public function passwords(): PasswordBroker
    {
        return $this->service(PasswordBroker::class);
    }
}
