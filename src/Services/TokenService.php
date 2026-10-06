<?php

declare(strict_types=1);

namespace Sso\Services;

use Sso\Data\Database;
use Sso\Models\Membership;
use Sso\Models\Token;
use Sso\Models\User;
use Sso\Support\Str;

/**
 * صدور، تمدید و بررسی توکن‌ها + هش‌کردن رمز عبور.
 */
final class TokenService
{
    public function __construct(private Database $db)
    {
    }

    // ------------------------------------------------------------------ رمز عبور

    public static function passwordAlgorithm(): string|int
    {
        $configured = (string) \sso_config('security.password_algorithm', 'auto');
        if ($configured === 'argon2id' && defined('PASSWORD_ARGON2ID')) {
            return PASSWORD_ARGON2ID;
        }
        if ($configured === 'bcrypt') {
            return PASSWORD_BCRYPT;
        }
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public function hashPassword(string $plain): string
    {
        $algo = self::passwordAlgorithm();
        $options = $algo === PASSWORD_BCRYPT ? ['cost' => 12] : [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1,
        ];
        return password_hash($plain, $algo, $options);
    }

    public function verifyPassword(string $plain, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::passwordAlgorithm(), self::passwordAlgorithm() === PASSWORD_BCRYPT
            ? ['cost' => 12]
            : ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
    }

    /**
     * بررسی سیاست رمز عبور. null یعنی تأیید شد.
     */
    public static function passwordError(string $plain): ?string
    {
        // در زمان نصب هنوز برنامه بالا نیامده است
        $min = \Sso\Core\App::isBooted()
            ? (int) \sso_config('security.password_min_length', 8)
            : 8;
        if (Str::length($plain) < $min) {
            return sprintf('رمز عبور باید حداقل %d کاراکتر باشد.', $min);
        }
        if (strlen($plain) > 128) {
            return 'رمز عبور نمی‌تواند بیش از ۱۲۸ کاراکتر باشد.';
        }
        $weak = ['123456', '12345678', '123456789', 'password', 'passw0rd', 'qwerty',
            '111111', '123123', 'letmein', 'admin', 'welcome', 'abc123', 'iloveyou'];
        if (in_array(Str::lower($plain), $weak, true)) {
            return 'این رمز عبور بسیار ساده است؛ یک رمز دیگر انتخاب کنید.';
        }
        if (preg_match('/^(.)\1+$/u', $plain) === 1) {
            return 'رمز عبور نمی‌تواند تکرار یک کاراکتر باشد.';
        }
        return null;
    }

    // ------------------------------------------------------------------ توکن‌ها

    /**
     * @param array<string, mixed>|null $app
     * @return array<int, string>
     */
    public static function scopesFor(?array $app): array
    {
        return ['profile:read', 'profile:write'];
    }

    /**
     * صدور جفت توکن دسترسی/تمدید.
     *
     * @param array<string, mixed>|null $app
     * @return array<string, mixed>
     */
    public function issuePair(
        int $userId,
        ?array $app,
        string $ip = '',
        string $userAgent = '',
        ?array $scopes = null
    ): array {
        $settings = $app === null ? [] : \Sso\Models\Application::settings($app);
        $accessTtl = (int) ($settings['access_token_ttl']
            ?? \sso_config('security.access_token_ttl', 3600));
        $refreshTtl = (int) ($settings['refresh_token_ttl']
            ?? \sso_config('security.refresh_token_ttl', 2592000));

        $scopes = $scopes ?? self::scopesFor($app);

        $access = Token::issue($userId, $app['id'] ?? null, Token::TYPE_ACCESS, $accessTtl, $scopes, $ip, $userAgent);
        $refresh = Token::issue($userId, $app['id'] ?? null, Token::TYPE_REFRESH, $refreshTtl, ['refresh'], $ip, $userAgent);

        return [
            'access_token' => $access['token'],
            'refresh_token' => $refresh['token'],
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'refresh_expires_in' => $refreshTtl,
            'scopes' => $scopes,
            'access_token_id' => $access['record']['id'],
            'refresh_token_id' => $refresh['record']['id'],
        ];
    }

    /**
     * تمدید با توکن refresh (چرخش: توکن قدیمی باطل می‌شود).
     *
     * @param array<string, mixed>|null $app
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken, ?array $app, string $ip = '', string $userAgent = ''): array
    {
        $record = Token::findValid($refreshToken, [Token::TYPE_REFRESH]);
        if ($record === null) {
            throw new \Sso\Api\ApiException('invalid_refresh_token', 'توکن تمدید معتبر نیست یا منقضی شده است.', 401);
        }
        if ($app !== null && (int) $record['app_id'] !== (int) $app['id']) {
            throw new \Sso\Api\ApiException('refresh_token_mismatch', 'توکن تمدید متعلق به این اپلیکیشن نیست.', 403);
        }

        $userId = (int) $record['user_id'];
        $user = User::find($userId);
        if ($user === null || $user['deleted_at'] !== null) {
            throw new \Sso\Api\ApiException('user_not_found', 'کاربر یافت نشد.', 404);
        }
        if ((string) $user['status'] !== User::STATUS_ACTIVE) {
            throw new \Sso\Api\ApiException('user_inactive', 'حساب کاربر غیرفعال است.', 403);
        }

        // فقط همین توکن تمدید مصرف می‌شود؛ نشست‌های دیگر دست‌نخورده باقی می‌مانند
        Token::revoke((int) $record['id']);

        $targetApp = $app;
        if ($targetApp === null && $record['app_id'] !== null) {
            $targetApp = \Sso\Models\Application::find((int) $record['app_id']);
        }

        $pair = $this->issuePair($userId, $targetApp, $ip, $userAgent, Token::scopes($record));
        $pair['user'] = $this->userPayload($user, $targetApp);
        return $pair;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $app
     * @return array<string, mixed>
     */
    public function userPayload(array $user, ?array $app): array
    {
        $role = null;
        $membershipStatus = null;
        if ($app !== null) {
            $membership = Membership::find((int) $app['id'], (int) $user['id']);
            if ($membership !== null) {
                $role = (string) $membership['role'];
                $membershipStatus = (string) $membership['status'];
            }
        }

        $options = ['with_memberships' => true];
        if ($role !== null) {
            $options['role'] = $role;
            $options['membership_status'] = $membershipStatus;
        }
        if ($app !== null) {
            $options['app'] = \Sso\Models\Application::publicArray($app, false);
        }

        return User::publicArray($user, $options);
    }

    /**
     * بررسی اعتبار یک توکن (برای اپ‌ها).
     *
     * @return array<string, mixed>
     */
    public function introspect(string $token): array
    {
        $record = Token::findValid($token, [Token::TYPE_ACCESS, Token::TYPE_REFRESH]);
        if ($record === null) {
            return ['active' => false];
        }
        $user = User::find((int) $record['user_id']);
        if ($user === null || $user['deleted_at'] !== null || (string) $user['status'] !== User::STATUS_ACTIVE) {
            return ['active' => false];
        }

        Token::touch((int) $record['id']);

        $app = $record['app_id'] !== null ? \Sso\Models\Application::find((int) $record['app_id']) : null;

        return [
            'active' => true,
            'token_type' => (string) $record['token_type'],
            'scopes' => Token::scopes($record),
            'expires_at' => $record['expires_at'],
            'app_id' => $record['app_id'] === null ? null : (int) $record['app_id'],
            'user' => $this->userPayload($user, $app),
        ];
    }

    /**
     * آیا توکنِ داده‌شده دسترسیِ خواسته‌شده را دارد؟
     *
     * @param array<string, mixed> $record
     */
    public static function hasScope(array $record, string $scope): bool
    {
        $scopes = Token::scopes($record);
        if (in_array('*', $scopes, true)) {
            return true;
        }
        if (in_array($scope, $scopes, true)) {
            return true;
        }
        // profile:write مستلزم profile:read نیست، اما write شامل read می‌شود
        if ($scope === 'profile:read' && in_array('profile:write', $scopes, true)) {
            return true;
        }
        return false;
    }
}
