<?php

declare(strict_types=1);

namespace Sso\Models;

use Sso\Data\Database;
use Sso\Support\Clock;

/**
 * جدول memberships — اتصال یک کاربر به یک اپ با یک نقش مشخص.
 */
final class Membership
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public const TABLE = 'memberships';

    private static function db(): Database
    {
        return \sso_db();
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_SUSPENDED];
    }

    public static function find(int $appId, int $userId): ?array
    {
        return self::db()->fetch(
            'SELECT * FROM `memberships` WHERE `app_id` = ? AND `user_id` = ?',
            [$appId, $userId]
        );
    }

    public static function attach(int $appId, int $userId, string $role = Roles::MEMBER, ?array $metadata = null): int
    {
        $existing = self::find($appId, $userId);
        if ($existing !== null) {
            self::db()->update(
                self::TABLE,
                ['role' => $role, 'status' => self::STATUS_ACTIVE, 'metadata' => self::encodeMetadata($metadata), 'updated_at' => Clock::now()],
                '`id` = ?',
                [(int) $existing['id']]
            );
            return (int) $existing['id'];
        }
        $now = Clock::now();
        return self::db()->insert(self::TABLE, [
            'app_id' => $appId,
            'user_id' => $userId,
            'role' => $role,
            'status' => self::STATUS_ACTIVE,
            'metadata' => self::encodeMetadata($metadata),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function updateRole(int $appId, int $userId, string $role): bool
    {
        return self::db()->update(
            self::TABLE,
            ['role' => $role, 'updated_at' => Clock::now()],
            '`app_id` = ? AND `user_id` = ?',
            [$appId, $userId]
        ) > 0;
    }

    /**
     * فقط متادیتا (بدون تغییر وضعیت یا نقش).
     */
    public static function updateMetadata(int $appId, int $userId, ?array $metadata): bool
    {
        return self::db()->update(
            self::TABLE,
            ['metadata' => self::encodeMetadata($metadata), 'updated_at' => Clock::now()],
            '`app_id` = ? AND `user_id` = ?',
            [$appId, $userId]
        ) > 0;
    }

    public static function setStatus(int $appId, int $userId, string $status): bool
    {
        return self::db()->update(
            self::TABLE,
            ['status' => $status, 'updated_at' => Clock::now()],
            '`app_id` = ? AND `user_id` = ?',
            [$appId, $userId]
        ) > 0;
    }

    public static function detach(int $appId, int $userId): bool
    {
        return self::db()->delete(self::TABLE, '`app_id` = ? AND `user_id` = ?', [$appId, $userId]) > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listForUser(int $userId): array
    {
        $rows = self::db()->fetchAll(
            'SELECT m.*, a.`name` AS app_name, a.`slug` AS app_slug, a.`status` AS app_status
             FROM `memberships` m
             INNER JOIN `apps` a ON a.`id` = m.`app_id`
             WHERE m.`user_id` = ?
             ORDER BY a.`name` ASC',
            [$userId]
        );
        return array_map([self::class, 'publicArray'], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listForApp(int $appId, int $limit = 100, int $offset = 0): array
    {
        $rows = self::db()->fetchAll(
            'SELECT m.*, u.`email` AS user_email, u.`full_name` AS user_full_name
             FROM `memberships` m
             INNER JOIN `users` u ON u.`id` = m.`user_id`
             WHERE m.`app_id` = ? AND u.`deleted_at` IS NULL
             ORDER BY m.`id` DESC
             LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset),
            [$appId]
        );
        return array_map([self::class, 'publicArray'], $rows);
    }

    public static function countForApp(int $appId): int
    {
        return self::db()->count(
            'SELECT COUNT(*) FROM `memberships` m
             INNER JOIN `users` u ON u.`id` = m.`user_id`
             WHERE m.`app_id` = ? AND u.`deleted_at` IS NULL',
            [$appId]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function publicArray(array $row): array
    {
        $out = [
            'app_id' => (int) $row['app_id'],
            'user_id' => (int) $row['user_id'],
            'role' => (string) $row['role'],
            'status' => (string) $row['status'],
            'metadata' => self::decodeMetadata($row['metadata'] ?? null),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
        if (isset($row['app_name'])) {
            $out['app'] = [
                'id' => (int) $row['app_id'],
                'name' => (string) $row['app_name'],
                'slug' => (string) $row['app_slug'],
                'status' => (string) $row['app_status'],
            ];
        }
        if (isset($row['user_email'])) {
            $out['user'] = [
                'id' => (int) $row['user_id'],
                'email' => (string) $row['user_email'],
                'full_name' => $row['user_full_name'] ?? null,
            ];
        }
        return $out;
    }

    private static function encodeMetadata(?array $metadata): ?string
    {
        if ($metadata === null || $metadata === []) {
            return null;
        }
        return (string) json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decodeMetadata(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
