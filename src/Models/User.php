<?php

declare(strict_types=1);

namespace Sso\Models;

use Sso\Data\Database;
use Sso\Support\Clock;
use Sso\Support\Str;

/**
 * جدول users — مخزن سراسری کاربران (Shared Identity Pool).
 *
 * کاربر در سطح سامانه یکتا است (بر اساس ایمیل) و از طریق جدول
 * memberships به هر تعداد اپلیکیشن با نقش متفاوت متصل می‌شود.
 */
final class User
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_PENDING = 'pending';

    public const TABLE = 'users';

    private static function db(): Database
    {
        return \sso_db();
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_PENDING];
    }

    public static function find(int $id, bool $withDeleted = false): ?array
    {
        $sql = 'SELECT * FROM `users` WHERE `id` = ?' . ($withDeleted ? '' : ' AND `deleted_at` IS NULL');
        return self::db()->fetch($sql, [$id]);
    }

    public static function findByEmail(string $email, bool $withDeleted = false): ?array
    {
        $sql = 'SELECT * FROM `users` WHERE `email` = ?' . ($withDeleted ? '' : ' AND `deleted_at` IS NULL');
        return self::db()->fetch($sql, [Str::normalizeEmail($email)]);
    }

    public static function findByUuid(string $uuid): ?array
    {
        return self::db()->fetch('SELECT * FROM `users` WHERE `uuid` = ? AND `deleted_at` IS NULL', [$uuid]);
    }

    public static function findByPhone(string $phone): ?array
    {
        return self::db()->fetch('SELECT * FROM `users` WHERE `phone` = ? AND `deleted_at` IS NULL', [$phone]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $now = Clock::now();
        $payload = [
            'uuid' => $data['uuid'] ?? self::generateUuid(),
            'email' => Str::normalizeEmail((string) $data['email']),
            'phone' => isset($data['phone']) && $data['phone'] !== '' ? (string) $data['phone'] : null,
            'password_hash' => (string) $data['password_hash'],
            'full_name' => isset($data['full_name']) ? self::cleanName((string) $data['full_name']) : null,
            'avatar_url' => $data['avatar_url'] ?? null,
            'metadata' => self::encodeMetadata($data['metadata'] ?? null),
            'status' => $data['status'] ?? self::STATUS_ACTIVE,
            'is_super_admin' => !empty($data['is_super_admin']) ? 1 : 0,
            'email_verified_at' => $data['email_verified_at'] ?? null,
            'password_changed_at' => $data['password_changed_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        return self::db()->insert(self::TABLE, $payload);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $allowed = ['email', 'phone', 'password_hash', 'full_name', 'avatar_url', 'metadata',
            'status', 'is_super_admin', 'email_verified_at', 'failed_logins', 'locked_until',
            'last_login_at', 'password_changed_at', 'deleted_at'];

        $payload = [];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $value = $data[$column];
            $payload[$column] = match ($column) {
                'email' => Str::normalizeEmail((string) $value),
                'full_name' => $value === null ? null : self::cleanName((string) $value),
                'metadata' => self::encodeMetadata($value),
                'is_super_admin' => $value ? 1 : 0,
                'failed_logins' => (int) $value,
                'password_changed_at' => $value,
                default => $value,
            };
        }
        if ($payload === []) {
            return;
        }
        $payload['updated_at'] = Clock::now();
        self::db()->update(self::TABLE, $payload, '`id` = ?', [$id]);
    }

    /**
     * حذف نرم: رکورد می‌ماند اما از همه‌ی جستجوها حذف می‌شود.
     */
    public static function softDelete(int $id): void
    {
        self::db()->update(
            self::TABLE,
            ['deleted_at' => Clock::now(), 'status' => self::STATUS_SUSPENDED, 'updated_at' => Clock::now()],
            '`id` = ?',
            [$id]
        );
    }

    // ------------------------------------------------------------------ نمایش

    /**
     * تبدیل رکورد به آرایه‌ی قابل انتشار.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $options  with_email, with_memberships, role, app, admin_view
     * @return array<string, mixed>
     */
    public static function publicArray(array $user, array $options = []): array
    {
        $metadata = self::decodeMetadata($user['metadata'] ?? null);

        $out = [
            'id' => (int) $user['id'],
            'uuid' => (string) $user['uuid'],
            'email' => (string) $user['email'],
            'email_verified' => $user['email_verified_at'] !== null,
            'full_name' => $user['full_name'] ?? null,
            'phone' => $user['phone'] ?? null,
            'avatar_url' => $user['avatar_url'] ?? null,
            'status' => (string) $user['status'],
            'metadata' => $metadata,
            'created_at' => $user['created_at'] ?? null,
            'updated_at' => $user['updated_at'] ?? null,
            'last_login_at' => $user['last_login_at'] ?? null,
        ];

        if (!empty($options['admin_view'])) {
            $out['is_super_admin'] = (int) ($user['is_super_admin'] ?? 0) === 1;
            $out['locked_until'] = $user['locked_until'] ?? null;
            $out['failed_logins'] = (int) ($user['failed_logins'] ?? 0);
        }

        if (isset($options['role'])) {
            $out['role'] = (string) $options['role'];
        }
        if (isset($options['membership_status'])) {
            $out['membership_status'] = (string) $options['membership_status'];
        }
        if (isset($options['app'])) {
            $out['app'] = $options['app'];
        }
        if (!empty($options['with_memberships'])) {
            $out['memberships'] = Membership::listForUser((int) $user['id']);
        }

        return $out;
    }

    /**
     * نمایش مدیریتی (شامل پرچم ادمین کل).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function adminArray(array $user): array
    {
        return self::publicArray($user, ['with_memberships' => true, 'admin_view' => true]);
    }

    private static function cleanName(string $name): string
    {
        return Str::substr(Str::trim($name), 0, 150);
    }

    public static function encodeMetadata(mixed $metadata): ?string
    {
        if ($metadata === null || $metadata === [] || $metadata === '') {
            return null;
        }
        if (is_string($metadata)) {
            // ممکن است از قبل JSON باشد (مثلاً در فرم ادمین)
            json_decode($metadata, true);
            return json_last_error() === JSON_ERROR_NONE ? $metadata : json_encode(['value' => $metadata], JSON_UNESCAPED_UNICODE);
        }
        return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decodeMetadata(mixed $metadata): ?array
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }
        if (is_array($metadata)) {
            return $metadata;
        }
        $decoded = json_decode((string) $metadata, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
