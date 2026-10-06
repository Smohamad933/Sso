<?php

declare(strict_types=1);

namespace Sso\Services;

use Sso\Api\ApiException;
use Sso\Data\Database;
use Sso\Models\Membership;
use Sso\Models\Roles;
use Sso\Models\Token;
use Sso\Models\User;
use Sso\Support\Clock;
use Sso\Support\Str;

/**
 * عملیات مربوط به کاربران (هم از طریق API و هم پنل مدیریت).
 */
final class UserService
{
    public function __construct(private Database $db)
    {
    }

    // ------------------------------------------------------------------ جستجو

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['u.`deleted_at` IS NULL'];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = '(u.`email` LIKE ? OR u.`full_name` LIKE ? OR u.`uuid` = ?)';
            $like = '%' . (string) $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = (string) $filters['q'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'u.`status` = ?';
            $params[] = (string) $filters['status'];
        }
        if (array_key_exists('is_super_admin', $filters) && $filters['is_super_admin'] !== null) {
            $where[] = 'u.`is_super_admin` = ?';
            $params[] = $filters['is_super_admin'] ? 1 : 0;
        }
        if (!empty($filters['app_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM `memberships` m2 WHERE m2.`user_id` = u.`id` AND m2.`app_id` = ?)';
            $params[] = (int) $filters['app_id'];
            if (!empty($filters['role'])) {
                $where[] = 'EXISTS (SELECT 1 FROM `memberships` m3 WHERE m3.`user_id` = u.`id` AND m3.`app_id` = ? AND m3.`role` = ?)';
                $params[] = (int) $filters['app_id'];
                $params[] = (string) $filters['role'];
            }
        }
        if (!empty($filters['email'])) {
            $where[] = 'u.`email` = ?';
            $params[] = Str::normalizeEmail((string) $filters['email']);
        }

        $whereSql = implode(' AND ', $where);
        $total = $this->db->count('SELECT COUNT(*) FROM `users` u WHERE ' . $whereSql, $params);

        $sortable = ['id', 'email', 'full_name', 'created_at', 'last_login_at', 'status'];
        $sort = in_array((string) ($filters['sort'] ?? ''), $sortable, true) ? (string) $filters['sort'] : 'id';
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->fetchAll(
            'SELECT u.* FROM `users` u WHERE ' . $whereSql
            . ' ORDER BY u.`' . $sort . '` ' . $direction
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        $appId = !empty($filters['app_id']) ? (int) $filters['app_id'] : null;
        $items = [];
        foreach ($rows as $row) {
            $options = ['with_memberships' => true, 'admin_view' => true];
            if ($appId !== null) {
                $membership = Membership::find($appId, (int) $row['id']);
                if ($membership !== null) {
                    $options['role'] = (string) $membership['role'];
                    $options['membership_status'] = (string) $membership['status'];
                }
            }
            $items[] = User::publicArray($row, $options);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * آمار کلی برای داشبورد.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $db = $this->db;
        return [
            'users' => $db->count('SELECT COUNT(*) FROM `users` WHERE `deleted_at` IS NULL'),
            'users_active' => $db->count('SELECT COUNT(*) FROM `users` WHERE `deleted_at` IS NULL AND `status` = ?', [User::STATUS_ACTIVE]),
            'users_suspended' => $db->count('SELECT COUNT(*) FROM `users` WHERE `deleted_at` IS NULL AND `status` = ?', [User::STATUS_SUSPENDED]),
            'apps' => $db->count('SELECT COUNT(*) FROM `apps`'),
            'apps_active' => $db->count('SELECT COUNT(*) FROM `apps` WHERE `status` = ?', [\Sso\Models\Application::STATUS_ACTIVE]),
            'memberships' => $db->count('SELECT COUNT(*) FROM `memberships`'),
            'active_tokens' => $db->count('SELECT COUNT(*) FROM `api_tokens` WHERE `revoked_at` IS NULL AND `expires_at` > ?', [Clock::now()]),
            'logins_today' => $db->count('SELECT COUNT(*) FROM `audit_logs` WHERE `action` IN (\'auth.login\',\'admin.login\') AND `created_at` > ?', [Clock::ago(86400)]),
            'failed_logins_today' => $db->count('SELECT COUNT(*) FROM `audit_logs` WHERE `action` = \'auth.login_failed\' AND `created_at` > ?', [Clock::ago(86400)]),
        ];
    }

    // ------------------------------------------------------------------ عملیات

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createUser(array $data): array
    {
        $tokens = \sso_app()->tokens();

        if (User::findByEmail((string) $data['email'], true) !== null) {
            throw new ApiException('email_taken', 'این ایمیل قبلاً ثبت شده است.', 409, ['email' => 'این ایمیل قبلاً ثبت شده است.']);
        }

        $id = User::create([
            'email' => (string) $data['email'],
            'password_hash' => $tokens->hashPassword((string) $data['password']),
            'full_name' => $data['full_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'status' => $data['status'] ?? User::STATUS_ACTIVE,
            'is_super_admin' => $data['is_super_admin'] ?? false,
            'email_verified_at' => !empty($data['email_verified']) ? Clock::now() : null,
            'password_changed_at' => Clock::now(),
        ]);

        /** @var array<string, mixed> $user */
        $user = User::find($id);
        return $user;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateUser(int $id, array $data): void
    {
        $payload = [];
        foreach (['email', 'phone', 'full_name', 'avatar_url', 'metadata', 'status', 'email_verified_at'] as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = $data[$column];
            }
        }
        if (array_key_exists('is_super_admin', $data)) {
            $payload['is_super_admin'] = (int) (bool) $data['is_super_admin'];
        }
        if ($payload !== []) {
            User::update($id, $payload);
        }
    }

    public function setPassword(int $id, string $newPassword): void
    {
        $error = TokenService::passwordError($newPassword);
        if ($error !== null) {
            throw new ApiException('weak_password', $error, 422, ['password' => $error]);
        }
        $hash = \sso_app()->tokens()->hashPassword($newPassword);
        User::update($id, [
            'password_hash' => $hash,
            'password_changed_at' => Clock::now(),
            'failed_logins' => 0,
            'locked_until' => null,
        ]);
        Token::revokeAllForUser($id);
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, User::statuses(), true)) {
            throw new ApiException('invalid_status', 'وضعیت نامعتبر است.', 422);
        }
        User::update($id, ['status' => $status]);
        if ($status !== User::STATUS_ACTIVE) {
            Token::revokeAllForUser($id);
        }
    }

    public function deleteUser(int $id): void
    {
        User::softDelete($id);
        Token::revokeAllForUser($id);
        $this->db->delete('memberships', '`user_id` = ?', [$id]);
    }

    // ------------------------------------------------------------------ نقش‌ها

    public function setRole(int $appId, int $userId, string $role, string $actorRole = Roles::OWNER): void
    {
        if (!Roles::isValid($role)) {
            throw new ApiException('invalid_role', 'نقش نامعتبر است.', 422, ['role' => 'نقش باید یکی از موارد زیر باشد: ' . implode(', ', Roles::all())]);
        }
        if (!Roles::canManage($actorRole, $role)) {
            throw new ApiException('role_not_allowed', 'نقشِ شما اجازه‌ی انتصاب این نقش را ندارد.', 403);
        }
        if (Membership::find($appId, $userId) === null) {
            throw new ApiException('not_a_member', 'این کاربر عضو این اپلیکیشن نیست.', 404);
        }
        Membership::updateRole($appId, $userId, $role);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function attachToApp(int $appId, int $userId, string $role = Roles::MEMBER, ?array $metadata = null): void
    {
        Membership::attach($appId, $userId, $role, $metadata);
    }

    public function detachFromApp(int $appId, int $userId): void
    {
        Membership::detach($appId, $userId);
        Token::revokeAllForUser($userId, $appId);
    }
}
