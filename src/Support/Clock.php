<?php

declare(strict_types=1);

namespace Sso\Support;

/**
 * زمان بر حسب UTC با فرمت سازگار با DATETIME هر دو دیتابیس.
 *
 * نکته‌ی مهم: در هیچ کوئری‌ای از توابع تاریخِ SQL استفاده نمی‌کنیم
 * (NOW() / DATE_SUB / INTERVAL و ...) تا کد بین MySQL و SQLite یکسان باشد.
 * همه‌ی مقایسه‌ها در PHP محاسبه و به صورت پارامتر فرستاده می‌شوند.
 */
final class Clock
{
    public const FORMAT = 'Y-m-d H:i:s';

    public static function now(): string
    {
        return gmdate(self::FORMAT);
    }

    public static function fromTimestamp(int $timestamp): string
    {
        return gmdate(self::FORMAT, $timestamp);
    }

    public static function inSeconds(int $seconds): string
    {
        return gmdate(self::FORMAT, time() + $seconds);
    }

    public static function ago(int $seconds): string
    {
        return gmdate(self::FORMAT, time() - $seconds);
    }

    /**
     * رشته‌ی DATETIME را به timestamp تبدیل می‌کند (برای خروجی JSON).
     */
    public static function toTimestamp(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime($value . ' UTC');
        return $ts === false ? null : $ts;
    }
}
