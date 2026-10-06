<?php

declare(strict_types=1);

namespace Sso\Api\Controllers;

use Sso\Api\Context;
use Sso\Http\Request;
use Sso\Http\Response;
use Sso\Models\AuditLog;
use Sso\Models\Membership;
use Sso\Models\Token;
use Sso\Models\User;

/**
 * عملیات مربوط به «کاربرِ جاری» (با توکن دسترسی).
 */
final class MeController extends BaseController
{
    /**
     * GET /v1/me
     */
    public function show(Request $request, array $params, Context $context): Response
    {
        $user = $context->requireUser();
        $token = $context->requireToken();

        $app = $token['app_id'] !== null ? \Sso\Models\Application::find((int) $token['app_id']) : null;

        return Response::json([
            'user' => \sso_app()->tokens()->userPayload($user, $app),
            'token' => [
                'scopes' => Token::scopes($token),
                'expires_at' => $token['expires_at'],
                'app_id' => $token['app_id'] === null ? null : (int) $token['app_id'],
            ],
        ]);
    }

    /**
     * PATCH /v1/me
     */
    public function update(Request $request, array $params, Context $context): Response
    {
        $user = $context->requireUser();

        $data = $this->validated($request, [
            'full_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:32',
            'avatar_url' => 'nullable|url|max:500',
            'metadata' => 'nullable|array',
        ]);

        if ($data !== []) {
            User::update((int) $user['id'], $data);
        }

        $fresh = User::find((int) $user['id']) ?? $user;
        $token = $context->requireToken();
        $app = $token['app_id'] !== null ? \Sso\Models\Application::find((int) $token['app_id']) : null;

        return Response::json(['user' => \sso_app()->tokens()->userPayload($fresh, $app)]);
    }

    /**
     * POST /v1/me/password
     */
    public function password(Request $request, array $params, Context $context): Response
    {
        $user = $context->requireUser();
        $min = (int) \sso_config('security.password_min_length', 8);

        $data = $this->validated($request, [
            'current_password' => 'required|string|max:128',
            'new_password' => 'required|string|min:' . $min . '|max:128',
        ]);

        \sso_app()->auth()->changePassword(
            $user,
            (string) $data['current_password'],
            (string) $data['new_password']
        );

        return Response::json(['updated' => true, 'message' => 'رمز عبور تغییر کرد. توکن‌های قبلی باطل شدند.']);
    }

    /**
     * GET /v1/me/apps
     */
    public function apps(Request $request, array $params, Context $context): Response
    {
        $user = $context->requireUser();
        return Response::json(['apps' => Membership::listForUser((int) $user['id'])]);
    }

    /**
     * POST /v1/me/logout — خروج از همه‌ی نشست‌ها
     */
    public function logoutAll(Request $request, array $params, Context $context): Response
    {
        $user = $context->requireUser();
        $revoked = Token::revokeAllForUser((int) $user['id']);

        AuditLog::record(
            'auth.logout_all',
            AuditLog::ACTOR_USER,
            (int) $user['id'],
            null,
            'user',
            (int) $user['id'],
            ['revoked' => $revoked],
            $context->ip,
            $context->userAgent
        );

        return Response::json(['revoked' => $revoked]);
    }
}
