<?php

declare(strict_types=1);

/**
 * تعریف تمام مسیرهای API نسخه ۱.
 * این فایل هم از public/api/index.php و هم از هارنس تست استفاده می‌شود.
 */

use Sso\Api\Controllers\AppsController;
use Sso\Api\Controllers\AuthController;
use Sso\Api\Controllers\HealthController;
use Sso\Api\Controllers\MeController;
use Sso\Api\Controllers\UsersController;
use Sso\Api\Router;

$router = new Router();

// ------------------------------------------------------------------ عمومی
$router->get('/v1/health', [new HealthController(), 'index'], ['auth' => 'public']);

// ------------------------------------------------------------------ احراز هویت
$auth = new AuthController();
$router->post('/v1/auth/register', [$auth, 'register'], ['auth' => 'app']);
$router->post('/v1/auth/login', [$auth, 'login'], ['auth' => 'app']);
$router->post('/v1/auth/refresh', [$auth, 'refresh'], ['auth' => 'optional_app']);
$router->post('/v1/auth/logout', [$auth, 'logout'], ['auth' => 'user']);
$router->post('/v1/auth/introspect', [$auth, 'introspect'], ['auth' => 'app_or_user']);
$router->post('/v1/auth/password/forgot', [$auth, 'forgotPassword'], ['auth' => 'app']);
$router->post('/v1/auth/password/reset', [$auth, 'resetPassword'], ['auth' => 'app']);
$router->post('/v1/auth/verify-email', [$auth, 'verifyEmail'], ['auth' => 'app']);

// ------------------------------------------------------------------ کاربرانِ اپ
$users = new UsersController();
$router->get('/v1/users', [$users, 'index'], ['auth' => 'app']);
$router->post('/v1/users', [$users, 'store'], ['auth' => 'app']);
$router->get('/v1/users/{id}', [$users, 'show'], ['auth' => 'app']);
$router->patch('/v1/users/{id}', [$users, 'update'], ['auth' => 'app']);
$router->delete('/v1/users/{id}', [$users, 'destroy'], ['auth' => 'app']);
$router->post('/v1/users/{id}/role', [$users, 'role'], ['auth' => 'app']);
$router->post('/v1/users/{id}/suspend', [$users, 'suspend'], ['auth' => 'app']);
$router->post('/v1/users/{id}/activate', [$users, 'activate'], ['auth' => 'app']);
$router->post('/v1/users/{id}/password', [$users, 'setPassword'], ['auth' => 'app']);
$router->post('/v1/users/{id}/password-reset', [$users, 'resetPassword'], ['auth' => 'app']);
$router->post('/v1/users/{id}/verify-email', [$users, 'verifyEmail'], ['auth' => 'app']);

// ------------------------------------------------------------------ کاربر جاری
$me = new MeController();
$router->get('/v1/me', [$me, 'show'], ['auth' => 'user']);
$router->patch('/v1/me', [$me, 'update'], ['auth' => 'user']);
$router->post('/v1/me/password', [$me, 'password'], ['auth' => 'user']);
$router->get('/v1/me/apps', [$me, 'apps'], ['auth' => 'user']);
$router->post('/v1/me/logout', [$me, 'logoutAll'], ['auth' => 'user']);

// ------------------------------------------------------------------ اپلیکیشن جاری
$apps = new AppsController();
$router->get('/v1/apps/me', [$apps, 'show'], ['auth' => 'app']);
$router->patch('/v1/apps/me', [$apps, 'update'], ['auth' => 'app']);
$router->get('/v1/apps/me/stats', [$apps, 'stats'], ['auth' => 'app']);

return $router;
