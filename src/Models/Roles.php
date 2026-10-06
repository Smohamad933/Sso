<?php

declare(strict_types=1);

namespace Sso\Models;

/**
 * نقش‌های کاربر در هر اپلیکیشن.
 *
 * owner  > admin > member > viewer
 */
final class Roles
{
    public const OWNER = 'owner';
    public const ADMIN = 'admin';
    public const MEMBER = 'member';
    public const VIEWER = 'viewer';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::OWNER, self::ADMIN, self::MEMBER, self::VIEWER];
    }

    public static function isValid(string $role): bool
    {
        return in_array($role, self::all(), true);
    }

    public static function rank(string $role): int
    {
        return match ($role) {
            self::OWNER => 40,
            self::ADMIN => 30,
            self::MEMBER => 20,
            self::VIEWER => 10,
            default => 0,
        };
    }

    /**
     * آیا نقشِ actor اجازه‌ی مدیریت نقشِ target را دارد؟
     */
    public static function canManage(string $actor, string $target): bool
    {
        return self::rank($actor) >= self::rank($target);
    }

    /**
     * نقش‌هایی که actor می‌تواند assign کند.
     *
     * @return array<int, string>
     */
    public static function assignableBy(string $actor): array
    {
        $rank = self::rank($actor);
        return array_values(array_filter(self::all(), static fn(string $r): bool => self::rank($r) <= $rank));
    }
}
