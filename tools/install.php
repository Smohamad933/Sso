<?php

declare(strict_types=1);

/**
 * نصب از طریق خط فرمان (CLI).
 *
 * استفاده:
 *   php tools/install.php
 *   php tools/install.php --driver=mysql --host=127.0.0.1 --port=3306 \
 *        --database=sso --username=root --password=secret \
 *        --admin-email=admin@example.com --admin-password=Secret123 \
 *        --base-url=https://auth.example.com --no-demo-app
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Sso\Install\Installer;
use Sso\Support\Str;

if (!sso_is_cli()) {
    fwrite(STDERR, "این اسکریپت فقط از خط فرمان اجرا می‌شود.\n");
    exit(1);
}

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $arg = substr($arg, 2);
    if (str_contains($arg, '=')) {
        [$key, $value] = explode('=', $arg, 2);
        $options[$key] = $value;
    } else {
        $options[$arg] = true;
    }
}

$out = static function (string $message): void {
    echo $message . PHP_EOL;
};
$ask = static function (string $question, string $default = '', bool $secret = false) use ($out): string {
    $suffix = $default !== '' ? " [$default]" : '';
    $out('');
    if ($secret && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        $out($question . $suffix . ': ');
        $handle = fopen('php://stdin', 'rb');
        $value = $handle === false ? '' : (string) fgets($handle);
    } else {
        $out($question . $suffix . ': ');
        $handle = fopen('php://stdin', 'rb');
        $value = $handle === false ? '' : (string) fgets($handle);
    }
    $value = Str::trim((string) $value);
    return $value === '' ? $default : $value;
};

$out('===============================================');
$out('  نصب سامانه احراز هویت یکپارچه (SSO)');
$out('===============================================');

if (Installer::isInstalled() && !isset($options['force'])) {
    $out('');
    $out('سامانه قبلاً نصب شده است. برای نصب مجدد: php tools/install.php --force');
    exit(0);
}

// ---------------------------------------------------------------- پیش‌نیازها
$missing = Installer::missingRequirements();
if ($missing !== []) {
    $out('');
    $out('پیش‌نیازها برآورده نشده‌اند:');
    foreach ($missing as $item) {
        $out('  - ' . $item);
    }
    exit(1);
}

// ---------------------------------------------------------------- دیتابیس
$driver = strtolower((string) ($options['driver'] ?? $ask('نوع دیتابیس (mysql/sqlite)', 'mysql')));
$db = [
    'driver' => $driver,
    'host' => (string) ($options['host'] ?? ''),
    'port' => (int) ($options['port'] ?? 0),
    'database' => (string) ($options['database'] ?? ''),
    'username' => (string) ($options['username'] ?? ''),
    'password' => (string) ($options['password'] ?? ''),
    'charset' => (string) ($options['charset'] ?? 'utf8mb4'),
    'path' => null,
];

if ($driver === 'sqlite') {
    $db['path'] = (string) ($options['path'] ?? SSO_STORAGE . '/database/sso.sqlite');
} else {
    $db['host'] = $db['host'] ?: $ask('هاست MySQL', '127.0.0.1');
    $db['port'] = $db['port'] ?: (int) $ask('پورت', '3306');
    $db['database'] = $db['database'] ?: $ask('نام دیتابیس', 'sso');
    $db['username'] = $db['username'] ?: $ask('نام کاربری', 'root');
    $db['password'] = $db['password'] !== '' || isset($options['password'])
        ? $db['password']
        : $ask('رمز عبور MySQL', '', true);
}

$connection = Installer::testConnection($db);
if (!$connection['ok']) {
    $out('');
    $out('خطا در اتصال به دیتابیس: ' . $connection['message']);
    exit(1);
}
$out('');
$out('✓ ' . $connection['message']);

// ---------------------------------------------------------------- مدیر
$adminEmail = (string) ($options['admin-email'] ?? $ask('ایمیل مدیر', 'admin@example.com'));
$adminPassword = (string) ($options['admin-password'] ?? '');
$adminName = (string) ($options['admin-name'] ?? 'مدیر سامانه');
$appName = (string) ($options['app-name'] ?? 'سامانه احراز هویت یکپارچه');
$baseUrl = (string) ($options['base-url'] ?? '');

if ($adminPassword === '') {
    while (true) {
        $adminPassword = $ask('رمز عبور مدیر (حداقل ' . '8' . ' کاراکتر)', '', true);
        $error = \Sso\Services\TokenService::passwordError($adminPassword);
        if ($error === null) {
            break;
        }
        $out('  ! ' . $error);
    }
}

if (!Str::isEmail($adminEmail)) {
    $out('');
    $out('ایمیل مدیر معتبر نیست.');
    exit(1);
}
$policyError = \Sso\Services\TokenService::passwordError($adminPassword);
if ($policyError !== null) {
    $out('');
    $out('رمز عبور مدیر: ' . $policyError);
    exit(1);
}

// ---------------------------------------------------------------- اجرا
try {
    if ($driver === 'mysql') {
        Installer::createDatabase($db);
        $out('✓ دیتابیس آماده است.');
    }

    Installer::writeConfig($db, 'base64:' . base64_encode(random_bytes(32)), $appName, $baseUrl);
    $out('✓ فایل config/config.php نوشته شد.');

    \Sso\Core\App::boot();
    Installer::runSchema(\sso_db());
    $out('✓ جداول دیتابیس ایجاد شدند.');

    Installer::createSuperAdmin($adminEmail, $adminPassword, $adminName);
    $out('✓ حساب مدیر ساخته شد: ' . $adminEmail);

    if (!isset($options['no-demo-app'])) {
        $demo = Installer::createDemoApp('اپلیکیشن نمونه');
        $out('✓ اپلیکیشن نمونه ساخته شد.');
        $out('');
        $out('  API Key:    ' . $demo['api_key']);
        $out('  API Secret: ' . $demo['api_secret']);
        $out('');
        $out('  این دو مقدار فقط یک‌بار نمایش داده می‌شوند.');
    }

    Installer::lock();
    $out('');
    $out('✓ نصب با موفقیت انجام شد.');
    $out('');
    $out('  مرحله‌ی بعد: public/setup.php را حذف کنید و Document Root سایت را روی پوشه‌ی public تنظیم کنید.');
    exit(0);
} catch (\Throwable $e) {
    $out('');
    $out('✗ نصب ناموفق بود: ' . $e->getMessage());
    \Sso\Support\Log::critical('cli install: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    exit(1);
}
