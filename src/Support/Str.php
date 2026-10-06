<?php

declare(strict_types=1);

namespace Sso\Support;

/**
 * توابع رشته‌ایِ ایمن برای یوتی‌اف-۸.
 */
final class Str
{
    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    public static function upper(string $value): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    public static function substr(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length, 'UTF-8');
        }
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }

    public static function limit(string $value, int $limit = 60, string $end = '…'): string
    {
        if (self::length($value) <= $limit) {
            return $value;
        }
        return self::substr($value, 0, max(0, $limit - 1)) . $end;
    }

    public static function trim(string $value): string
    {
        return trim(preg_replace('/^[\pZ\s]+|[\pZ\s]+$/u', '', $value) ?? $value);
    }

    /**
     * رشته‌ی هگز تصادفیِ امن (برای توکن‌ها و کلیدها).
     */
    public static function randomHex(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * رشته‌ی تصادفی url-safe.
     */
    public static function randomAlnum(int $length = 32): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzACDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    public static function normalizeEmail(string $email): string
    {
        return self::lower(self::trim($email));
    }

    public static function isEmail(string $email): bool
    {
        if (self::length($email) > 190 || str_contains($email, "\n") || str_contains($email, "\r")) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function slug(string $value): string
    {
        $value = self::lower(self::trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        return trim($value, '-');
    }

    /**
     * IPv4/IPv6 را برای ستون ۴۵ کاراکتری کوتاه می‌کند.
     */
    public static function ip(?string $ip): string
    {
        $ip = (string) $ip;
        return self::substr($ip, 0, 45);
    }

    public static function userAgent(?string $ua): string
    {
        $ua = self::trim((string) $ua);
        return self::substr($ua, 0, 400);
    }
}
