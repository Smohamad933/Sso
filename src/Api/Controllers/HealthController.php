<?php

declare(strict_types=1);

namespace Sso\Api\Controllers;

use Sso\Api\Context;
use Sso\Core\App;
use Sso\Http\Request;
use Sso\Http\Response;
use Sso\Support\Clock;

final class HealthController
{
    public function index(Request $request, array $params, Context $context): Response
    {
        $dbOk = true;
        try {
            \sso_db()->value('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        $payload = [
            'status' => $dbOk ? 'ok' : 'degraded',
            'time' => Clock::now(),
            'database' => [
                'driver' => \sso_db()->driver(),
                'connected' => $dbOk,
            ],
            'php' => PHP_VERSION,
            'api_version' => 'v1',
        ];

        if (\sso_config('debug', false)) {
            $payload['routes'] = \sso_api_routes();
        }

        return Response::json($payload, $dbOk ? 200 : 503);
    }
}
