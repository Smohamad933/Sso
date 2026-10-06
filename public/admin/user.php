<?php

declare(strict_types=1);

/**
 * جزئیات و مدیریت یک کاربر.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Admin\Layout;
use Sso\Http\HttpException;
use Sso\Models\Application;
use Sso\Models\AuditLog;
use Sso\Models\Membership;
use Sso\Models\Roles;
use Sso\Models\Token;
use Sso\Models\User;
use Sso\Support\Clock;
use Sso\Support\Str;
use Sso\Admin\Page;

Page::run(static function (): void {

    Guard::boot();
    $admin = Guard::requireAuth();

    $id = (int) ($_GET['id'] ?? 0);
    $user = User::find($id);
    if ($user === null) {
        \Sso\Http\Session::flash('error', 'کاربر یافت نشد.');
        Guard::redirectTo('/admin/users.php');
    }

    $errors = [];
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = Str::userAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!Guard::verifyCsrf($_POST['_token'] ?? null)) {
            Guard::redirectTo('/admin/user.php?id=' . $id);
        }
        $action = (string) ($_POST['action'] ?? '');

        try {
            switch ($action) {
                case 'update':
                    $email = Str::normalizeEmail((string) ($_POST['email'] ?? ''));
                    if (!Str::isEmail($email)) {
                        $errors['email'] = 'ایمیل معتبر نیست.';
                        break;
                    }
                    $taken = User::findByEmail($email);
                    if ($taken !== null && (int) $taken['id'] !== $id) {
                        $errors['email'] = 'این ایمیل متعلق به کاربر دیگری است.';
                        break;
                    }
                    $metadataRaw = Str::trim((string) ($_POST['metadata'] ?? ''));
                    $metadata = null;
                    if ($metadataRaw !== '') {
                        $decoded = json_decode($metadataRaw, true);
                        if (!is_array($decoded)) {
                            $errors['metadata'] = 'متادیتا باید JSON معتبر باشد.';
                            break;
                        }
                        $metadata = $decoded;
                    }

                    $status = (string) ($_POST['status'] ?? '');
                    if (!in_array($status, User::statuses(), true)) {
                        $errors['status'] = 'وضعیت نامعتبر است.';
                        break;
                    }

                    $isAdmin = (int) $admin['id'] === $id ? 1 : (isset($_POST['is_super_admin']) ? 1 : 0);

                    User::update($id, [
                        'email' => $email,
                        'full_name' => Str::trim((string) ($_POST['full_name'] ?? '')) ?: null,
                        'phone' => Str::trim((string) ($_POST['phone'] ?? '')) ?: null,
                        'avatar_url' => Str::trim((string) ($_POST['avatar_url'] ?? '')) ?: null,
                        'metadata' => $metadata,
                        'status' => $status,
                        'is_super_admin' => $isAdmin,
                        'email_verified_at' => isset($_POST['email_verified'])
                            ? ($user['email_verified_at'] ?? Clock::now())
                            : null,
                    ]);
                    if ($status !== User::STATUS_ACTIVE) {
                        Token::revokeAllForUser($id);
                    }
                    \Sso\Http\Session::flash('success', 'اطلاعات کاربر ذخیره شد.');
                    break;

                case 'password':
                    $password = (string) ($_POST['new_password'] ?? '');
                    \sso_app()->users()->setPassword($id, $password);
                    \Sso\Http\Session::flash('success', 'رمز عبور تغییر کرد و تمام توکن‌های کاربر باطل شد.');
                    break;

                case 'reset_token':
                    $reset = \sso_app()->passwords()->createToken($user);
                    \Sso\Http\Session::flash('warning', 'توکن بازیابی صادر شد (۶۰ دقیقه معتبر است):');
                    \Sso\Http\Session::flash('secret_reset_token', $reset['token']);
                    break;

                case 'attach':
                    $appId = (int) ($_POST['app_id'] ?? 0);
                    $role = (string) ($_POST['role'] ?? Roles::MEMBER);
                    if (Application::find($appId) === null) {
                        $errors['app_id'] = 'اپلیکیشن نامعتبر است.';
                        break;
                    }
                    \sso_app()->users()->attachToApp($appId, $id, $role);
                    \Sso\Http\Session::flash('success', 'کاربر به اپلیکیشن متصل شد.');
                    break;

                case 'role':
                    $appId = (int) ($_POST['app_id'] ?? 0);
                    \sso_app()->users()->setRole($appId, $id, (string) ($_POST['role'] ?? Roles::MEMBER), Roles::OWNER);
                    \Sso\Http\Session::flash('success', 'نقش به‌روزرسانی شد.');
                    break;

                case 'detach':
                    $appId = (int) ($_POST['app_id'] ?? 0);
                    \sso_app()->users()->detachFromApp($appId, $id);
                    \Sso\Http\Session::flash('success', 'دسترسی کاربر از اپلیکیشن قطع شد.');
                    break;

                case 'revoke_tokens':
                    Token::revokeAllForUser($id);
                    \Sso\Http\Session::flash('success', 'تمام توکن‌های این کاربر باطل شد.');
                    break;

                case 'delete':
                    if ((int) $admin['id'] === $id) {
                        \Sso\Http\Session::flash('error', 'نمی‌توانید حساب خود را حذف کنید.');
                        break;
                    }
                    \sso_app()->users()->deleteUser($id);
                    \Sso\Http\Session::flash('success', 'کاربر حذف شد.');
                    AuditLog::record('users.deleted', AuditLog::ACTOR_ADMIN, (int) $admin['id'], null, 'user', $id, [], $ip, $ua);
                    Guard::redirectTo('/admin/users.php');
            }

            if ($action !== 'delete') {
                AuditLog::record('users.' . $action, AuditLog::ACTOR_ADMIN, (int) $admin['id'], null, 'user', $id, [], $ip, $ua);
            }
        } catch (\Sso\Api\ApiException $e) {
            $errors = array_merge($errors, (array) ($e->fields ?? []));
            if ($errors === []) {
                $errors['general'] = $e->getMessage();
            }
        } catch (\Sso\Http\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Sso\Support\Log::error('admin user: ' . $e->getMessage());
            $errors['general'] = 'خطا: ' . $e->getMessage();
        }

        Guard::redirectTo('/admin/user.php?id=' . $id);
    }

    $user = User::find($id) ?? $user;
    $memberships = Membership::listForUser($id);
    $tokens = \sso_db()->fetchAll(
        'SELECT * FROM `api_tokens` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 20',
        [$id]
    );
    $logs = AuditLog::search(['actor_user_id' => $id], 15);
    $apps = Application::all();
    $attached = array_map(static fn(array $m): int => (int) $m['app_id'], $memberships);
    $availableApps = array_values(array_filter($apps, static fn(array $a): bool => !in_array((int) $a['id'], $attached, true)));

    Layout::begin('کاربر: ' . (string) ($user['full_name'] ?: $user['email']), 'users', $admin);

    $resetToken = \Sso\Http\Session::getFlash('secret_reset_token');
    if (is_string($resetToken) && $resetToken !== ''): ?>
        <div class="flash flash-warning">
            <strong>توکن بازیابی (یک‌بار نمایش):</strong>
            <div class="secret-box" id="resetToken"><?= e($resetToken) ?></div>
            <button type="button" class="btn btn-ghost btn-sm" data-copy="#resetToken">کپی</button>
        </div>
    <?php endif;

    Layout::errorBox($errors);

    ?>
    <p><a href="<?= e(\sso_url('/admin/users.php')) ?>">‹ بازگشت به کاربران</a></p>

    <div class="grid grid-2">
        <section class="card">
            <header class="card-head"><h2>اطلاعات کاربر</h2></header>
            <div class="card-body">
                <form method="post" action="<?= e(\sso_url('/admin/user.php?id=' . $id)) ?>">
                    <?= \Sso\Support\Csrf::field() ?>
                    <input type="hidden" name="action" value="update">
                    <div class="field">
                        <label for="email">ایمیل</label>
                        <input type="email" id="email" name="email" value="<?= e((string) $user['email']) ?>" required maxlength="190">
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label for="full_name">نام کامل</label>
                            <input type="text" id="full_name" name="full_name" value="<?= e((string) ($user['full_name'] ?? '')) ?>" maxlength="150">
                        </div>
                        <div class="field">
                            <label for="phone">تلفن</label>
                            <input type="text" id="phone" name="phone" value="<?= e((string) ($user['phone'] ?? '')) ?>" maxlength="32">
                        </div>
                    </div>
                    <div class="field">
                        <label for="avatar_url">آدرس تصویر</label>
                        <input type="url" id="avatar_url" name="avatar_url" value="<?= e((string) ($user['avatar_url'] ?? '')) ?>" maxlength="500">
                    </div>
                    <div class="field">
                        <label for="metadata">متادیتا (JSON دلخواه — اطلاعات اختصاصی شما)</label>
                        <textarea id="metadata" name="metadata" dir="ltr" style="text-align:left"><?= e((string) (($user['metadata'] ?? null) === null ? '' : json_encode(User::decodeMetadata($user['metadata']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))) ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label for="status">وضعیت</label>
                            <select id="status" name="status">
                                <?php foreach (User::statuses() as $status): ?>
                                    <option value="<?= e($status) ?>" <?= (string) $user['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>&nbsp;</label>
                            <label><input type="checkbox" name="email_verified" <?= $user['email_verified_at'] !== null ? 'checked' : '' ?>> ایمیل تأیید شده</label>
                            <label><input type="checkbox" name="is_super_admin" <?= (int) ($user['is_super_admin'] ?? 0) === 1 ? 'checked' : '' ?> <?= (int) $admin['id'] === $id ? 'disabled' : '' ?>> ادمین کل</label>
                            <?php if ((int) $admin['id'] === $id): ?>
                                <div class="hint">برای جلوگیری از قفل شدن پنل، نمی‌توانید دسترسی ادمین خود را بردارید.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn">ذخیره تغییرات</button>
                </form>
            </div>
        </section>

        <div>
            <section class="card">
                <header class="card-head"><h2>امنیت</h2></header>
                <div class="card-body">
                    <dl class="kv">
                        <dt>شناسه</dt><dd><span class="code"><?= e((string) $user['id']) ?></span></dd>
                        <dt>UUID</dt><dd><span class="code"><?= e((string) $user['uuid']) ?></span></dd>
                        <dt>آخرین ورود</dt><dd><?= e(Layout::human($user['last_login_at'] ?? null)) ?></dd>
                        <dt>تغییر رمز</dt><dd><?= e(Layout::human($user['password_changed_at'] ?? null)) ?></dd>
                        <dt>تلاش‌های ناموفق</dt><dd><?= e((string) ($user['failed_logins'] ?? 0)) ?></dd>
                        <dt>قفل تا</dt><dd><?= e(Layout::date($user['locked_until'] ?? null)) ?></dd>
                        <dt>عضویت از</dt><dd><?= e(Layout::date($user['created_at'] ?? null)) ?></dd>
                    </dl>
                    <hr style="border:none;border-top:1px solid var(--line);margin:16px 0">
                    <form method="post" action="<?= e(\sso_url('/admin/user.php?id=' . $id)) ?>" style="margin-bottom:10px">
                        <?= \Sso\Support\Csrf::field() ?>
                        <input type="hidden" name="action" value="password">
                        <div class="field">
                            <label for="new_password">رمز عبور جدید</label>
                            <input type="text" id="new_password" name="new_password" required minlength="<?= e((string) \sso_config('security.password_min_length', 8)) ?>" maxlength="128">
                            <div class="hint">با تغییر رمز، همه‌ی توکن‌های فعال این کاربر باطل می‌شوند.</div>
                        </div>
                        <button type="submit" class="btn btn-warning btn-sm" data-confirm="تمام توکن‌های این کاربر باطل می‌شود. ادامه می‌دهید؟">تنظیم رمز جدید</button>
                    </form>
                    <div class="btn-row">
                        <form method="post" action="<?= e(\sso_url('/admin/user.php?id=' . $id)) ?>">
                            <?= \Sso\Support\Csrf::field() ?>
                            <input type="hidden" name="action" value="reset_token">
                            <button type="submit" class="btn btn-ghost btn-sm">صدور توکن بازیابی</button>
                        </form>
                        <form method="post" action="<?= e(\sso_url('/admin/user.php?id=' . $id)) ?>">
                            <?= \Sso\Support\Csrf::field() ?>
                            <input type="hidden" name="action" value="revoke_tokens">
                            <button type="submit" class="btn btn-ghost btn-sm" data-confirm="تمام توکن‌های این کاربر باطل می‌شود. ادامه می‌دهید؟">ابطال همه‌ی توکن‌ها</button>
                        </form>
                    </div>
                </div>
            </section>

            <section class="card">
                <header class="card-head"><h2>اتصال به اپلیکیشن</h2></header>
                <div class="card-body">
                    <?php if ($availableApps === []): ?>
                        <p class="muted small">این کاربر به همه‌ی اپلیکیشن‌ها متصل است.</p>
                    <?php else: ?>
                        <form method="post" action="<?= e(\sso_url('/admin/user.php?id=' . $id)) ?>">
                            <?= \Sso\Support\Csrf::field() ?>
                            <input type="hidden" name="action" value="attach">
                            <div class="form-row">
                                <div class="field">
                                    <label for="app_id">اپلیکیشن</label>
                                    <select id="app_id" name="app_id">
                                        <?php foreach ($availableApps as $app): ?>
                                            <option value="<?= e((string) $app['id']) ?>"><?= e((string) $app['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="role">نقش</label>
                                    <select id="role" name="role">
                                        <?php foreach (Roles::all() as $role): ?>
                                            <option value="<?= e($role) ?>" <?= $role === Roles::MEMBER ? 'selected' : '' ?>><?= e($role) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-sm">اتصال</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <section class="card">
        <header class="card-head"><h2>عضویت در اپلیکیشن‌ها</h2></header>
        <div class="card-body" style="padding:0">
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>اپلیکیشن</th><th>نقش</th><th>وضعیت</th><th class="num">تاریخ</th><th></th></tr></thead>
                    <tbody>
                        <?php if ($memberships === []): ?>
                            <tr><td colspan="5" class="empty-row">عضو هیچ اپلیکیشنی نیست.</td></tr>
                        <?php else: ?>
                            <?php foreach ($memberships as $membership): ?>
                                <tr>
                                    <td>
                                        <a href="<?= e(\sso_url('/admin/app.php?id=' . (int) $membership['app_id'])) ?>">
                                            <?= e((string) ($membership['app']['name'] ?? '')) ?>
                                        </a>
                                        <div class="small muted"><span class="code"><?= e((string) ($membership['app']['slug'] ?? '')) ?></span></div>
                                    </td>
                                    <td>
                                        <form method="post" action="<?= e(\sso_url('/admin/user.php?id=' . $id)) ?>" class="btn-row">
                                            <?= \Sso\Support\Csrf::field() ?>
                                            <input type="hidden" name="action" value="role">
                                            <input type="hidden" name="app_id" value="<?= e((string) $membership['app_id']) ?>">
                                            <select name="role" data-auto-submit>
                                                <?php foreach (Roles::all() as $role): ?>
                                                    <option value="<?= e($role) ?>" <?= (string) $membership['role'] === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-ghost btn-sm">ذخیره</button>
                                        </form>
                                    </td>
                                    <td><?= Layout::statusBadge((string) $membership['status']) ?></td>
                                    <td class="num small muted"><?= e(Layout::date($membership['created_at'] ?? null)) ?></td>
                                    <td>
                                        <form method="post" action="<?= e(\sso_url('/admin/user.php?id=' . $id)) ?>">
                                            <?= \Sso\Support\Csrf::field() ?>
                                            <input type="hidden" name="action" value="detach">
                                            <input type="hidden" name="app_id" value="<?= e((string) $membership['app_id']) ?>">
                                            <button type="submit" class="btn btn-ghost btn-sm" data-confirm="دسترسی کاربر از این اپلیکیشن قطع می‌شود. ادامه می‌دهید؟">قطع دسترسی</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card">
        <header class="card-head"><h2>آخرین توکن‌ها</h2></header>
        <div class="card-body" style="padding:0">
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>نوع</th><th>پیشوند</th><th>IP</th><th class="num">انقضا</th><th>وضعیت</th></tr></thead>
                    <tbody>
                        <?php if ($tokens === []): ?>
                            <tr><td colspan="5" class="empty-row">توکنی صادر نشده است.</td></tr>
                        <?php else: ?>
                            <?php foreach ($tokens as $token): ?>
                                <?php $info = Token::publicArray($token); ?>
                                <tr>
                                    <td><?= Layout::badge((string) $info['type'], (string) $info['type'] === 'access' ? 'info' : 'neutral') ?></td>
                                    <td><span class="code"><?= e((string) $info['prefix']) ?>***</span></td>
                                    <td class="small muted"><?= e((string) ($info['ip_address'] ?? '—')) ?></td>
                                    <td class="num small muted"><?= e(Layout::date($info['expires_at'] ?? null)) ?></td>
                                    <td>
                                        <?php if ($info['revoked_at'] !== null): ?>
                                            <?= Layout::badge('باطل شده', 'danger') ?>
                                        <?php elseif ($info['is_expired']): ?>
                                            <?= Layout::badge('منقضی', 'warning') ?>
                                        <?php else: ?>
                                            <?= Layout::badge('فعال', 'success') ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card">
        <header class="card-head"><h2>رویدادهای این کاربر</h2></header>
        <div class="card-body" style="padding:0">
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>رویداد</th><th>IP</th><th class="num">زمان</th></tr></thead>
                    <tbody>
                        <?php if ($logs === []): ?>
                            <tr><td colspan="3" class="empty-row">رویدادی ثبت نشده است.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><span class="code"><?= e((string) $log['action']) ?></span></td>
                                    <td class="small muted"><?= e((string) ($log['ip_address'] ?? '—')) ?></td>
                                    <td class="num small muted"><?= e(Layout::human($log['created_at'] ?? null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php if ((int) $admin['id'] !== $id): ?>
        <section class="card">
            <header class="card-head"><h2>حذف کاربر</h2></header>
            <div class="card-body">
                <p class="small muted">حذف به صورت نرم انجام می‌شود: رکورد کاربر باقی می‌ماند اما از همه‌ی فهرست‌ها خارج و تمام توکن‌ها و عضویت‌هایش باطل می‌شود.</p>
                <form method="post" action="<?= e(\sso_url('/admin/user.php?id=' . $id)) ?>">
                    <?= \Sso\Support\Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="کاربر حذف می‌شود. مطمئن هستید؟">حذف کاربر</button>
                </form>
            </div>
        </section>
    <?php endif; ?>
    <?php

    Layout::end();
});
