<?php

declare(strict_types=1);

namespace Sso\Http;

/**
 * مدیریت نشست (مخصوص پنل مدیریت؛ API بدون نشست است).
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $path = SSO_STORAGE . '/sessions';
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        if (is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }

        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;

        session_name('SSOSESSID');
        if (!headers_sent()) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        session_start();
        self::$started = true;
    }

    public static function started(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public static function id(): string
    {
        return self::started() ? session_id() : '';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function flash(string $key, mixed $value = null, array $values = []): void
    {
        $bag = $_SESSION['_flash'] ?? [];
        if ($value !== null) {
            $bag[$key] = $value;
        }
        foreach ($values as $name => $v) {
            $bag[$name] = $v;
        }
        $_SESSION['_flash'] = $bag;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function errors(): array
    {
        $errors = self::getFlash('errors', []);
        return is_array($errors) ? $errors : [];
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (!self::started()) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        self::$started = false;
    }
}
