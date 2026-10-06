<?php

declare(strict_types=1);

/**
 * SSO Server — Bootstrap
 *
 * مسئول: تعریف ثابت‌ها، ثبت autoloader، تنظیم مدیریت خطا.
 * این فایل هیچ خروجی‌ای تولید نمی‌کند.
 */

defined('SSO_START') || define('SSO_START', microtime(true));
defined('SSO_ROOT') || define('SSO_ROOT', dirname(__DIR__));
defined('SSO_SRC') || define('SSO_SRC', SSO_ROOT . '/src');
defined('SSO_PUBLIC') || define('SSO_PUBLIC', SSO_ROOT . '/public');
defined('SSO_STORAGE') || define('SSO_STORAGE', SSO_ROOT . '/storage');
defined('SSO_LOG_DIR') || define('SSO_LOG_DIR', SSO_STORAGE . '/logs');
defined('SSO_CONFIG_FILE') || define('SSO_CONFIG_FILE', SSO_ROOT . '/config/config.php');
defined('SSO_EXAMPLE_CONFIG') || define('SSO_EXAMPLE_CONFIG', SSO_ROOT . '/config/config.example.php');
defined('SSO_INSTALL_LOCK') || define('SSO_INSTALL_LOCK', SSO_STORAGE . '/installed.lock');

if (!defined('SSO_AUTOLOAD_REGISTERED')) {
    define('SSO_AUTOLOAD_REGISTERED', true);
    spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Sso\\')) {
        return;
    }
    $relative = substr($class, 4);
    $file = SSO_SRC . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

require_once SSO_SRC . '/Support/helpers.php';

/*
 * تبدیل warningها به استثنا: باگ‌ها را بلافاصله آشکار می‌کند و
 * جلوی ادامه‌ی اجرا با داده‌ی نیمه‌خراب را می‌گیرد.
 */
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    Sso\Support\Log::critical('unhandled: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    if (PHP_SAPI === 'cli' && !getenv('SSO_HTTP_TEST')) {
        fwrite(STDERR, 'Uncaught ' . $e::class . ': ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo PHP_EOL . 'خطای داخلی سرور. جزئیات در storage/logs/app.log ثبت شد.' . PHP_EOL;
    echo get_class($e) . ': ' . $e->getMessage();
});
