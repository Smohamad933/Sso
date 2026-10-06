<?php

declare(strict_types=1);

/**
 * اجرای یک صفحه‌ی پنل مدیریت در حالت تست (بدون وب‌سرور).
 *
 * ورودی: $GLOBALS['__TEST_REQUEST'] (آرایه)
 * خروجی: یک بلوک JSON بعد از نشانگر SSO_TEST_RESULT
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Http\Response;

$spec = $GLOBALS['__TEST_REQUEST'] ?? [];

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// در محیط تست کوکی وجود ندارد؛ نشست با session_id صریح مدیریت می‌شود
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_trans_sid', '0');

Response::capture(true);

$_SERVER = array_merge([
    'REQUEST_METHOD' => (string) ($spec['method'] ?? 'GET'),
    'REQUEST_URI' => (string) ($spec['uri'] ?? '/'),
    'SCRIPT_NAME' => (string) ($spec['script_name'] ?? '/admin/index.php'),
    'HTTP_HOST' => (string) ($spec['host'] ?? 'localhost'),
    'SERVER_PORT' => (string) ($spec['port'] ?? 80),
    'HTTPS' => !empty($spec['https']) ? 'on' : 'off',
    'REMOTE_ADDR' => (string) ($spec['ip'] ?? '127.0.0.1'),
    'HTTP_USER_AGENT' => (string) ($spec['user_agent'] ?? 'SSO-Test-Harness'),
], (array) ($spec['server'] ?? []));

$_GET = (array) ($spec['query'] ?? []);
$_POST = (array) ($spec['form'] ?? []);
$_COOKIE = (array) ($spec['cookies'] ?? []);
foreach ((array) ($spec['headers'] ?? []) as $name => $value) {
    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string) $name))] = (string) $value;
}

$sessionId = (string) ($spec['session_id'] ?? '');
if ($sessionId !== '') {
    session_name('SSOSESSID');
    session_id($sessionId);
}

$captured = '';

ob_start(static function (string $buffer) use (&$captured): string {
    $captured .= $buffer;
    return '';
});

$file = (string) ($spec['file'] ?? '/admin/index.php');
$real = SSO_PUBLIC . $file;

if (is_file($real)) {
    chdir(dirname($real));
    require $real;
} else {
    $captured .= 'فایل یافت نشد: ' . $file;
}

while (ob_get_level() > 0) {
    $captured .= (string) ob_get_contents();
    @ob_end_clean();
}

$activeSessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

echo "\n=====SSO_TEST_RESULT=====\n";
echo (string) json_encode([
    'status' => 200,
    'headers' => Response::capturedHeaders(),
    'body' => $captured,
    'session_id' => $activeSessionId,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
