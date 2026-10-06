<?php

declare(strict_types=1);

/**
 * توابع کمکی سراسری.
 */

if (!function_exists('e')) {
    /**
     * خروجی امن برای HTML.
     */
    function e(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('sso_config')) {
    /**
     * دسترسی سریع به تنظیمات: sso_config('db.host')
     */
    function sso_config(string $key = '', mixed $default = null): mixed
    {
        return Sso\Core\App::instance()->config($key, $default);
    }
}

if (!function_exists('sso_app')) {
    function sso_app(): Sso\Core\App
    {
        return Sso\Core\App::instance();
    }
}

if (!function_exists('sso_db')) {
    function sso_db(): Sso\Data\Database
    {
        return Sso\Core\App::instance()->db();
    }
}

if (!function_exists('sso_url')) {
    /**
     * آدرس کامل یک مسیر نسبی به ریشه‌ی سایت.
     */
    function sso_url(string $path = '/'): string
    {
        // پیش از نصب، برنامه بالا نیامده است؛ مسیر نسبی به ریشه برمی‌گردد
        $base = Sso\Core\App::isBooted()
            ? rtrim((string) sso_config('base_url', ''), '/')
            : '';
        return $base . '/' . ltrim($path, '/');
    }
}

/**
 * آیا در محیط خط فرمان هستیم؟ (embed همان PHP تعبیه‌شده در wasm است)
 */
if (!function_exists('sso_is_cli')) {
    function sso_is_cli(): bool
    {
        return in_array(PHP_SAPI, ['cli', 'embed', 'phpdbg'], true);
    }
}

if (!function_exists('sso_api_router')) {
    /**
     * ثبت/دریافت مسیریاب API (برای مستندات خودکار).
     */
    function sso_api_router(?Sso\Api\Router $router = null): ?Sso\Api\Router
    {
        static $instance = null;
        if ($router !== null) {
            $instance = $router;
        }
        return $instance;
    }
}

if (!function_exists('sso_api_routes')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function sso_api_routes(): array
    {
        $router = sso_api_router();
        return $router === null ? [] : $router->routes();
    }
}
