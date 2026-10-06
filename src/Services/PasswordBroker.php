<?php

declare(strict_types=1);

namespace Sso\Services;

use Sso\Api\ApiException;
use Sso\Data\Database;
use Sso\Models\AuditLog;
use Sso\Models\Token;
use Sso\Models\User;
use Sso\Support\Clock;
use Sso\Support\Str;

/**
 * بازیابی رمز عبور.
 *
 * ایمیل ارسال نمی‌شود (تنظیمات mail روی IIS معمولاً در دسترس نیست)؛
 * توکن تولید و به اپلیکیشن برگردانده می‌شود تا خودش ایمیل را بفرستد.
 */
final class PasswordBroker
{
    /** توکن‌ها ۶۰ دقیقه معتبرند */
    public const TTL_SECONDS = 3600;

    public function __construct(private Database $db)
    {
    }

    public function hash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /**
     * ساخت توکن بازیابی برای کاربر.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $app
     */
    public function createToken(array $user, ?array $app = null): array
    {
        $token = 'prt_' . Str::randomHex(32);
        $expiresAt = Clock::inSeconds(self::TTL_SECONDS);

        $this->db->delete('password_resets', '`user_id` = ? AND `used_at` IS NULL', [(int) $user['id']]);

        $id = $this->db->insert('password_resets', [
            'user_id' => (int) $user['id'],
            'app_id' => $app['id'] ?? null,
            'token_hash' => $this->hash($token),
            'expires_at' => $expiresAt,
            'created_at' => Clock::now(),
        ]);

        AuditLog::record(
            'auth.password_reset_requested',
            $app !== null ? AuditLog::ACTOR_APP : AuditLog::ACTOR_ADMIN,
            (int) $user['id'],
            $app['id'] ?? null,
            'user',
            (int) $user['id']
        );

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'expires_in' => self::TTL_SECONDS,
            'reset_id' => $id,
        ];
    }

    /**
     * مصرف توکن و تنظیم رمز جدید.
     *
     * @return array<string, mixed>
     */
    public function reset(string $token, string $newPassword): array
    {
        $row = $this->db->fetch('SELECT * FROM `password_resets` WHERE `token_hash` = ?', [$this->hash($token)]);
        if ($row === null || $row['used_at'] !== null || (string) $row['expires_at'] < Clock::now()) {
            throw new ApiException('invalid_reset_token', 'توکن بازیابی معتبر نیست یا منقضی شده است.', 400);
        }

        $error = TokenService::passwordError($newPassword);
        if ($error !== null) {
            throw new ApiException('weak_password', $error, 422, ['password' => $error]);
        }

        $userId = (int) $row['user_id'];
        $user = User::find($userId);
        if ($user === null) {
            throw new ApiException('user_not_found', 'کاربر یافت نشد.', 404);
        }

        /** @var TokenService $tokens */
        $tokens = \sso_app()->tokens();
        User::update($userId, [
            'password_hash' => $tokens->hashPassword($newPassword),
            'password_changed_at' => Clock::now(),
            'failed_logins' => 0,
            'locked_until' => null,
        ]);
        Token::revokeAllForUser($userId);

        $this->db->update('password_resets', ['used_at' => Clock::now()], '`id` = ?', [(int) $row['id']]);

        AuditLog::record(
            'auth.password_reset_completed',
            AuditLog::ACTOR_APP,
            $userId,
            $row['app_id'] === null ? null : (int) $row['app_id'],
            'user',
            $userId
        );

        return User::find($userId) ?? $user;
    }
}
