<?php

declare(strict_types=1);

namespace Sso\Services;

use Sso\Api\ApiException;
use Sso\Data\Database;
use Sso\Models\Application;
use Sso\Models\AuditLog;
use Sso\Models\Membership;
use Sso\Models\Roles;
use Sso\Models\Token;
use Sso\Models\User;
use Sso\Support\Clock;
use Sso\Support\Str;

/**
 * ثبت‌نام، ورود و قفل‌شدن حساب.
 */
final class AuthService
{
    private static ?string $dummyHash = null;

    public function __construct(private Database $db)
    {
    }

    // ------------------------------------------------------------------ ثبت‌نام

    /**
     * ثبت‌نام کاربر در یک اپلیکیشن.
     *
     * اگر کاربر از قبل در سامانه وجود داشته باشد، باید رمز عبور درست را
     * ارائه دهد تا به این اپ متصل شود (جلوگیری از تصاحب حساب).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $app
     * @return array<string, mixed>
     */
    public function register(array $input, array $app, string $ip = '', string $userAgent = ''): array
    {
        $settings = Application::settings($app);
        if (empty($settings['allow_registration'])) {
            throw new ApiException('registration_disabled', 'ثبت‌نام برای این اپلیکیشن غیرفعال است.', 403);
        }
        if ((string) $app['status'] !== Application::STATUS_ACTIVE) {
            throw new ApiException('app_disabled', 'این اپلیکیشن غیرفعال است.', 403);
        }

        $email = Str::normalizeEmail((string) $input['email']);
        $password = (string) $input['password'];
        $role = isset($input['role']) && Roles::isValid((string) $input['role'])
            ? (string) $input['role']
            : (string) ($settings['default_role'] ?? Roles::MEMBER);

        $tokens = \sso_app()->tokens();
        $existing = User::findByEmail($email);

        if ($existing !== null) {
            // کاربر از قبل در سامانه هست: برای اتصال باید رمز را بداند
            if (!$tokens->verifyPassword($password, (string) $existing['password_hash'])) {
                \sso_app()->audit()->loginFailed($email, $ip, $userAgent, (int) $app['id'], 'register_password_mismatch');
                throw new ApiException(
                    'email_taken',
                    'این ایمیل قبلاً ثبت شده است. اگر متعلق به شماست وارد شوید.',
                    409,
                    ['email' => 'این ایمیل قبلاً ثبت شده است.']
                );
            }
            if (Membership::find((int) $app['id'], (int) $existing['id']) !== null) {
                throw new ApiException(
                    'already_registered',
                    'این کاربر قبلاً در این اپلیکیشن عضو شده است.',
                    409,
                    ['email' => 'این کاربر قبلاً در این اپلیکیشن عضو شده است.']
                );
            }

            Membership::attach(
                (int) $app['id'],
                (int) $existing['id'],
                $role,
                is_array($input['metadata'] ?? null) ? $input['metadata'] : null
            );

            $user = User::find((int) $existing['id']) ?? $existing;
            $this->afterRegister($user, $app, $ip, $userAgent, false);
            return $this->registrationResponse($user, $app, $ip, $userAgent, $settings);
        }

        $status = !empty($settings['require_email_verification']) ? User::STATUS_PENDING : User::STATUS_ACTIVE;

        $userId = User::create([
            'email' => $email,
            'password_hash' => $tokens->hashPassword($password),
            'full_name' => $input['full_name'] ?? null,
            'phone' => $input['phone'] ?? null,
            'avatar_url' => $input['avatar_url'] ?? null,
            'metadata' => $input['metadata'] ?? null,
            'status' => $status,
            'password_changed_at' => Clock::now(),
        ]);

        Membership::attach((int) $app['id'], $userId, $role, is_array($input['metadata'] ?? null) ? $input['metadata'] : null);

        /** @var array<string, mixed> $user */
        $user = User::find($userId);
        $this->afterRegister($user, $app, $ip, $userAgent, true);

        return $this->registrationResponse($user, $app, $ip, $userAgent, $settings);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $app
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function registrationResponse(array $user, array $app, string $ip, string $userAgent, array $settings): array
    {
        $tokens = \sso_app()->tokens();
        $requiresVerification = (string) $user['status'] === User::STATUS_PENDING;

        $response = [
            'user' => $tokens->userPayload($user, $app),
            'requires_email_verification' => $requiresVerification,
        ];

        if (!$requiresVerification) {
            $response['tokens'] = $tokens->issuePair((int) $user['id'], $app, $ip, $userAgent);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $app
     */
    private function afterRegister(array $user, array $app, string $ip, string $userAgent, bool $isNew): void
    {
        AuditLog::record(
            $isNew ? 'auth.register' : 'auth.attached',
            AuditLog::ACTOR_APP,
            (int) $user['id'],
            (int) $app['id'],
            'user',
            (int) $user['id'],
            ['app' => (string) $app['slug']],
            $ip,
            $userAgent
        );
    }

    // ------------------------------------------------------------------ ورود

    /**
     * @param array<string, mixed> $app
     * @return array<string, mixed>
     */
    public function login(string $email, string $password, array $app, string $ip = '', string $userAgent = ''): array
    {
        $limiter = \sso_app()->limiter();
        $audit = \sso_app()->audit();

        $ipKey = 'login:ip:' . $ip;
        $emailKey = 'login:email:' . Str::normalizeEmail($email);

        if ($limiter->tooManyAttempts($ipKey, 30, 900)) {
            throw new ApiException('too_many_attempts', 'تلاش‌های زیاد از این آدرس IP. کمی بعد دوباره امتحان کنید.', 429);
        }
        if ($limiter->tooManyAttempts($emailKey, 15, 900)) {
            throw new ApiException('too_many_attempts', 'تلاش‌های زیاد برای این حساب. کمی بعد دوباره امتحان کنید.', 429);
        }

        $user = User::findByEmail($email);

        if ($user === null) {
            $limiter->hit($ipKey, 900);
            $limiter->hit($emailKey, 900);
            $this->dummyVerify($password);
            $audit->loginFailed($email, $ip, $userAgent, (int) $app['id'], 'unknown_email');
            throw new ApiException('invalid_credentials', 'ایمیل یا رمز عبور اشتباه است.', 401);
        }

        if ($this->isLocked($user)) {
            $audit->loginFailed($email, $ip, $userAgent, (int) $app['id'], 'locked');
            throw new ApiException('account_locked', 'حساب به‌طور موقت قفل شده است. چند دقیقه دیگر تلاش کنید.', 429);
        }

        /** @var TokenService $tokens */
        $tokens = \sso_app()->tokens();

        if (!$tokens->verifyPassword($password, (string) $user['password_hash'])) {
            $limiter->hit($ipKey, 900);
            $limiter->hit($emailKey, 900);
            $this->registerFailure($user);
            $audit->loginFailed($email, $ip, $userAgent, (int) $app['id'], 'bad_password');
            throw new ApiException('invalid_credentials', 'ایمیل یا رمز عبور اشتباه است.', 401);
        }

        if ($tokens->needsRehash((string) $user['password_hash'])) {
            User::update((int) $user['id'], ['password_hash' => $tokens->hashPassword($password)]);
        }

        // بررسی وضعیت حساب و عضویت
        if ((string) $user['status'] !== User::STATUS_ACTIVE) {
            $audit->loginFailed($email, $ip, $userAgent, (int) $app['id'], 'inactive:' . (string) $user['status']);
            throw new ApiException('account_inactive', $this->statusMessage((string) $user['status']), 403);
        }
        if ((string) $app['status'] !== Application::STATUS_ACTIVE) {
            throw new ApiException('app_disabled', 'این اپلیکیشن غیرفعال است.', 403);
        }

        $membership = Membership::find((int) $app['id'], (int) $user['id']);
        if ($membership === null) {
            $audit->loginFailed($email, $ip, $userAgent, (int) $app['id'], 'not_a_member');
            throw new ApiException('not_a_member', 'این کاربر به این اپلیکیشن دسترسی ندارد.', 403);
        }
        if ((string) $membership['status'] !== Membership::STATUS_ACTIVE) {
            $audit->loginFailed($email, $ip, $userAgent, (int) $app['id'], 'membership_suspended');
            throw new ApiException('membership_suspended', 'دسترسی این کاربر به اپلیکیشن مسدود شده است.', 403);
        }

        // موفق
        $limiter->clear($ipKey);
        $limiter->clear($emailKey);
        User::update((int) $user['id'], [
            'failed_logins' => 0,
            'locked_until' => null,
            'last_login_at' => Clock::now(),
        ]);

        AuditLog::record(
            'auth.login',
            AuditLog::ACTOR_APP,
            (int) $user['id'],
            (int) $app['id'],
            'user',
            (int) $user['id'],
            ['app' => (string) $app['slug']],
            $ip,
            $userAgent
        );

        /** @var array<string, mixed> $fresh */
        $fresh = User::find((int) $user['id']);

        return [
            'user' => $tokens->userPayload($fresh, $app),
            'tokens' => $tokens->issuePair((int) $user['id'], $app, $ip, $userAgent),
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isLocked(array $user): bool
    {
        $until = $user['locked_until'] ?? null;
        if ($until === null) {
            return false;
        }
        return (string) $until > Clock::now();
    }

    /**
     * @param array<string, mixed> $user
     */
    private function registerFailure(array $user): void
    {
        $max = (int) \sso_config('security.login_max_attempts', 5);
        $minutes = (int) \sso_config('security.login_lockout_minutes', 15);
        $failed = (int) ($user['failed_logins'] ?? 0) + 1;

        $payload = ['failed_logins' => $failed];
        if ($failed >= $max) {
            $payload['locked_until'] = Clock::inSeconds($minutes * 60);
            $payload['failed_logins'] = 0;
        }
        User::update((int) $user['id'], $payload);
    }

    private function dummyVerify(string $password): void
    {
        if (self::$dummyHash === null) {
            self::$dummyHash = \sso_app()->tokens()->hashPassword(Str::randomHex(16));
        }
        password_verify($password, self::$dummyHash);
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            User::STATUS_PENDING => 'حساب هنوز تأیید نشده است (در انتظار تأیید ایمیل).',
            User::STATUS_SUSPENDED => 'حساب مسدود شده است.',
            default => 'حساب غیرفعال است.',
        };
    }

    // ------------------------------------------------------------------ مدیریت

    /**
     * ورود پنل مدیریت — فقط کاربران is_super_admin.
     *
     * @return array<string, mixed>
     */
    public function loginAdmin(string $email, string $password, string $ip = '', string $userAgent = ''): array
    {
        $limiter = \sso_app()->limiter();
        $key = 'admin:login:' . $ip;
        if ($limiter->tooManyAttempts($key, 10, 900)) {
            throw new ApiException('too_many_attempts', 'تلاش‌های زیاد. ۱۵ دقیقه دیگر امتحان کنید.', 429);
        }

        $user = User::findByEmail($email);
        /** @var TokenService $tokens */
        $tokens = \sso_app()->tokens();

        $ok = $user !== null
            && (int) ($user['is_super_admin'] ?? 0) === 1
            && !$this->isLocked($user)
            && $tokens->verifyPassword($password, (string) $user['password_hash']);

        if (!$ok) {
            $limiter->hit($key, 900);
            if ($user !== null) {
                $this->registerFailure($user);
            }
            \sso_app()->audit()->loginFailed($email, $ip, $userAgent, null, 'admin');
            throw new ApiException('invalid_credentials', 'ایمیل یا رمز عبور اشتباه است.', 401);
        }

        /** @var array<string, mixed> $user */
        $limiter->clear($key);
        User::update((int) $user['id'], [
            'failed_logins' => 0,
            'locked_until' => null,
            'last_login_at' => Clock::now(),
        ]);
        AuditLog::record('admin.login', AuditLog::ACTOR_ADMIN, (int) $user['id'], null, 'user', (int) $user['id'], [], $ip, $userAgent);

        return User::find((int) $user['id']) ?? $user;
    }

    /**
     * تغییر رمز عبور توسط خود کاربر.
     *
     * @param array<string, mixed> $user
     */
    public function changePassword(array $user, string $currentPassword, string $newPassword): void
    {
        /** @var TokenService $tokens */
        $tokens = \sso_app()->tokens();
        if (!$tokens->verifyPassword($currentPassword, (string) $user['password_hash'])) {
            throw new ApiException('invalid_credentials', 'رمز عبور فعلی اشتباه است.', 422, ['current_password' => 'رمز عبور فعلی اشتباه است.']);
        }
        $error = TokenService::passwordError($newPassword);
        if ($error !== null) {
            throw new ApiException('weak_password', $error, 422, ['new_password' => $error]);
        }

        User::update((int) $user['id'], [
            'password_hash' => $tokens->hashPassword($newPassword),
            'password_changed_at' => Clock::now(),
        ]);
        // تمام نشست‌های دیگر این کاربر باطل می‌شود
        Token::revokeAllForUser((int) $user['id']);
        AuditLog::record('auth.password_changed', AuditLog::ACTOR_USER, (int) $user['id'], null, 'user', (int) $user['id']);
    }
}
