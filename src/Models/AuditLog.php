<?php

declare(strict_types=1);

namespace Sso\Models;

use Sso\Data\Database;
use Sso\Support\Clock;
use Sso\Support\Str;

/**
 * جدول audit_logs — ثبت رویدادهای حساس.
 */
final class AuditLog
{
    public const TABLE = 'audit_logs';

    public const ACTOR_USER = 'user';
    public const ACTOR_APP = 'app';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SYSTEM = 'system';

    private static function db(): Database
    {
        return \sso_db();
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function record(
        string $action,
        string $actorType = self::ACTOR_SYSTEM,
        ?int $actorUserId = null,
        ?int $actorAppId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        array $context = [],
        string $ip = '',
        string $userAgent = ''
    ): int {
        return self::db()->insert(self::TABLE, [
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'actor_app_id' => $actorAppId,
            'action' => Str::substr($action, 0, 64),
            'target_type' => $targetType === null ? null : Str::substr($targetType, 0, 32),
            'target_id' => $targetId,
            'context' => $context === [] ? null : (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => Str::ip($ip),
            'user_agent' => Str::userAgent($userAgent),
            'created_at' => Clock::now(),
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['actor_type'])) {
            $where[] = '`actor_type` = ?';
            $params[] = (string) $filters['actor_type'];
        }
        if (!empty($filters['action'])) {
            $where[] = '`action` LIKE ?';
            $params[] = '%' . (string) $filters['action'] . '%';
        }
        if (!empty($filters['actor_user_id'])) {
            $where[] = '`actor_user_id` = ?';
            $params[] = (int) $filters['actor_user_id'];
        }
        if (!empty($filters['actor_app_id'])) {
            $where[] = '`actor_app_id` = ?';
            $params[] = (int) $filters['actor_app_id'];
        }
        if (!empty($filters['target_type'])) {
            $where[] = '`target_type` = ?';
            $params[] = (string) $filters['target_type'];
        }
        if (!empty($filters['target_id'])) {
            $where[] = '`target_id` = ?';
            $params[] = (int) $filters['target_id'];
        }
        if (!empty($filters['ip'])) {
            $where[] = '`ip_address` LIKE ?';
            $params[] = '%' . (string) $filters['ip'] . '%';
        }
        if (!empty($filters['q'])) {
            $where[] = '(`action` LIKE ? OR `context` LIKE ?)';
            $like = '%' . (string) $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT l.*, u.`email` AS actor_email, a.`name` AS actor_app_name
                FROM `audit_logs` l
                LEFT JOIN `users` u ON u.`id` = l.`actor_user_id`
                LEFT JOIN `apps` a ON a.`id` = l.`actor_app_id`';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.`id` DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset);

        return array_map([self::class, 'publicArray'], self::db()->fetchAll($sql, $params));
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function count(array $filters = []): int
    {
        $where = [];
        $params = [];
        if (!empty($filters['actor_type'])) {
            $where[] = '`actor_type` = ?';
            $params[] = (string) $filters['actor_type'];
        }
        if (!empty($filters['action'])) {
            $where[] = '`action` LIKE ?';
            $params[] = '%' . (string) $filters['action'] . '%';
        }
        if (!empty($filters['target_type']) && !empty($filters['target_id'])) {
            $where[] = '`target_type` = ? AND `target_id` = ?';
            $params[] = (string) $filters['target_type'];
            $params[] = (int) $filters['target_id'];
        }
        $sql = 'SELECT COUNT(*) FROM `audit_logs`';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return self::db()->count($sql, $params);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function publicArray(array $row): array
    {
        $context = json_decode((string) ($row['context'] ?? ''), true);
        return [
            'id' => (int) $row['id'],
            'actor_type' => (string) $row['actor_type'],
            'actor_user_id' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            'actor_email' => $row['actor_email'] ?? null,
            'actor_app_id' => $row['actor_app_id'] === null ? null : (int) $row['actor_app_id'],
            'actor_app_name' => $row['actor_app_name'] ?? null,
            'action' => (string) $row['action'],
            'target_type' => $row['target_type'] ?? null,
            'target_id' => $row['target_id'] === null ? null : (int) $row['target_id'],
            'context' => is_array($context) ? $context : null,
            'ip_address' => $row['ip_address'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
