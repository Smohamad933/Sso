<?php

declare(strict_types=1);

namespace Sso\Models;

use Sso\Data\Database;
use Sso\Support\Clock;
use Sso\Support\Str;

/**
 * جدول api_tokens — توکن‌های دسترسی کاربر (access / refresh).
 *
 * فقط هشِ توکن در دیتابیس نگه داشته می‌شود؛ مقدار اصلی یک‌بار
 * در لحظه‌ی صدور به کلاینت برگردانده می‌شود.
 */
final class Token
{
    public const TYPE_ACCESS = 'access';
    public const TYPE_REFRESH = 'refresh';

    public const TABLE = 'api_tokens';

    public const PREFIX_ACCESS = 'sat_';
    public const PREFIX_REFRESH = 'srt_';

    private static function db(): Database
    {
        return \sso_db();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /**
     * صدور توکن جدید.
     *
     * @param array<int, string> $scopes
     * @return array{token: string, record: array<string, mixed>}
     */
    public static function issue(
        int $userId,
        ?int $appId,
        string $type,
        int $ttlSeconds,
        array $scopes = [],
        string $ip = '',
        string $userAgent = ''
    ): array {
        $prefix = $type === self::TYPE_REFRESH ? self::PREFIX_REFRESH : self::PREFIX_ACCESS;
        $secret = Str::randomHex(36);
        $token = $prefix . $secret;

        $expiresAt = Clock::inSeconds(max(30, $ttlSeconds));
        $id = self::db()->insert(self::TABLE, [
            'token_type' => $type,
            'token_hash' => self::hash($token),
            'token_prefix' => $prefix . Str::substr($secret, 0, 6),
            'user_id' => $userId,
            'app_id' => $appId,
            'scopes' => $scopes === [] ? null : implode(',', $scopes),
            'ip_address' => Str::ip($ip),
            'user_agent' => Str::userAgent($userAgent),
            'expires_at' => $expiresAt,
            'created_at' => Clock::now(),
        ]);

        return [
            'token' => $token,
            'record' => [
                'id' => $id,
                'token_type' => $type,
                'token_prefix' => $prefix . Str::substr($secret, 0, 6),
                'user_id' => $userId,
                'app_id' => $appId,
                'scopes' => $scopes,
                'expires_at' => $expiresAt,
                'created_at' => Clock::now(),
            ],
        ];
    }

    /**
     * یافتن توکنِ معتبر (منقضی/ابطال‌شده نادیده گرفته می‌شود).
     *
     * @param array<int, string> $allowedTypes
     */
    public static function findValid(string $token, array $allowedTypes = [self::TYPE_ACCESS]): ?array
    {
        $row = self::db()->fetch(
            'SELECT * FROM `api_tokens` WHERE `token_hash` = ?',
            [self::hash($token)]
        );
        if ($row === null) {
            return null;
        }
        if (!in_array((string) $row['token_type'], $allowedTypes, true)) {
            return null;
        }
        if ($row['revoked_at'] !== null) {
            return null;
        }
        if ((string) $row['expires_at'] < Clock::now()) {
            return null;
        }
        return $row;
    }

    public static function find(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM `api_tokens` WHERE `id` = ?', [$id]);
    }

    public static function revokeByHash(string $token): bool
    {
        return self::db()->update(
            self::TABLE,
            ['revoked_at' => Clock::now()],
            '`token_hash` = ? AND `revoked_at` IS NULL',
            [self::hash($token)]
        ) > 0;
    }

    public static function revoke(int $id): bool
    {
        return self::db()->update(
            self::TABLE,
            ['revoked_at' => Clock::now()],
            '`id` = ? AND `revoked_at` IS NULL',
            [$id]
        ) > 0;
    }

    public static function revokeAllForUser(int $userId, ?int $appId = null): int
    {
        if ($appId === null) {
            return self::db()->update(self::TABLE, ['revoked_at' => Clock::now()], '`user_id` = ? AND `revoked_at` IS NULL', [$userId]);
        }
        return self::db()->update(
            self::TABLE,
            ['revoked_at' => Clock::now()],
            '`user_id` = ? AND `app_id` = ? AND `revoked_at` IS NULL',
            [$userId, $appId]
        );
    }

    public static function touch(int $id): void
    {
        self::db()->update(self::TABLE, ['last_used_at' => Clock::now()], '`id` = ?', [$id]);
    }

    /**
     * @return array<int, string>
     */
    public static function scopes(array $row): array
    {
        $raw = (string) ($row['scopes'] ?? '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $s): bool => $s !== ''));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function publicArray(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['token_type'],
            'prefix' => (string) $row['token_prefix'],
            'user_id' => (int) $row['user_id'],
            'app_id' => $row['app_id'] === null ? null : (int) $row['app_id'],
            'scopes' => self::scopes($row),
            'ip_address' => $row['ip_address'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'revoked_at' => $row['revoked_at'] ?? null,
            'last_used_at' => $row['last_used_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'is_expired' => $row['expires_at'] !== null && (string) $row['expires_at'] < Clock::now(),
        ];
    }
}
