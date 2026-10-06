<?php

declare(strict_types=1);

namespace Sso\Services;

use Sso\Data\Database;
use Sso\Models\AuditLog;

/**
 * ثبت یکپارچه‌ی رویدادها.
 */
final class AuditService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function record(
        string $action,
        string $actorType = AuditLog::ACTOR_SYSTEM,
        ?int $actorUserId = null,
        ?int $actorAppId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        array $context = [],
        string $ip = '',
        string $userAgent = ''
    ): int {
        return AuditLog::record(
            $action,
            $actorType,
            $actorUserId,
            $actorAppId,
            $targetType,
            $targetId,
            $context,
            $ip,
            $userAgent
        );
    }

    public function loginFailed(string $email, string $ip, string $userAgent, ?int $appId, string $reason): void
    {
        $this->db->insert('login_attempts', [
            'email' => $email,
            'ip_address' => $ip,
            'app_id' => $appId,
            'success' => 0,
            'created_at' => \Sso\Support\Clock::now(),
        ]);

        $this->record(
            'auth.login_failed',
            $appId !== null ? AuditLog::ACTOR_APP : AuditLog::ACTOR_SYSTEM,
            null,
            $appId,
            'user',
            null,
            ['email' => $email, 'reason' => $reason],
            $ip,
            $userAgent
        );
    }

    public function loginSucceeded(int $userId, string $ip, string $userAgent, ?int $appId): void
    {
        $this->db->insert('login_attempts', [
            'email' => (string) (\Sso\Models\User::find($userId)['email'] ?? ''),
            'ip_address' => $ip,
            'app_id' => $appId,
            'success' => 1,
            'created_at' => \Sso\Support\Clock::now(),
        ]);
    }
}
