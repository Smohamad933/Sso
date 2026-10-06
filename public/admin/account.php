<?php

declare(strict_types=1);

/**
 * حساب کاربری مدیر: تغییر رمز عبور و مشاهده اطلاعات نشست.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Admin\Layout;
use Sso\Api\ApiException;
use Sso\Models\AuditLog;
use Sso\Models\User;
use Sso\Support\Str;
use Sso\Admin\Page;

Page::run(static function (): void {

    Guard::boot();
    $admin = Guard::requireAuth();

    $errors = [];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!Guard::verifyCsrf($_POST['_token'] ?? null)) {
            Guard::redirectTo('/admin/account.php');
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirmation'] ?? '');

        if ($new !== $confirm) {
            $errors['new_password_confirmation'] = 'تکرار رمز عبور یکسان نیست.';
        }

        if ($errors === []) {
            try {
                \sso_app()->auth()->changePassword($admin, $current, $new);
                AuditLog::record(
                    'admin.password_changed',
                    AuditLog::ACTOR_ADMIN,
                    (int) $admin['id'],
                    null,
                    'user',
                    (int) $admin['id'],
                    [],
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    Str::userAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))
                );
                \Sso\Http\Session::flash('success', 'رمز عبور با موفقیت تغییر کرد. لطفاً دوباره وارد شوید.');
                Guard::logout();
                Guard::redirectTo('/admin/login.php');
            } catch (ApiException $e) {
                $errors = array_merge($errors, (array) ($e->fields ?? []));
                if ($errors === []) {
                    $errors['current_password'] = $e->getMessage();
                }
            }
        }
    }

    Layout::begin('حساب من', '', $admin);

    ?>
    <div class="grid grid-2">
        <section class="card">
            <header class="card-head"><h2>تغییر رمز عبور</h2></header>
            <div class="card-body">
                <?php Layout::errorBox($errors); ?>
                <form method="post" action="<?= e(\sso_url('/admin/account.php')) ?>">
                    <?= \Sso\Support\Csrf::field() ?>
                    <div class="field">
                        <label for="current_password">رمز عبور فعلی</label>
                        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="field">
                        <label for="new_password">رمز عبور جدید</label>
                        <input type="password" id="new_password" name="new_password" autocomplete="new-password" required minlength="<?= e((string) \sso_config('security.password_min_length', 8)) ?>" maxlength="128">
                        <div class="hint">حداقل <?= e((string) \sso_config('security.password_min_length', 8)) ?> کاراکتر.</div>
                    </div>
                    <div class="field">
                        <label for="new_password_confirmation">تکرار رمز عبور جدید</label>
                        <input type="password" id="new_password_confirmation" name="new_password_confirmation" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn">تغییر رمز عبور</button>
                </form>
            </div>
        </section>

        <section class="card">
            <header class="card-head"><h2>اطلاعات حساب</h2></header>
            <div class="card-body">
                <dl class="kv">
                    <dt>ایمیل</dt><dd><?= e((string) $admin['email']) ?></dd>
                    <dt>نام</dt><dd><?= e((string) ($admin['full_name'] ?? '—')) ?></dd>
                    <dt>شناسه</dt><dd><span class="code"><?= e((string) $admin['id']) ?></span></dd>
                    <dt>UUID</dt><dd><span class="code"><?= e((string) $admin['uuid']) ?></span></dd>
                    <dt>آخرین ورود</dt><dd><?= e(Layout::human($admin['last_login_at'] ?? null)) ?></dd>
                    <dt>تغییر رمز</dt><dd><?= e(Layout::date($admin['password_changed_at'] ?? null)) ?></dd>
                    <dt>نقش</dt><dd><?= Layout::badge('ادمین کل', 'purple') ?></dd>
                </dl>
            </div>
        </section>
    </div>

    <section class="card">
        <header class="card-head"><h2>اطلاعات محیط</h2></header>
        <div class="card-body">
            <dl class="kv">
                <dt>نسخه PHP</dt><dd><span class="code"><?= e(PHP_VERSION) ?></span></dd>
                <dt>نوع دیتابیس</dt><dd><span class="code"><?= e(\sso_db()->driver()) ?></span></dd>
                <dt>SAPI</dt><dd><span class="code"><?= e(PHP_SAPI) ?></span></dd>
                <dt>الگوریتم رمز عبور</dt><dd><span class="code"><?= e(\Sso\Services\TokenService::passwordAlgorithm() === PASSWORD_ARGON2ID ? 'argon2id' : 'bcrypt') ?></span></dd>
                <dt>عمر توکن دسترسی</dt><dd><span class="code"><?= e((string) \sso_config('security.access_token_ttl', 3600)) ?></span> ثانیه</dd>
                <dt>عمر توکن تمدید</dt><dd><span class="code"><?= e((string) \sso_config('security.refresh_token_ttl', 2592000)) ?></span> ثانیه</dd>
                <dt>محدودیت نرخ API</dt><dd><span class="code"><?= e((string) \sso_config('security.api_rate_limit_per_minute', 600)) ?></span> درخواست/دقیقه</dd>
            </dl>
        </div>
    </section>
    <?php

    Layout::end();
});
