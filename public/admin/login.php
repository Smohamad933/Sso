<?php

declare(strict_types=1);

/**
 * ورود به پنل مدیریت (فقط کاربران ادمین کل).
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Api\ApiException;
use Sso\Http\HttpException;
use Sso\Support\Csrf;
use Sso\Support\Str;
use Sso\Admin\Page;

Page::run(static function (): void {

Guard::boot();

if (Guard::user() !== null) {
    Guard::redirectTo('/admin/index.php');
}

$errors = [];
$email = '';
$redirect = isset($_GET['redirect']) && is_string($_GET['redirect']) ? trim($_GET['redirect']) : '';
if ($redirect === '' || !str_starts_with($redirect, '/')) {
    $redirect = '/admin/index.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::verify($_POST['_token'] ?? null)) {
        $errors['_token'] = 'توکن امنیتی نامعتبر است. صفحه را دوباره بارگذاری کنید.';
    }

    $email = Str::normalizeEmail((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || !Str::isEmail($email)) {
        $errors['email'] = 'ایمیل معتبر وارد کنید.';
    }
    if ($password === '') {
        $errors['password'] = 'رمز عبور را وارد کنید.';
    }

    if ($errors === []) {
        try {
            $user = \sso_app()->auth()->loginAdmin(
                $email,
                $password,
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                Str::userAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))
            );
            Guard::login($user);
            \Sso\Http\Session::flash('success', 'خوش آمدید.');
            Guard::redirectTo($redirect);
        } catch (ApiException $e) {
            $errors['email'] = $e->getMessage();
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Sso\Support\Log::error('admin login: ' . $e->getMessage());
            $errors['email'] = 'خطایی رخ داد. دوباره تلاش کنید.';
        }
    }
}

$appName = (string) \sso_config('app_name', 'سامانه احراز هویت');
$assetBase = \sso_url('/assets');
$cssVersion = @filemtime(SSO_PUBLIC . '/assets/app.css') ?: 0;

?><!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ورود مدیر — <?= e($appName) ?></title>
    <link rel="stylesheet" href="<?= e($assetBase . '/app.css?v=' . $cssVersion) ?>">
</head>
<body class="login-page">
    <div class="login-card">
        <h1>ورود به پنل مدیریت</h1>
        <p class="sub"><?= e($appName) ?></p>

        <?php if ($errors !== []): ?>
            <div class="flash flash-error">
                <ul class="tight">
                    <?php foreach ($errors as $message): ?>
                        <li><?= e((string) $message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(\sso_url('/admin/login.php?redirect=' . rawurlencode($redirect))) ?>">
            <?= Csrf::field() ?>
            <div class="field">
                <label for="email">ایمیل مدیر</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="field">
                <label for="password">رمز عبور</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn">ورود</button>
        </form>

        <p class="small muted" style="margin-top:22px;text-align:center">
            فقط کاربر دارای نقش «ادمین کل» می‌تواند وارد شود.
        </p>
    </div>
</body>
</html>
<?php });
