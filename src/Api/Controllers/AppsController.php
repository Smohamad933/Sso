<?php

declare(strict_types=1);

namespace Sso\Api\Controllers;

use Sso\Api\Context;
use Sso\Http\Request;
use Sso\Http\Response;
use Sso\Models\Application;
use Sso\Models\AuditLog;

/**
 * اطلاعات اپلیکیشنِ صاحبِ کلید API.
 */
final class AppsController extends BaseController
{
    /**
     * GET /v1/apps/me
     */
    public function show(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        return Response::json(['app' => Application::publicArray($app)]);
    }

    /**
     * PATCH /v1/apps/me — تغییر نام/توضیحات/تنظیمات اپ
     */
    public function update(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();

        $data = $this->validated($request, [
            'name' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:255',
            'webhook_url' => 'nullable|url|max:500',
        ]);

        $payload = [];
        foreach (['name', 'description', 'webhook_url'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        $rawSettings = $request->input('settings');
        if (is_array($rawSettings)) {
            $allowed = ['allow_registration', 'require_email_verification', 'default_role',
                'access_token_ttl', 'refresh_token_ttl', 'allowed_origins', 'metadata_fields'];
            $settings = array_intersect_key($rawSettings, array_flip($allowed));
            \sso_app()->apps()->updateSettings((int) $app['id'], $settings);
        }

        if ($payload !== []) {
            Application::update((int) $app['id'], $payload);
        }

        AuditLog::record(
            'apps.updated',
            AuditLog::ACTOR_APP,
            null,
            (int) $app['id'],
            'app',
            (int) $app['id'],
            ['fields' => array_keys($payload)],
            $context->ip,
            $context->userAgent
        );

        $fresh = Application::find((int) $app['id']) ?? $app;
        return Response::json(['app' => Application::publicArray($fresh)]);
    }

    /**
     * GET /v1/apps/me/stats
     */
    public function stats(Request $request, array $params, Context $context): Response
    {
        $app = $context->requireApp();
        $db = \sso_db();

        return Response::json([
            'app' => Application::publicArray($app, false),
            'users' => (int) \Sso\Models\Membership::countForApp((int) $app['id']),
            'active_tokens' => $db->count(
                'SELECT COUNT(*) FROM `api_tokens` WHERE `app_id` = ? AND `revoked_at` IS NULL AND `expires_at` > ?',
                [(int) $app['id'], \Sso\Support\Clock::now()]
            ),
        ]);
    }
}
