<?php

declare(strict_types=1);

namespace Sso\Support;

use Sso\Http\Session;

/**
 * محافظت در برابر CSRF برای فرم‌های پنل مدیریت.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        Session::start();
        $token = Session::get(self::KEY);
        if (!is_string($token) || $token === '') {
            $token = Str::randomHex(32);
            Session::put(self::KEY, $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function meta(): string
    {
        return '<meta name="csrf-token" content="' . e(self::token()) . '">';
    }

    public static function verify(?string $token): bool
    {
        Session::start();
        $stored = Session::get(self::KEY);
        if (!is_string($stored) || $stored === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }
}
