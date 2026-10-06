<?php

declare(strict_types=1);

/**
 * خروج از پنل مدیریت.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Models\AuditLog;
use Sso\Admin\Page;

Page::run(static function (): void {

    Guard::boot();

    $admin = Guard::user();
    if ($admin !== null) {
        AuditLog::record(
            'admin.logout',
            AuditLog::ACTOR_ADMIN,
            (int) $admin['id'],
            null,
            'user',
            (int) $admin['id'],
            [],
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            \Sso\Support\Str::userAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))
        );
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!\Sso\Support\Csrf::verify($_POST['_token'] ?? null)) {
            Guard::redirectTo('/admin/index.php');
        }
    }

    Guard::logout();
    Guard::redirectTo('/admin/login.php');
});
