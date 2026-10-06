<?php

declare(strict_types=1);

namespace Sso\Models;

use Sso\Data\Database;
use Sso\Support\Clock;
use Sso\Support\Str;

/**
 * جدول apps — اپلیکیشن‌های متصل (Tenant / Client).
 *
 * هر اپ یک جفت کلید دارد:
 *   api_key    (شناسه، در دیتابیس فقط هش می‌شود)
 *   api_secret (فقط یک‌بار در زمان ساخت نمایش داده می‌شود)
 */
final class Application
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public const TABLE = 'apps';

    /** @var array<string, mixed> */
    public static array $defaultSettings = [
        'allow_registration' => true,
        'require_email_verification' => false,
        'default_role' => Roles::MEMBER,
        'access_token_ttl' => null,
        'refresh_token_ttl' => null,
        'allowed_origins' => [],
        'metadata_fields' => [],
    ];

    private static function db(): Database
    {
        return \sso_db();
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_DISABLED];
    }

    public static function find(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM `apps` WHERE `id` = ?', [$id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return self::db()->fetch('SELECT * FROM `apps` WHERE `slug` = ?', [Str::slug($slug)]);
    }

    public static function findByKey(string $apiKey): ?array
    {
        if (!is_string($apiKey) || trim($apiKey) === '') {
            return null;
        }
        return self::db()->fetch('SELECT * FROM `apps` WHERE `api_key_hash` = ?', [self::hash($apiKey)]);
    }

    public static function hash(string $value): string
    {
        return hash('sha256', trim($value));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::db()->fetchAll('SELECT * FROM `apps` ORDER BY `name` ASC');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $now = Clock::now();
        return self::db()->insert(self::TABLE, [
            'name' => Str::substr(Str::trim((string) $data['name']), 0, 120),
            'slug' => Str::substr((string) $data['slug'], 0, 120),
            'description' => isset($data['description']) ? Str::substr(Str::trim((string) $data['description']), 0, 255) : null,
            'status' => $data['status'] ?? self::STATUS_ACTIVE,
            'api_key_prefix' => (string) $data['api_key_prefix'],
            'api_key_hash' => (string) $data['api_key_hash'],
            'api_secret_hash' => (string) $data['api_secret_hash'],
            'webhook_url' => $data['webhook_url'] ?? null,
            'settings' => self::encodeSettings($data['settings'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $payload = [];
        foreach (['name', 'slug', 'description', 'status', 'api_key_prefix', 'api_key_hash',
            'api_secret_hash', 'webhook_url', 'settings'] as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $value = $data[$column];
            $payload[$column] = match ($column) {
                'name' => Str::substr(Str::trim((string) $value), 0, 120),
                'slug' => Str::substr((string) $value, 0, 120),
                'description' => $value === null ? null : Str::substr(Str::trim((string) $value), 0, 255),
                'settings' => self::encodeSettings($value),
                default => $value,
            };
        }
        if ($payload === []) {
            return;
        }
        $payload['updated_at'] = Clock::now();
        self::db()->update(self::TABLE, $payload, '`id` = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        $db = self::db();
        $db->transaction(static function (Database $db) use ($id): void {
            $db->delete('memberships', '`app_id` = ?', [$id]);
            $db->update('api_tokens', ['revoked_at' => Clock::now()], '`app_id` = ? AND `revoked_at` IS NULL', [$id]);
            $db->delete('apps', '`id` = ?', [$id]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(array $app): array
    {
        $decoded = json_decode((string) ($app['settings'] ?? ''), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        return array_replace(self::$defaultSettings, $decoded);
    }

    public static function encodeSettings(mixed $settings): string
    {
        $settings = is_array($settings) ? $settings : [];
        return (string) json_encode(array_replace(self::$defaultSettings, $settings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $app
     * @return array<string, mixed>
     */
    public static function publicArray(array $app, bool $withSettings = true): array
    {
        $out = [
            'id' => (int) $app['id'],
            'name' => (string) $app['name'],
            'slug' => (string) $app['slug'],
            'description' => $app['description'] ?? null,
            'status' => (string) $app['status'],
            'api_key_prefix' => (string) $app['api_key_prefix'],
            'webhook_url' => $app['webhook_url'] ?? null,
            'created_at' => $app['created_at'] ?? null,
            'updated_at' => $app['updated_at'] ?? null,
        ];
        if ($withSettings) {
            $out['settings'] = self::settings($app);
        }
        return $out;
    }
}
