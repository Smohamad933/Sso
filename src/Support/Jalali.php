<?php

declare(strict_types=1);

namespace Sso\Support;

/**
 * تبدیل تاریخ میلادی به شمسی (برای نمایش در پنل).
 */
final class Jalali
{
    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $gDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gy + 1;
        $days = 355666 + (365 * $gy) + ((int) (($gy2 + 3) / 4)) - ((int) (($gy2 + 99) / 100))
            + ((int) (($gy2 + 399) / 400)) + $gd + ($gDays[$gm - 1] ?? 0);

        if ($gm > 2 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) {
            $days++;
        }

        $jy = -1595 + (33 * ((int) ($days / 12053)));
        $days %= 12053;
        $jy += 4 * ((int) ($days / 1461));
        $days %= 1461;

        if ($days > 365) {
            $jy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + (int) ($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int) (($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    public static function format(?string $datetime, bool $withTime = true): string
    {
        if ($datetime === null || $datetime === '') {
            return '—';
        }
        $timestamp = Clock::toTimestamp($datetime);
        if ($timestamp === null) {
            return '—';
        }
        [$y, $m, $d] = self::toJalali((int) gmdate('Y', $timestamp), (int) gmdate('n', $timestamp), (int) gmdate('j', $timestamp));
        $date = sprintf('%04d/%02d/%02d', $y, $m, $d);
        return $withTime ? $date . ' ' . gmdate('H:i', $timestamp) : $date;
    }

    /**
     * «۳ دقیقه پیش» و مشابه.
     */
    public static function humanDiff(?string $datetime): string
    {
        $timestamp = Clock::toTimestamp($datetime);
        if ($timestamp === null) {
            return '—';
        }
        $diff = time() - $timestamp;
        if ($diff < 0) {
            return self::format($datetime);
        }
        if ($diff < 60) {
            return 'همین الان';
        }
        if ($diff < 3600) {
            return (int) ($diff / 60) . ' دقیقه پیش';
        }
        if ($diff < 86400) {
            return (int) ($diff / 3600) . ' ساعت پیش';
        }
        if ($diff < 2592000) {
            return (int) ($diff / 86400) . ' روز پیش';
        }
        return self::format($datetime);
    }
}
