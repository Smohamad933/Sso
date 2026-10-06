<?php

declare(strict_types=1);

namespace Sso\Api\Controllers;

use Sso\Api\ApiException;
use Sso\Api\Context;
use Sso\Http\Request;
use Sso\Http\Response;
use Sso\Models\Application;
use Sso\Models\AuditLog;
use Sso\Models\Membership;
use Sso\Models\Roles;
use Sso\Models\Token;
use Sso\Models\User;
use Sso\Services\TokenService;
use Sso\Support\Clock;

/**
 * مدیریت کاربرانِ یک اپلیکیشن از طریق API.
 */
final class UsersController extends BaseController
{
    /**
     * GET /v1/users
     */
    public function index(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();

        $filters = [
            'app_id' => (int) $app['id'],
            'q' => $request->query('q'),
            'status' => $request->query('status'),
            'role' => $request->query('role'),
            'sort' => $request->query('sort', 'id'),
            'direction' => $request->query('direction', 'desc'),
        ];

        $result = \sso_app()->users()->paginate(
            $filters,
            max(1, (int) $request->query('page', 1)),
            max(1, min(100, (int) $request->query('per_page', 25)))
        );

        return Response::json($result);
    }

    /**
     * POST /v1/users — ایجاد کاربر جدید (یا اتصال کاربر موجود)
     */
    public function store(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        $min = (int) \sso_config('security.password_min_length', 8);

        $data = $this->validated($request, [
            'email' => 'required|email|max:190',
            'password' => 'nullable|string|min:' . $min . '|max:128',
            'full_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:32',
            'avatar_url' => 'nullable|url|max:500',
            'role' => 'nullable|in:' . implode(',', Roles::all()),
            'metadata' => 'nullable|array',
            'link_existing' => 'nullable|bool',
            'email_verified' => 'nullable|bool',
        ]);

        $settings = Application::settings($app);
        $role = isset($data['role']) ? (string) $data['role'] : (string) ($settings['default_role'] ?? Roles::MEMBER);
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : null;

        $existing = User::findByEmail((string) $data['email']);
        if ($existing !== null) {
            if (empty($data['link_existing'])) {
                throw new ApiException(
                    'email_taken',
                    'این ایمیل قبلاً در سامانه ثبت شده است. برای اتصال، link_existing را بفرستید.',
                    409,
                    ['email' => 'این ایمیل قبلاً ثبت شده است.']
                );
            }
            if (Membership::find((int) $app['id'], (int) $existing['id']) !== null) {
                throw new ApiException('already_registered', 'این کاربر قبلاً در این اپلیکیشن عضو است.', 409);
            }
            Membership::attach((int) $app['id'], (int) $existing['id'], $role, $metadata);
            $fresh = User::find((int) $existing['id']) ?? $existing;

            AuditLog::record('users.attached', AuditLog::ACTOR_APP, (int) $existing['id'], (int) $app['id'], 'user', (int) $existing['id'], ['role' => $role], $context->ip, $context->userAgent);

            return Response::json(['user' => \sso_app()->tokens()->userPayload($fresh, $app)], 201);
        }

        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            $password = 'sk_' . \Sso\Support\Str::randomAlnum(16);
        }
        $error = TokenService::passwordError($password);
        if ($error !== null) {
            throw new ApiException('weak_password', $error, 422, ['password' => $error]);
        }

        $status = !empty($settings['require_email_verification']) && empty($data['email_verified'])
            ? User::STATUS_PENDING
            : User::STATUS_ACTIVE;

        $generatedPassword = ($data['password'] ?? '') === '';
        $userId = User::create([
            'email' => (string) $data['email'],
            'password_hash' => \sso_app()->tokens()->hashPassword($password),
            'full_name' => $data['full_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
            'metadata' => $metadata,
            'status' => $status,
            'email_verified_at' => !empty($data['email_verified']) ? Clock::now() : null,
            'password_changed_at' => Clock::now(),
        ]);
        Membership::attach((int) $app['id'], $userId, $role, $metadata);

        AuditLog::record('users.created', AuditLog::ACTOR_APP, $userId, (int) $app['id'], 'user', $userId, ['role' => $role], $context->ip, $context->userAgent);

        /** @var array<string, mixed> $user */
        $user = User::find($userId);

        $payload = ['user' => \sso_app()->tokens()->userPayload($user, $app)];
        if ($generatedPassword) {
            $payload['generated_password'] = $password;
        }

        return Response::json($payload, 201);
    }

    /**
     * GET /v1/users/{id}
     */
    public function show(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        ['user' => $user, 'membership' => $membership] = $this->userForApp((string) $params['id'], $context);

        $payload = User::publicArray($user, [
            'role' => (string) $membership['role'],
            'membership_status' => (string) $membership['status'],
            'app' => Application::publicArray($app, false),
            'with_memberships' => true,
        ]);
        $payload['membership_metadata'] = Membership::publicArray($membership)['metadata'] ?? null;

        return Response::json(['user' => $payload]);
    }

    /**
     * PATCH /v1/users/{id}
     */
    public function update(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        ['user' => $user, 'membership' => $membership] = $this->userForApp((string) $params['id'], $context);

        $data = $this->validated($request, [
            'email' => 'nullable|email|max:190',
            'full_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:32',
            'avatar_url' => 'nullable|url|max:500',
            'metadata' => 'nullable|array',
            'membership_metadata' => 'nullable|array',
            'role' => 'nullable|in:' . implode(',', Roles::all()),
            'email_verified' => 'nullable|bool',
        ]);

        $userData = [];
        foreach (['email', 'full_name', 'phone', 'avatar_url', 'metadata'] as $field) {
            if (array_key_exists($field, $data)) {
                $userData[$field] = $data[$field];
            }
        }
        if (array_key_exists('email_verified', $data)) {
            $userData['email_verified_at'] = $data['email_verified'] ? Clock::now() : null;
        }
        if ($userData !== []) {
            if (isset($userData['email'])) {
                $taken = User::findByEmail((string) $userData['email']);
                if ($taken !== null && (int) $taken['id'] !== (int) $user['id']) {
                    throw new ApiException('email_taken', 'این ایمیل متعلق به کاربر دیگری است.', 409, ['email' => 'این ایمیل متعلق به کاربر دیگری است.']);
                }
            }
            User::update((int) $user['id'], $userData);
        }

        if (array_key_exists('role', $data)) {
            \sso_app()->users()->setRole((int) $app['id'], (int) $user['id'], (string) $data['role'], $this->actorRole($context));
        }
        if (array_key_exists('membership_metadata', $data)) {
            Membership::updateMetadata((int) $app['id'], (int) $user['id'], $data['membership_metadata']);
        }

        AuditLog::record('users.updated', AuditLog::ACTOR_APP, (int) $user['id'], (int) $app['id'], 'user', (int) $user['id'], ['fields' => array_keys($data)], $context->ip, $context->userAgent);

        $fresh = User::find((int) $user['id']) ?? $user;
        return Response::json(['user' => \sso_app()->tokens()->userPayload($fresh, $app)]);
    }

    /**
     * DELETE /v1/users/{id} — حذف دسترسی کاربر از این اپلیکیشن
     */
    public function destroy(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        ['user' => $user] = $this->userForApp((string) $params['id'], $context);

        \sso_app()->users()->detachFromApp((int) $app['id'], (int) $user['id']);

        AuditLog::record('users.detached', AuditLog::ACTOR_APP, (int) $user['id'], (int) $app['id'], 'user', (int) $user['id'], [], $context->ip, $context->userAgent);

        return Response::json(['deleted' => true, 'user_id' => (int) $user['id']]);
    }

    /**
     * POST /v1/users/{id}/role
     */
    public function role(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        ['user' => $user] = $this->userForApp((string) $params['id'], $context);

        $data = $this->validated($request, ['role' => 'required|in:' . implode(',', Roles::all())]);
        \sso_app()->users()->setRole((int) $app['id'], (int) $user['id'], (string) $data['role'], $this->actorRole($context));

        AuditLog::record('users.role_changed', AuditLog::ACTOR_APP, (int) $user['id'], (int) $app['id'], 'user', (int) $user['id'], ['role' => (string) $data['role']], $context->ip, $context->userAgent);

        $fresh = User::find((int) $user['id']) ?? $user;
        return Response::json(['user' => \sso_app()->tokens()->userPayload($fresh, $app)]);
    }

    /**
     * POST /v1/users/{id}/suspend
     */
    public function suspend(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        ['user' => $user] = $this->userForApp((string) $params['id'], $context);

        Membership::setStatus((int) $app['id'], (int) $user['id'], Membership::STATUS_SUSPENDED);
        Token::revokeAllForUser((int) $user['id'], (int) $app['id']);

        AuditLog::record('users.suspended', AuditLog::ACTOR_APP, (int) $user['id'], (int) $app['id'], 'user', (int) $user['id'], [], $context->ip, $context->userAgent);

        $fresh = User::find((int) $user['id']) ?? $user;
        return Response::json(['user' => \sso_app()->tokens()->userPayload($fresh, $app)]);
    }

    /**
     * POST /v1/users/{id}/activate
     */
    public function activate(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        ['user' => $user] = $this->userForApp((string) $params['id'], $context);

        Membership::setStatus((int) $app['id'], (int) $user['id'], Membership::STATUS_ACTIVE);

        AuditLog::record('users.activated', AuditLog::ACTOR_APP, (int) $user['id'], (int) $app['id'], 'user', (int) $user['id'], [], $context->ip, $context->userAgent);

        $fresh = User::find((int) $user['id']) ?? $user;
        return Response::json(['user' => \sso_app()->tokens()->userPayload($fresh, $app)]);
    }

    /**
     * POST /v1/users/{id}/password-reset — صدور توکن بازیابی
     */
    public function resetPassword(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        ['user' => $user] = $this->userForApp((string) $params['id'], $context);

        /** @var \Sso\Services\PasswordBroker $broker */
        $broker = \sso_app()->passwords();
        $reset = $broker->createToken($user, $app);

        return Response::json([
            'token' => $reset['token'],
            'expires_at' => $reset['expires_at'],
            'expires_in' => $reset['expires_in'],
            'user_id' => (int) $user['id'],
        ]);
    }

    /**
     * POST /v1/users/{id}/password — تنظیم مستقیم رمز توسط اپ (ادمینِ اپ)
     */
    public function setPassword(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        ['user' => $user] = $this->userForApp((string) $params['id'], $context);

        $min = (int) \sso_config('security.password_min_length', 8);
        $data = $this->validated($request, ['password' => 'required|string|min:' . $min . '|max:128']);

        \sso_app()->users()->setPassword((int) $user['id'], (string) $data['password']);

        AuditLog::record('users.password_set', AuditLog::ACTOR_APP, (int) $user['id'], (int) $app['id'], 'user', (int) $user['id'], [], $context->ip, $context->userAgent);

        return Response::json(['updated' => true, 'user_id' => (int) $user['id']]);
    }

    /**
     * POST /v1/users/{id}/verify-email
     */
    public function verifyEmail(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        ['user' => $user] = $this->userForApp((string) $params['id'], $context);

        User::update((int) $user['id'], [
            'email_verified_at' => Clock::now(),
            'status' => (string) $user['status'] === User::STATUS_PENDING ? User::STATUS_ACTIVE : (string) $user['status'],
        ]);

        $fresh = User::find((int) $user['id']) ?? $user;
        return Response::json(['verified' => true, 'user' => \sso_app()->tokens()->userPayload($fresh, $app)]);
    }
}
