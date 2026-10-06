<?php

declare(strict_types=1);

namespace Sso\Install;

use Sso\Core\App;
use Sso\Data\Database;
use Sso\Models\Membership;
use Sso\Models\Roles;
use Sso\Models\User;
use Sso\Services\AppService;
use Sso\Services\TokenService;

/**
 * نصب‌کننده: بررسی پیش‌نیازها، ساخت فایل تنظیمات، اجرای اسکیمای دیتابیس،
 * ساخت ادمین اولیه و اپلیکیشن نمونه.
 */
final class Installer
{
    /**
     * @return array<int, array{name: string, ok: bool, message: string}>
     */
    public static function requirements(): array
    {
        $checks = [];

        $checks[] = [
            'name' => 'نسخه PHP',
            'ok' => PHP_VERSION_ID >= 80100,
            'message' => PHP_VERSION . ' (حداقل ۸.۱ توصیه می‌شود)',
        ];
        foreach (['pdo', 'json', 'hash', 'session', 'random'] as $ext) {
            $checks[] = [
                'name' => 'افزونه ' . $ext,
                'ok' => extension_loaded($ext),
                'message' => extension_loaded($ext) ? 'نصب شده' : 'نصب نیست',
            ];
        }
        $checks[] = [
            'name' => 'افزونه pdo_mysql یا pdo_sqlite',
            'ok' => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
            'message' => extension_loaded('pdo_mysql') ? 'pdo_mysql در دسترس است'
                : (extension_loaded('pdo_sqlite') ? 'فقط pdo_sqlite در دسترس است' : 'هیچ‌کدام نصب نیست'),
        ];
        $checks[] = [
            'name' => 'دسترسی نوشتن در storage',
            'ok' => self::ensureDirectory(SSO_STORAGE),
            'message' => self::ensureDirectory(SSO_STORAGE) ? 'قابل نوشتن' : 'قابل نوشتن نیست: ' . SSO_STORAGE,
        ];
        $checks[] = [
            'name' => 'دسترسی نوشتن در config',
            'ok' => self::ensureDirectory(dirname(SSO_CONFIG_FILE)),
            'message' => self::ensureDirectory(dirname(SSO_CONFIG_FILE)) ? 'قابل نوشتن' : 'قابل نوشتن نیست',
        ];

        return $checks;
    }

    public static function ensureDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        return is_dir($path) && is_writable($path);
    }

    /**
     * @return array<int, string>
     */
    public static function missingRequirements(): array
    {
        $missing = [];
        foreach (self::requirements() as $check) {
            if (!$check['ok']) {
                $missing[] = $check['name'] . ': ' . $check['message'];
            }
        }
        return $missing;
    }

    /**
     * تست اتصال به دیتابیس.
     *
     * @param array<string, mixed> $dbConfig
     * @return array{ok: bool, message: string, driver: string, create_database: bool}
     */
    public static function testConnection(array $dbConfig): array
    {
        $driver = (string) ($dbConfig['driver'] ?? 'mysql');

        try {
            if ($driver === 'sqlite') {
                $path = (string) ($dbConfig['path'] ?? (SSO_STORAGE . '/database/sso.sqlite'));
                self::ensureDirectory(dirname($path));
                $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('PRAGMA foreign_keys = ON');
                return ['ok' => true, 'message' => 'اتصال به SQLite برقرار است.', 'driver' => 'sqlite', 'create_database' => false];
            }

            // ابتدا بدون انتخاب دیتابیس وصل می‌شویم تا در صورت نیاز بسازیمش
            $charset = (string) ($dbConfig['charset'] ?? 'utf8mb4');
            $baseDsn = sprintf(
                'mysql:host=%s;port=%d;charset=%s',
                (string) ($dbConfig['host'] ?? '127.0.0.1'),
                (int) ($dbConfig['port'] ?? 3306),
                $charset
            );
            $pdo = new \PDO($baseDsn, (string) ($dbConfig['username'] ?? ''), (string) ($dbConfig['password'] ?? ''), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $database = (string) ($dbConfig['database'] ?? '');
            $exists = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $exists->execute([$database]);

            return [
                'ok' => true,
                'message' => 'اتصال به MySQL برقرار است.',
                'driver' => 'mysql',
                'create_database' => $exists->fetch() === false,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'driver' => $driver, 'create_database' => false];
        }
    }

    /**
     * @param array<string, mixed> $dbConfig
     */
    public static function createDatabase(array $dbConfig): bool
    {
        if ((string) ($dbConfig['driver'] ?? 'mysql') !== 'mysql') {
            return true;
        }
        $charset = (string) ($dbConfig['charset'] ?? 'utf8mb4');
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%d;charset=%s', (string) $dbConfig['host'], (int) $dbConfig['port'], $charset),
            (string) $dbConfig['username'],
            (string) $dbConfig['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $dbConfig['database']);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET ' . $charset . ' COLLATE ' . $charset . '_unicode_ci');
        return true;
    }

    /**
     * اجرای فایل اسکیما روی دیتابیس.
     */
    public static function runSchema(Database $db): void
    {
        $file = $db->isSqlite()
            ? SSO_ROOT . '/database/schema.sqlite.sql'
            : SSO_ROOT . '/database/schema.mysql.sql';

        foreach (self::splitSql((string) file_get_contents($file)) as $statement) {
            $db->pdo()->exec($statement);
        }
    }

    /**
     * @return array<int, string>
     */
    public static function splitSql(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $sql) ?: [] as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $lines[] = $line;
        }
        $sql = implode("\n", $lines);

        $statements = [];
        foreach (explode(';', $sql) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }
        return $statements;
    }

    /**
     * نوشتن فایل تنظیمات.
     *
     * @param array<string, mixed> $dbConfig
     */
    public static function writeConfig(array $dbConfig, string $appKey, string $appName = 'سامانه احراز هویت یکپارچه', string $baseUrl = ''): void
    {
        self::ensureDirectory(dirname(SSO_CONFIG_FILE));

        $driver = (string) ($dbConfig['driver'] ?? 'mysql');
        $content = self::renderConfig([
            'debug' => false,
            'app_name' => $appName,
            'base_url' => $baseUrl,
            'app_key' => $appKey,
            'timezone' => 'UTC',
            'db' => [
                'driver' => $driver,
                'host' => (string) ($dbConfig['host'] ?? '127.0.0.1'),
                'port' => (int) ($dbConfig['port'] ?? 3306),
                'database' => (string) ($dbConfig['database'] ?? 'sso'),
                'username' => (string) ($dbConfig['username'] ?? 'root'),
                'password' => (string) ($dbConfig['password'] ?? ''),
                'charset' => (string) ($dbConfig['charset'] ?? 'utf8mb4'),
                'unix_socket' => $dbConfig['unix_socket'] ?? null,
                'path' => $dbConfig['path'] ?? ($driver === 'sqlite' ? SSO_STORAGE . '/database/sso.sqlite' : null),
            ],
            'security' => App::defaults()['security'],
        ]);

        file_put_contents(SSO_CONFIG_FILE, $content);
        @chmod(SSO_CONFIG_FILE, 0640);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function renderConfig(array $config): string
    {
        $export = static function (mixed $value, int $indent = 1) use (&$export): string {
            $pad = str_repeat('    ', $indent);
            if (is_array($value)) {
                $isList = array_keys($value) === range(0, count($value) - 1);
                if ($isList && $value === []) {
                    return '[]';
                }
                if (!$isList && $value === []) {
                    return '[]';
                }
                $lines = [];
                foreach ($value as $key => $item) {
                    $keyPart = $isList ? '' : var_export((string) $key, true) . ' => ';
                    $lines[] = $pad . $keyPart . $export($item, $indent + 1);
                }
                return "[\n" . implode(",\n", $lines) . ",\n" . str_repeat('    ', $indent - 1) . ']';
            }
            if ($value === null) {
                return 'null';
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            return var_export((string) $value, true);
        };

        $body = '';
        foreach ($config as $key => $value) {
            $body .= '    ' . var_export((string) $key, true) . ' => ' . $export($value) . ",\n";
        }

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/**\n * تنظیمات سامانه احراز هویت — تولید شده توسط نصب‌کننده.\n"
            . " * تاریخ: " . gmdate('Y-m-d H:i:s') . " UTC\n */\n\n"
            . "return [\n" . $body . "];\n";
    }

    /**
     * ساخت کاربر ادمین کل.
     *
     * @return array<string, mixed>
     */
    public static function createSuperAdmin(string $email, string $password, ?string $fullName = null): array
    {
        $error = TokenService::passwordError($password);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }

        $existing = User::findByEmail($email, true);
        if ($existing !== null) {
            User::update((int) $existing['id'], [
                'is_super_admin' => 1,
                'password_hash' => App::instance()->tokens()->hashPassword($password),
                'status' => User::STATUS_ACTIVE,
                'deleted_at' => null,
            ]);
            return User::find((int) $existing['id']) ?? $existing;
        }

        $id = User::create([
            'email' => $email,
            'password_hash' => App::instance()->tokens()->hashPassword($password),
            'full_name' => $fullName,
            'status' => User::STATUS_ACTIVE,
            'is_super_admin' => true,
            'password_changed_at' => \Sso\Support\Clock::now(),
        ]);

        return User::find($id) ?? [];
    }

    /**
     * ساخت اولین اپلیکیشن نمونه.
     *
     * @return array{app: array<string, mixed>, api_key: string, api_secret: string}
     */
    public static function createDemoApp(string $name = 'اپلیکیشن نمونه'): array
    {
        /** @var AppService $service */
        $service = App::instance()->apps();
        $result = $service->create($name);

        // ادمین را هم به عنوان owner به این اپ وصل می‌کنیم
        $admin = App::instance()->db()->fetch('SELECT * FROM `users` WHERE `is_super_admin` = 1 ORDER BY `id` ASC');
        if ($admin !== null) {
            Membership::attach((int) $result['app']['id'], (int) $admin['id'], Roles::OWNER);
        }
        return $result;
    }

    public static function isInstalled(): bool
    {
        return is_file(SSO_CONFIG_FILE) && is_file(SSO_INSTALL_LOCK);
    }

    public static function lock(): void
    {
        self::ensureDirectory(SSO_STORAGE);
        file_put_contents(SSO_INSTALL_LOCK, gmdate('Y-m-d H:i:s') . " UTC\n");
    }
}
