<?php

declare(strict_types=1);

/**
 * اجرای یک درخواست API در حالت تست (بدون وب‌سرور).
 *
 * ورودی: $GLOBALS['__TEST_REQUEST'] (آرایه)
 * خروجی: یک بلوک JSON بعد از نشانگر SSO_TEST_RESULT
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Api\Kernel;
use Sso\Core\App;
use Sso\Http\Request;
use Sso\Http\Response;

// در حالت تست خطاها باید دیده شوند، نه اینکه پشت یک صفحه‌ی ۵۰۰ پنهان شوند.
// (باید بعد از bootstrap تنظیم شود چون bootstrap هندلر خودش را ثبت می‌کند)
set_exception_handler(static function (Throwable $e): void {
    echo "\n=====SSO_TEST_RESULT=====\n";
    echo (string) json_encode([
        'status' => 500,
        'headers' => [],
        'body' => json_encode([
            'ok' => false,
            'error' => [
                'code' => 'unhandled_' . $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ],
        ], JSON_UNESCAPED_UNICODE),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

$spec = $GLOBALS['__TEST_REQUEST'] ?? [];

App::boot();
Response::capture(true);

$path = (string) ($spec['path'] ?? '/v1/health');
$query = (array) ($spec['query'] ?? []);

$request = Request::create(
    (string) ($spec['method'] ?? 'GET'),
    '/api' . $path . ($query === [] ? '' : '?' . http_build_query($query)),
    [
        'query' => $query,
        'form' => (array) ($spec['form'] ?? []),
        'json' => $spec['json'] ?? null,
        'body' => (string) ($spec['body'] ?? ''),
        'headers' => (array) ($spec['headers'] ?? []),
        'cookies' => (array) ($spec['cookies'] ?? []),
        'ip' => (string) ($spec['ip'] ?? '127.0.0.1'),
        'host' => (string) ($spec['host'] ?? 'localhost'),
        'https' => (bool) ($spec['https'] ?? false),
        'script_name' => '/api/index.php',
    ]
);

/** @var \Sso\Api\Router $router */
$router = require SSO_SRC . '/Api/routes.php';
$kernel = new Kernel($router);

$status = 200;
try {
    $response = $kernel->handle($request);
    $status = $response->statusCode();
} catch (Throwable $e) {
    $response = Response::apiError('test_exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 500);
    $status = 500;
}

ob_start();
$response->send();
$body = (string) ob_get_clean();

echo "\n=====SSO_TEST_RESULT=====\n";
echo (string) json_encode([
    'status' => $status,
    'headers' => Response::capturedHeaders(),
    'body' => $body,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
