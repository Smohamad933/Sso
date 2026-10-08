<?php

declare(strict_types=1);

namespace Sso\Admin;

use Sso\Core\App;
use Sso\Http\HttpException;
use Sso\Http\RedirectException;
use Sso\Http\Session;
use Sso\Models\User;
use Sso\Support\Clock;

/**
 * محافظ پنل مدیریت: فقط کاربران is_super_admin.
 */
final class Guard
{
    private const KEY_ID = 'admin_user_id';
    private const KEY_ACTIVITY = 'admin_last_activity';
    private const KEY_FINGERPRINT = 'admin_fingerprint';

    public static function boot(): void
    {
        // پیش از بالا آوردن برنامه باید مطمئن شویم تنظیمات وجود دارد
        if (!is_file(SSO_CONFIG_FILE)) {
            self::redirectTo('/setup.php');
        }

        if (!App::isBooted()) {
            App::boot();
        }

        Session::start();
        self::enforceIpWhitelist();
    }

    private static function enforceIpWhitelist(): void
    {
        /** @var array<int, string> $allowed */
        $allowed = (array) App::instance()->config('security.admin_ip_whitelist', []);
        $allowed = array_values(array_filter($allowed, static fn($v): bool => is_string($v) && trim($v) !== ''));
        if ($allowed === []) {
            return;
        }

        $ip = \Sso\Support\Security::clientIp();
        foreach ($allowed as $entry) {
            if (\Sso\Support\Security::cidrMatch($ip, trim($entry))) {
                return;
            }
        }

        throw HttpException::forbidden(
            '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
            . '<body style="font-family:tahoma;text-align:center;padding:80px">'
            . '<h2>دسترسی محدود شده</h2><p>آدرس IP شما اجازه‌ی ورود به پنل مدیریت را ندارد.</p></body></html>'
        );
    }


    /**
     * کاربرِ واردشده (یا هدایت به صفحه‌ی ورود).
     *
     * @return array<string, mixed>
     */
    public static function requireAuth(): array
    {
        $userId = Session::get(self::KEY_ID);
        if (!is_int($userId) && !is_string($userId)) {
            self::redirectToLogin();
        }

        $idle = (int) App::instance()->config('security.admin_session_idle_minutes', 120);
        $lastActivity = (int) Session::get(self::KEY_ACTIVITY, 0);
        if ($idle > 0 && $lastActivity > 0 && (time() - $lastActivity) > ($idle * 60)) {
            Session::destroy();
            Session::start();
            Session::flash('warning', 'نشست به دلیل عدم فعالیت منقضی شد.');
            self::redirectToLogin();
        }

        $user = User::find((int) $userId);
        if ($user === null || (int) ($user['is_super_admin'] ?? 0) !== 1 || (string) $user['status'] !== User::STATUS_ACTIVE) {
            Session::destroy();
            self::redirectToLogin();
        }

        Session::put(self::KEY_ACTIVITY, time());

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        $userId = Session::get(self::KEY_ID);
        if ($userId === null) {
            return null;
        }
        $user = User::find((int) $userId);
        return ($user !== null && (int) ($user['is_super_admin'] ?? 0) === 1) ? $user : null;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function login(array $user): void
    {
        Session::regenerate();
        Session::put(self::KEY_ID, (int) $user['id']);
        Session::put(self::KEY_ACTIVITY, time());
        Session::put(self::KEY_FINGERPRINT, hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    /**
     * بررسی CSRF برای درخواست‌های POST؛ در صورت نادرستی پیام می‌دهد و خارج می‌شود.
     */
    public static function verifyCsrf(?string $token): bool
    {
        if (\Sso\Support\Csrf::verify($token)) {
            return true;
        }
        Session::flash('error', 'درخواست نامعتبر بود (توکن امنیتی منقضی شده). لطفاً دوباره تلاش کنید.');
        return false;
    }

    /**
     * هدایت به یک مسیر داخلی (به صورت استثنا تا لایه‌ی بالاتر پاسخ را بفرستد).
     *
     * @throws RedirectException
     */
    public static function redirectTo(string $url): void
    {
        throw RedirectException::to(sso_url($url));
    }

    /**
     * @throws RedirectException
     */
    public static function redirectToLogin(): void
    {
        self::redirectTo('/admin/login.php');
    }

    public static function loginUrl(): string
    {
        return sso_url('/admin/login.php');
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function lastLoginLabel(array $user): string
    {
        return $user['last_login_at'] === null ? 'هرگز' : \Sso\Support\Jalali::format((string) $user['last_login_at']);
    }

    public static function now(): string
    {
        return Clock::now();
    }
}
