<?php

declare(strict_types=1);

/**
 * جزئیات یک اپلیکیشن: ویرایش، کلیدها، تنظیمات، حذف.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Admin\Layout;
use Sso\Http\HttpException;
use Sso\Models\Application;
use Sso\Models\AuditLog;
use Sso\Models\Membership;
use Sso\Models\Roles;
use Sso\Models\User;
use Sso\Support\Str;
use Sso\Admin\Page;

Page::run(static function (): void {

    Guard::boot();
    $admin = Guard::requireAuth();

    $id = (int) ($_GET['id'] ?? 0);
    $app = Application::find($id);
    if ($app === null) {
        \Sso\Http\Session::flash('error', 'اپلیکیشن یافت نشد.');
        Guard::redirectTo('/admin/apps.php');
    }

    $errors = [];
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = Str::userAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!Guard::verifyCsrf($_POST['_token'] ?? null)) {
            Guard::redirectTo('/admin/app.php?id=' . $id);
        }
        $action = (string) ($_POST['action'] ?? '');

        try {
            switch ($action) {
                case 'update':
                    $name = Str::trim((string) ($_POST['name'] ?? ''));
                    if ($name === '') {
                        $errors['name'] = 'نام الزامی است.';
                        break;
                    }
                    Application::update($id, [
                        'name' => $name,
                        'description' => Str::trim((string) ($_POST['description'] ?? '')) ?: null,
                        'status' => in_array((string) ($_POST['status'] ?? ''), Application::statuses(), true)
                            ? (string) $_POST['status'] : (string) $app['status'],
                        'webhook_url' => Str::trim((string) ($_POST['webhook_url'] ?? '')) ?: null,
                    ]);
                    \Sso\Http\Session::flash('success', 'اطلاعات اپلیکیشن به‌روزرسانی شد.');
                    break;

                case 'settings':
                    $origins = array_values(array_filter(array_map(
                        static fn(string $line): string => trim($line),
                        preg_split('/\R/u', (string) ($_POST['allowed_origins'] ?? '')) ?: []
                    ), static fn(string $line): bool => $line !== ''));

                    $settings = [
                        'allow_registration' => isset($_POST['allow_registration']),
                        'require_email_verification' => isset($_POST['require_email_verification']),
                        'default_role' => in_array((string) ($_POST['default_role'] ?? ''), Roles::all(), true)
                            ? (string) $_POST['default_role'] : Roles::MEMBER,
                        'access_token_ttl' => ($_POST['access_token_ttl'] ?? '') === ''
                            ? null : max(60, (int) $_POST['access_token_ttl']),
                        'refresh_token_ttl' => ($_POST['refresh_token_ttl'] ?? '') === ''
                            ? null : max(300, (int) $_POST['refresh_token_ttl']),
                        'allowed_origins' => $origins,
                    ];
                    \sso_app()->apps()->updateSettings($id, $settings);
                    \Sso\Http\Session::flash('success', 'تنظیمات ذخیره شد.');
                    break;

                case 'regenerate_keys':
                    $keys = \sso_app()->apps()->regenerateKeys($id);
                    // تمام توکن‌های صادرشده برای این اپ باطل می‌شوند
                    \sso_db()->update('api_tokens', ['revoked_at' => \Sso\Support\Clock::now()], '`app_id` = ? AND `revoked_at` IS NULL', [$id]);
                    \Sso\Http\Session::flash('warning', 'کلیدها و سکرت جدید صادر شد. کلیدهای قبلی بی‌اعتبار شدند و همه‌ی توکن‌های این اپ باطل شد.');
                    \Sso\Http\Session::flash('secret_api_key', $keys['api_key']);
                    \Sso\Http\Session::flash('secret_api_secret', $keys['api_secret']);
                    break;

                case 'rotate_secret':
                    $secret = \sso_app()->apps()->rotateSecret($id);
                    \Sso\Http\Session::flash('warning', 'سکرت جدید صادر شد.');
                    \Sso\Http\Session::flash('secret_api_secret', $secret);
                    break;

                case 'delete':
                    Application::delete($id);
                    AuditLog::record('apps.deleted', AuditLog::ACTOR_ADMIN, (int) $admin['id'], null, 'app', $id, ['slug' => (string) $app['slug']], $ip, $ua);
                    \Sso\Http\Session::flash('success', 'اپلیکیشن حذف شد.');
                    Guard::redirectTo('/admin/apps.php');
            }

            if ($action !== 'delete') {
                AuditLog::record('apps.' . $action, AuditLog::ACTOR_ADMIN, (int) $admin['id'], $id, 'app', $id, [], $ip, $ua);
            }
        } catch (\Sso\Http\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Sso\Support\Log::error('admin app: ' . $e->getMessage());
            $errors['general'] = 'خطا: ' . $e->getMessage();
        }

        Guard::redirectTo('/admin/app.php?id=' . $id);
    }

    $app = Application::find($id) ?? $app;
    $settings = Application::settings($app);
    $members = Membership::listForApp($id, 10, 0);
    $memberCount = Membership::countForApp($id);

    Layout::begin('اپلیکیشن: ' . (string) $app['name'], 'apps', $admin);

    $newKey = \Sso\Http\Session::getFlash('secret_api_key');
    $newSecret = \Sso\Http\Session::getFlash('secret_api_secret');
    if (is_string($newKey) && $newKey !== ''): ?>
        <div class="flash flash-warning">
            <strong>حتماً کپی کنید — این مقادیر دوباره نمایش داده نخواهند شد.</strong>
            <div class="secret-box" id="genKey"><?= e($newKey) ?></div>
            <div class="secret-box" id="genSecret"><?= e((string) $newSecret) ?></div>
            <button type="button" class="btn btn-ghost btn-sm" data-copy="#genKey">کپی کلید</button>
            <button type="button" class="btn btn-ghost btn-sm" data-copy="#genSecret">کپی سکرت</button>
        </div>
    <?php endif;

    Layout::errorBox($errors);

    ?>
    <p><a href="<?= e(\sso_url('/admin/apps.php')) ?>">‹ بازگشت به اپلیکیشن‌ها</a></p>

    <div class="grid grid-2">
        <section class="card">
            <header class="card-head"><h2>ویرایش اطلاعات</h2></header>
            <div class="card-body">
                <form method="post" action="<?= e(\sso_url('/admin/app.php?id=' . $id)) ?>">
                    <?= \Sso\Support\Csrf::field() ?>
                    <input type="hidden" name="action" value="update">
                    <div class="field">
                        <label for="name">نام</label>
                        <input type="text" id="name" name="name" value="<?= e((string) $app['name']) ?>" required maxlength="120">
                    </div>
                    <div class="field">
                        <label for="description">توضیحات</label>
                        <input type="text" id="description" name="description" value="<?= e((string) ($app['description'] ?? '')) ?>" maxlength="255">
                    </div>
                    <div class="field">
                        <label for="webhook_url">آدرس Webhook (اختیاری)</label>
                        <input type="url" id="webhook_url" name="webhook_url" value="<?= e((string) ($app['webhook_url'] ?? '')) ?>" maxlength="500" placeholder="https://example.com/sso/webhook">
                    </div>
                    <div class="field">
                        <label for="status">وضعیت</label>
                        <select id="status" name="status">
                            <option value="active" <?= (string) $app['status'] === 'active' ? 'selected' : '' ?>>فعال</option>
                            <option value="disabled" <?= (string) $app['status'] === 'disabled' ? 'selected' : '' ?>>غیرفعال</option>
                        </select>
                    </div>
                    <button type="submit" class="btn">ذخیره تغییرات</button>
                </form>
            </div>
        </section>

        <section class="card">
            <header class="card-head"><h2>کلیدهای API</h2></header>
            <div class="card-body">
                <dl class="kv">
                    <dt>شناسه (slug)</dt>
                    <dd><span class="code"><?= e((string) $app['slug']) ?></span></dd>
                    <dt>پیشوند کلید</dt>
                    <dd><span class="code"><?= e((string) $app['api_key_prefix']) ?>…</span></dd>
                    <dt>سکرت</dt>
                    <dd class="muted small">هش‌شده ذخیره می‌شود؛ مقدار اصلی فقط هنگام ساخت/چرخش نمایش داده می‌شود.</dd>
                </dl>
                <hr style="border:none;border-top:1px solid var(--line);margin:16px 0">
                <div class="btn-row">
                    <form method="post" action="<?= e(\sso_url('/admin/app.php?id=' . $id)) ?>">
                        <?= \Sso\Support\Csrf::field() ?>
                        <input type="hidden" name="action" value="rotate_secret">
                        <button type="submit" class="btn btn-ghost btn-sm" data-confirm="سکرت فعلی بی‌اعتبار می‌شود. ادامه می‌دهید؟">چرخش سکرت</button>
                    </form>
                    <form method="post" action="<?= e(\sso_url('/admin/app.php?id=' . $id)) ?>">
                        <?= \Sso\Support\Csrf::field() ?>
                        <input type="hidden" name="action" value="regenerate_keys">
                        <button type="submit" class="btn btn-warning btn-sm" data-confirm="کلید و سکرت جدید ساخته می‌شود و تمام توکن‌های این اپ باطل می‌شود. ادامه می‌دهید؟">تولید مجدد کلید و سکرت</button>
                    </form>
                </div>
                <hr style="border:none;border-top:1px solid var(--line);margin:16px 0">
                <form method="post" action="<?= e(\sso_url('/admin/app.php?id=' . $id)) ?>">
                    <?= \Sso\Support\Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="اپلیکیشن و تمام عضویت‌های آن حذف می‌شود. مطمئن هستید؟">حذف اپلیکیشن</button>
                </form>
            </div>
        </section>
    </div>

    <section class="card">
        <header class="card-head"><h2>تنظیمات رفتاری</h2></header>
        <div class="card-body">
            <form method="post" action="<?= e(\sso_url('/admin/app.php?id=' . $id)) ?>">
                <?= \Sso\Support\Csrf::field() ?>
                <input type="hidden" name="action" value="settings">
                <div class="form-row">
                    <div class="field">
                        <label><input type="checkbox" name="allow_registration" <?= !empty($settings['allow_registration']) ? 'checked' : '' ?>> ثبت‌نام آزاد از طریق API</label>
                        <div class="hint">اگر غیرفعال باشد، فقط شما یا اپ می‌توانید کاربر بسازید.</div>
                    </div>
                    <div class="field">
                        <label><input type="checkbox" name="require_email_verification" <?= !empty($settings['require_email_verification']) ? 'checked' : '' ?>> نیاز به تأیید ایمیل</label>
                        <div class="hint">کاربر جدید تا زمان تأیید در وضعیت «در انتظار» می‌ماند.</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="default_role">نقش پیش‌فرض کاربران جدید</label>
                        <select id="default_role" name="default_role">
                            <?php foreach (Roles::all() as $role): ?>
                                <option value="<?= e($role) ?>" <?= (string) $settings['default_role'] === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="access_token_ttl">عمر توکن دسترسی (ثانیه)</label>
                        <input type="number" id="access_token_ttl" name="access_token_ttl" min="60" value="<?= e((string) ($settings['access_token_ttl'] ?? '')) ?>" placeholder="پیش‌فرض سامانه">
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="refresh_token_ttl">عمر توکن تمدید (ثانیه)</label>
                        <input type="number" id="refresh_token_ttl" name="refresh_token_ttl" min="300" value="<?= e((string) ($settings['refresh_token_ttl'] ?? '')) ?>" placeholder="پیش‌فرض سامانه">
                    </div>
                    <div class="field">
                        <label for="allowed_origins">دامنه‌های مجاز CORS (هر خط یکی)</label>
                        <textarea id="allowed_origins" name="allowed_origins" placeholder="https://example.com&#10;https://*.example.com"><?= e(implode("\n", (array) $settings['allowed_origins'])) ?></textarea>
                    </div>
                </div>
                <button type="submit" class="btn">ذخیره تنظیمات</button>
            </form>
        </div>
    </section>

    <section class="card">
        <header class="card-head"><h2>اعضا (<?= e((string) $memberCount) ?>)</h2></header>
        <div class="card-body" style="padding:0">
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>کاربر</th><th>نقش</th><th>وضعیت</th><th class="num">تاریخ عضویت</th></tr></thead>
                    <tbody>
                        <?php if ($members === []): ?>
                            <tr><td colspan="4" class="empty-row">هیچ عضوی ندارد.</td></tr>
                        <?php else: ?>
                            <?php foreach ($members as $member): ?>
                                <tr>
                                    <td>
                                        <a href="<?= e(\sso_url('/admin/user.php?id=' . (int) $member['user_id'])) ?>">
                                            <?= e((string) ($member['user']['full_name'] ?: $member['user']['email'])) ?>
                                        </a>
                                        <div class="small muted"><?= e((string) $member['user']['email']) ?></div>
                                    </td>
                                    <td><?= Layout::roleBadge((string) $member['role']) ?></td>
                                    <td><?= Layout::statusBadge((string) $member['status']) ?></td>
                                    <td class="num small muted"><?= e(Layout::date($member['created_at'] ?? null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <footer class="card-foot"><a href="<?= e(\sso_url('/admin/users.php?app_id=' . $id)) ?>">مشاهده همه‌ی کاربران این اپ ›</a></footer>
    </section>

    <section class="card">
        <header class="card-head"><h2>نمونه فراخوانی API</h2></header>
        <div class="card-body">
            <pre class="code-block">curl -X POST <?= e(\sso_url('/api/v1/auth/register')) ?> ^
  -H "Content-Type: application/json" ^
  -H "X-Api-Key: <?= e((string) $app['api_key_prefix']) ?>...YOUR_KEY" ^
  -H "X-Api-Secret: YOUR_SECRET" ^
      -d "{\"email\":\"user@example.com\",\"password\":\"secret123\",\"full_name\":\"کاربر نمونه\"}"</pre>
            <p class="small muted" style="margin-top:12px">مستندات کامل در <code class="code">docs/API.md</code></p>
        </div>
    </section>
    <?php

    Layout::end();
});
