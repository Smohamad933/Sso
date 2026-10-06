<?php

declare(strict_types=1);

/**
 * نصب پروژه در محیط تست (بدون exit تا runtime تست سالم بماند).
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Core\App;
use Sso\Install\Installer;

$spec = $GLOBALS['__TEST_REQUEST'] ?? [];

$db = [
    'driver' => 'sqlite',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'sso',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'path' => SSO_STORAGE . '/database/test.sqlite',
];

if (is_file(SSO_CONFIG_FILE)) {
    @unlink(SSO_CONFIG_FILE);
}
if (is_file(SSO_INSTALL_LOCK)) {
    @unlink(SSO_INSTALL_LOCK);
}
$sqliteFile = SSO_STORAGE . '/database/test.sqlite';
if (is_file($sqliteFile)) {
    @unlink($sqliteFile);
}

try {
    $baseUrl = (string) ($spec['base_url'] ?? 'http://localhost');
    Installer::writeConfig($db, 'base64:' . base64_encode(random_bytes(32)), 'SSO Test', $baseUrl);
    App::boot();
    Installer::runSchema(\sso_db());

    Installer::createSuperAdmin(
        (string) ($spec['admin_email'] ?? 'admin@example.com'),
        (string) ($spec['admin_password'] ?? 'AdminPass123'),
        'مدیر تست'
    );

    $demo = Installer::createDemoApp('اپلیکیشن نمونه');
    Installer::lock();

    echo "\n=====SSO_TEST_RESULT=====\n";
    echo (string) json_encode([
        'ok' => true,
        'api_key' => $demo['api_key'],
        'api_secret' => $demo['api_secret'],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo "\n=====SSO_TEST_RESULT=====\n";
    echo (string) json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString()),
    ], JSON_UNESCAPED_UNICODE);
}
