<?php

declare(strict_types=1);

/**
 * نقطه‌ی ورود API نسخه ۱.
 *
 * روی IIS با ماژول URL Rewrite مسیر /api/v1/* به این فایل می‌رسد.
 * اگر ماژول Rewrite در دسترس نباشد، می‌توان از
 *   /api/index.php?_route=/v1/users
 * استفاده کرد (به صورت خودکار پشتیبانی می‌شود).
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Api\Kernel;
use Sso\Core\App;
use Sso\Http\Request;

App::boot();

/** @var \Sso\Api\Router $router */
$router = require SSO_SRC . '/Api/routes.php';

$kernel = new Kernel($router);
$kernel->handle(Request::fromGlobals())->send();
