<?php

declare(strict_types=1);

namespace Sso\Support;

/**
 * سیاست‌های امنیتیِ لایه‌ی انتقال و مرورگر.
 *
 * این کلاس برای حالتی است که سامانه روی اینترنتِ عمومی مستقر می‌شود:
 * اجبارِ HTTPS، HSTS، هدرهای امنیتیِ مرورگر و تشخیصِ درستِ IP وقتی
 * وب‌سرور پشتِ یک پروکسی معکوس است.
 *
 * همه‌ی متدها ایستا و بدون اثرِ جانبیِ پنهان هستند.
 */
final class Security
{
    /** مدتِ HSTS به ثانیه (یک سال) */
    private const HSTS_MAX_AGE = 31536000;

    /**
     * آیا درخواستِ فعلی روی HTTPS است؟
     *
     * پشتِ پروکسیِ معکوس (IIS ARR، nginx، Cloudflare و غیره) وب‌سرورِ داخلی
     * معمولاً روی HTTP است و پروتکلِ واقعی در هدرِ X-Forwarded-Proto می‌آید.
     */
    public static function isHttps(): bool
    {
        if (strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwarded !== '') {
            // ممکن است زنجیره باشد: "https, http"
            $parts = array_map('trim', explode(',', $forwarded));
            return $parts[0] === 'https';
        }

        return false;
    }

    /**
     * آدرس IP واقعیِ کلاینت.
     *
     * هشدار: اعتماد به هدرهای X-Forwarded-For فقط وقتی امن است که بدانیم
     * درخواست از پروکسیِ مورد اعتمادِ ما می‌آید. برای همین فقط در صورتی از
     * آن استفاده می‌کنیم که trust_proxy در تنظیمات فعال شده باشد؛ در غیر این
     * صورت همان REMOTE_ADDR برگردانده می‌شود (که قابل جعل نیست).
     */
    public static function clientIp(): string
    {
        $direct = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        $trust = (bool) self::config('security.trust_proxy', false);
        if (!$trust) {
            return $direct;
        }

        $candidate = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($candidate === '') {
            $candidate = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        }
        if ($candidate === '') {
            return $direct;
        }

        // اولین آدرسِ زنجیره متعلق به کلاینت است
        $parts = array_map('trim', explode(',', $candidate));
        $ip = (string) ($parts[0] ?? '');

        return $ip !== '' ? $ip : $direct;
    }

    /**
     * اجبارِ HTTPS: اگر تنظیم شده باشد و درخواست روی HTTP باشد، هدایت می‌کند.
     *
     * خروجی ندارد؛ هدایت با header() انجام می‌شود و فراخوان مسئولِ توقف است.
     *
     * @return bool آیا هدایت انجام شد؟
     */
    public static function enforceHttps(): bool
    {
        if (!(bool) self::config('security.force_https', true)) {
            return false;
        }
        if (self::isHttps()) {
            return false;
        }
        // در محیط تست/embed هدایت بی‌معناست
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'embed') {
            return false;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return false;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        \header('Location: https://' . $host . $uri, true, 301);
        return true;
    }

    /**
     * ارسالِ هدرهای امنیتیِ مرورگر.
     *
     * @param array<string, string> $extra هدرهای بیشتر برای این پاسخ
     */
    public static function sendHeaders(array $extra = []): void
    {
        if (\headers_sent()) {
            return;
        }

        $headers = array_merge(self::baseHeaders(), $extra);
        foreach ($headers as $name => $value) {
            if ($value === '') {
                continue;
            }
            \header($name . ': ' . $value);
        }
    }

    /**
     * فهرستِ هدرهای امنیتیِ پیش‌فرض.
     *
     * @return array<string, string>
     */
    public static function baseHeaders(): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Content-Security-Policy' => self::contentSecurityPolicy(),
        ];

        // HSTS فقط روی HTTPS معنا دارد و روی HTTP می‌تواند مرورگر را گیج کند
        if (self::isHttps() && (bool) self::config('security.hsts_enabled', true)) {
            $headers['Strict-Transport-Security'] = 'max-age=' . self::HSTS_MAX_AGE
                . '; includeSubDomains; preload';
        }

        return $headers;
    }

    /**
     * خط‌مشیِ امنیتِ محتوا (CSP).
     *
     * سامانه هیچ اسکریپت یا استایلِ خارجی ندارد (بدون CDN، بدون فونتِ اینترنتی)،
     * بنابراین می‌توانیم سیاستِ بسیار سخت‌گیرانه‌ای اعمال کنیم. استایل‌های
     * درون‌خطی (inline) در صفحه‌ی نصب و پنل استفاده شده‌اند، برای همین
     * 'unsafe-inline' برای style-src لازم است.
     */
    public static function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            // اسکریپت فقط از خودِ سایت؛ بدون eval
            "script-src 'self'",
            // استایل‌های درون‌خطی در Layout و صفحه‌ی نصب استفاده شده‌اند
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-src 'none'",
        ]);
    }

    /**
     * تطبیقِ یک IP با یک محدوده‌ی CIDR یا یک آدرسِ تکی.
     *
     * هر دو نسخه‌ی IPv4 پشتیبانی می‌شود؛ مقادیرِ نامعتبر false برمی‌گردانند
     * (یعنی «مجاز نیست»، که جهتِ امن است).
     */
    public static function cidrMatch(string $ip, string $cidr): bool
    {
        $ip = trim($ip);
        $cidr = trim($cidr);
        if ($ip === '' || $cidr === '') {
            return false;
        }

        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        if ($bits <= 0) {
            return true;
        }
        if ($bits > 32) {
            $bits = 32;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long(trim($subnet));
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * خواندنِ یک تنظیمِ امنیتی به‌طور ایمن.
     *
     * نکته: نصابِ وب پیش از آن‌که برنامه بالا بیاید اجرا می‌شود (فایلِ تنظیمات
     * هنوز وجود ندارد). اگر در این حالت سراغ sso_config برویم، استثنا پرتاب
     * می‌شود و نصب از کار می‌افتد. برای همین وقتی برنامه بالا نیست، مقدارِ
     * پیش‌فرض برگردانده می‌شود.
     */
    private static function config(string $key, mixed $default): mixed
    {
        return \Sso\Core\App::isBooted() ? \sso_config($key, $default) : $default;
    }
}
