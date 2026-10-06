<?php

declare(strict_types=1);

namespace Sso\Api\Controllers;

use Sso\Api\ApiException;
use Sso\Api\Context;
use Sso\Http\Request;
use Sso\Http\Response;
use Sso\Models\AuditLog;
use Sso\Models\Membership;
use Sso\Models\Token;
use Sso\Models\User;
use Sso\Services\TokenService;
use Sso\Support\Clock;
use Sso\Support\Str;

/**
 * احراز هویت: ثبت‌نام، ورود، تمدید، خروج، بازیابی رمز.
 */
final class AuthController extends BaseController
{
    /**
     * POST /v1/auth/register
     */
    public function register(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        $min = (int) \sso_config('security.password_min_length', 8);

        $data = $this->validated($request, [
            'email' => 'required|email|max:190',
            'password' => 'required|string|min:' . $min . '|max:128',
            'full_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:32',
            'avatar_url' => 'nullable|url|max:500',
            'role' => 'nullable|in:owner,admin,member,viewer',
            'metadata' => 'nullable|array',
        ]);

        $error = TokenService::passwordError((string) $data['password']);
        if ($error !== null) {
            throw new ApiException('weak_password', $error, 422, ['password' => $error]);
        }

        /** @var \Sso\Services\AuthService $auth */
        $auth = \sso_app()->auth();
        $result = $auth->register($data, $app, $context->ip, $context->userAgent);

        return Response::json($result, 201);
    }

    /**
     * POST /v1/auth/login
     */
    public function login(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();

        $data = $this->validated($request, [
            'email' => 'required|email|max:190',
            'password' => 'required|string|max:128',
        ]);

        /** @var \Sso\Services\AuthService $auth */
        $auth = \sso_app()->auth();
        $result = $auth->login(
            (string) $data['email'],
            (string) $data['password'],
            $app,
            $context->ip,
            $context->userAgent
        );

        return Response::json($result);
    }

    /**
     * POST /v1/auth/refresh
     */
    public function refresh(Request $request, array $params, Context $context): Response
    {
        $data = $this->validated($request, ['refresh_token' => 'required|string|max:200']);

        /** @var \Sso\Services\TokenService $tokens */
        $tokens = \sso_app()->tokens();
        $result = $tokens->refresh(
            (string) $data['refresh_token'],
            $context->app,
            $context->ip,
            $context->userAgent
        );

        return Response::json($result);
    }

    /**
     * POST /v1/auth/logout  (نیازمند توکن کاربر)
     */
    public function logout(Request $request, array $params, Context $context): Response
    {
        $token = $context->requireToken();
        $user = $context->requireUser();

        Token::revoke((int) $token['id']);

        $revokeAll = filter_var($request->input('everywhere', false), FILTER_VALIDATE_BOOL);
        if ($revokeAll) {
            Token::revokeAllForUser((int) $user['id']);
        }

        AuditLog::record(
            'auth.logout',
            AuditLog::ACTOR_USER,
            (int) $user['id'],
            $token['app_id'] === null ? null : (int) $token['app_id'],
            'user',
            (int) $user['id'],
            ['everywhere' => $revokeAll],
            $context->ip,
            $context->userAgent
        );

        return Response::json(['revoked' => true, 'everywhere' => $revokeAll]);
    }

    /**
     * POST /v1/auth/introspect — بررسی اعتبار توکن
     */
    public function introspect(Request $request, array $params, Context $context): Response
    {
        $data = $this->validated($request, ['token' => 'required|string|max:200']);

        /** @var \Sso\Services\TokenService $tokens */
        $tokens = \sso_app()->tokens();
        $result = $tokens->introspect((string) $data['token']);

        if ($context->app !== null && !empty($result['active'])) {
            $appId = $result['app_id'] ?? null;
            if ($appId !== null && (int) $appId !== (int) $context->app['id']) {
                return Response::json(['active' => false]);
            }
        }

        return Response::json($result);
    }

    /**
     * POST /v1/auth/password/forgot
     */
    public function forgotPassword(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        $data = $this->validated($request, ['email' => 'required|email|max:190']);

        $user = User::findByEmail((string) $data['email']);
        $membership = $user === null ? null : Membership::find((int) $app['id'], (int) $user['id']);

        // پاسخ یکسان در هر حالت تا وجود حساب فاش نشود
        if ($user === null || $membership === null) {
            return Response::json([
                'sent' => true,
                'message' => 'اگر حسابی با این ایمیل وجود داشته باشد، لینک بازیابی ارسال می‌شود.',
            ]);
        }

        /** @var \Sso\Services\PasswordBroker $broker */
        $broker = \sso_app()->passwords();
        $reset = $broker->createToken($user, $app);

        return Response::json([
            'sent' => true,
            'expires_in' => $reset['expires_in'],
            'token' => $reset['token'],
            'message' => 'توکن بازیابی صادر شد. اپلیکیشن باید آن را از طریق ایمیل برای کاربر بفرستد.',
        ]);
    }

    /**
     * POST /v1/auth/password/reset
     */
    public function resetPassword(Request $request, array $params, Context $context): Response
    {
        $min = (int) \sso_config('security.password_min_length', 8);
        $data = $this->validated($request, [
            'token' => 'required|string|max:200',
            'password' => 'required|string|min:' . $min . '|max:128',
        ]);

        /** @var \Sso\Services\PasswordBroker $broker */
        $broker = \sso_app()->passwords();
        $user = $broker->reset((string) $data['token'], (string) $data['password']);

        /** @var \Sso\Services\TokenService $tokens */
        $tokens = \sso_app()->tokens();

        return Response::json([
            'reset' => true,
            'user' => $tokens->userPayload($user, $context->app),
        ]);
    }

    /**
     * POST /v1/auth/verify-email
     */
    public function verifyEmail(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        $data = $this->validated($request, ['user_id' => 'required|string|max:64']);

        ['user' => $user] = $this->userForApp((string) $data['user_id'], $context);

        User::update((int) $user['id'], [
            'email_verified_at' => Clock::now(),
            'status' => (string) $user['status'] === User::STATUS_PENDING ? User::STATUS_ACTIVE : (string) $user['status'],
        ]);

        AuditLog::record(
            'auth.email_verified',
            AuditLog::ACTOR_APP,
            (int) $user['id'],
            (int) $app['id'],
            'user',
            (int) $user['id'],
            [],
            $context->ip,
            $context->userAgent
        );

        $fresh = User::find((int) $user['id']) ?? $user;
        return Response::json(['verified' => true, 'user' => \sso_app()->tokens()->userPayload($fresh, $app)]);
    }
}
