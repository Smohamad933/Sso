<?php

declare(strict_types=1);

/**
 * فهرست اپلیکیشن‌ها + ساخت اپلیکیشن جدید.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Admin\Layout;
use Sso\Models\Application;
use Sso\Models\AuditLog;
use Sso\Models\Membership;
use Sso\Support\Str;
use Sso\Admin\Page;

Page::run(static function (): void {

    Guard::boot();
    $admin = Guard::requireAuth();

    $errors = [];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'create') {
        if (!Guard::verifyCsrf($_POST['_token'] ?? null)) {
            Guard::redirectTo('/admin/apps.php');
        }

        $name = Str::trim((string) ($_POST['name'] ?? ''));
        $slug = Str::trim((string) ($_POST['slug'] ?? ''));
        $description = Str::trim((string) ($_POST['description'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'نام اپلیکیشن الزامی است.';
        }
        if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $errors['slug'] = 'شناسه فقط می‌تواند شامل حروف کوچک انگلیسی، عدد و خط تیره باشد.';
        }

        if ($errors === []) {
            $result = \sso_app()->apps()->create($name, $slug === '' ? null : $slug, $description === '' ? null : $description);

            AuditLog::record(
                'apps.created',
                AuditLog::ACTOR_ADMIN,
                (int) $admin['id'],
                (int) $result['app']['id'],
                'app',
                (int) $result['app']['id'],
                ['slug' => (string) $result['app']['slug']],
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                Str::userAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))
            );

            \Sso\Http\Session::flash('success', 'اپلیکیشن ساخته شد. کلیدها را همین حالا کپی کنید:');
            \Sso\Http\Session::flash('secret_api_key', $result['api_key']);
            \Sso\Http\Session::flash('secret_api_secret', $result['api_secret']);
            Guard::redirectTo('/admin/app.php?id=' . (int) $result['app']['id']);
        }
    }

    $apps = Application::all();

    Layout::begin('اپلیکیشن‌ها', 'apps', $admin);

    $secretKey = \Sso\Http\Session::getFlash('secret_api_key');
    $secretSecret = \Sso\Http\Session::getFlash('secret_api_secret');
    if (is_string($secretKey) && $secretKey !== ''): ?>
        <div class="flash flash-warning">
            <strong>کلیدها فقط یک‌بار نمایش داده می‌شوند!</strong>
            <div class="secret-box" id="newApiKey"><?= e($secretKey) ?></div>
            <div class="secret-box" id="newApiSecret"><?= e((string) $secretSecret) ?></div>
            <button type="button" class="btn btn-ghost btn-sm" data-copy="#newApiSecret">کپی سکرت</button>
        </div>
    <?php endif; ?>

    <section class="card">
        <header class="card-head"><h2>ساخت اپلیکیشن جدید</h2></header>
        <div class="card-body">
            <?php Layout::errorBox($errors); ?>
            <form method="post" action="<?= e(\sso_url('/admin/apps.php')) ?>">
                <?= \Sso\Support\Csrf::field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-row">
                    <div class="field">
                        <label for="name">نام اپلیکیشن</label>
                        <input type="text" id="name" name="name" required maxlength="120" placeholder="فروشگاه من">
                    </div>
                    <div class="field">
                        <label for="slug">شناسه (اختیاری)</label>
                        <input type="text" id="slug" name="slug" maxlength="120" placeholder="my-shop">
                        <div class="hint">اگر خالی بگذارید از روی نام ساخته می‌شود.</div>
                    </div>
                </div>
                <div class="field">
                    <label for="description">توضیحات (اختیاری)</label>
                    <input type="text" id="description" name="description" maxlength="255">
                </div>
                <button type="submit" class="btn">ساخت اپلیکیشن</button>
            </form>
        </div>
    </section>

    <section class="card">
        <header class="card-head"><h2>همه‌ی اپلیکیشن‌ها</h2></header>
        <div class="card-body" style="padding:0">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>نام</th>
                            <th>شناسه</th>
                            <th>کلید</th>
                            <th>وضعیت</th>
                            <th class="num">اعضا</th>
                            <th class="num">ساخته‌شده</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($apps === []): ?>
                            <tr><td colspan="7" class="empty-row">هنوز اپلیکیشنی وجود ندارد.</td></tr>
                        <?php else: ?>
                            <?php foreach ($apps as $app): ?>
                                <tr>
                                    <td><a href="<?= e(\sso_url('/admin/app.php?id=' . (int) $app['id'])) ?>"><?= e((string) $app['name']) ?></a></td>
                                    <td><span class="code"><?= e((string) $app['slug']) ?></span></td>
                                    <td><span class="code"><?= e((string) $app['api_key_prefix']) ?>…</span></td>
                                    <td><?= Layout::statusBadge((string) $app['status']) ?></td>
                                    <td class="num"><?= e((string) Membership::countForApp((int) $app['id'])) ?></td>
                                    <td class="num small muted"><?= e(Layout::date($app['created_at'] ?? null)) ?></td>
                                    <td><a class="btn btn-ghost btn-sm" href="<?= e(\sso_url('/admin/app.php?id=' . (int) $app['id'])) ?>">جزئیات</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php

    Layout::end();
});
