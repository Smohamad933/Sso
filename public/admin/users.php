<?php

declare(strict_types=1);

/**
 * فهرست کاربران با جستجو و فیلتر.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Admin\Layout;
use Sso\Models\Application;
use Sso\Models\Roles;
use Sso\Models\User;
use Sso\Admin\Page;

Page::run(static function (): void {

    Guard::boot();
    $admin = Guard::requireAuth();

    $filters = [
        'q' => trim((string) ($_GET['q'] ?? '')),
        'status' => trim((string) ($_GET['status'] ?? '')),
        'role' => trim((string) ($_GET['role'] ?? '')),
        'app_id' => (int) ($_GET['app_id'] ?? 0),
        'admins' => isset($_GET['admins']),
        'sort' => trim((string) ($_GET['sort'] ?? 'id')),
        'direction' => trim((string) ($_GET['direction'] ?? 'desc')),
    ];

    $query = [];
    if ($filters['q'] !== '') $query['q'] = $filters['q'];
    if ($filters['status'] !== '') $query['status'] = $filters['status'];
    if ($filters['role'] !== '') $query['role'] = $filters['role'];
    if ($filters['app_id'] > 0) $query['app_id'] = $filters['app_id'];
    if ($filters['admins']) $query['admins'] = 1;
    if ($filters['sort'] !== 'id') $query['sort'] = $filters['sort'];
    if ($filters['direction'] !== 'desc') $query['direction'] = $filters['direction'];

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $result = \sso_app()->users()->paginate([
        'q' => $filters['q'] ?: null,
        'status' => $filters['status'] ?: null,
        'role' => $filters['role'] ?: null,
        'app_id' => $filters['app_id'] ?: null,
        'is_super_admin' => $filters['admins'] ? true : null,
        'sort' => $filters['sort'],
        'direction' => $filters['direction'],
    ], $page, 25);

    $apps = Application::all();

    Layout::begin('کاربران', 'users', $admin);

    ?>
    <section class="card">
        <header class="card-head"><h2>جستجو و فیلتر</h2></header>
        <div class="card-body">
            <form method="get" action="<?= e(\sso_url('/admin/users.php')) ?>">
                <div class="filter-bar">
                    <div class="field">
                        <label for="q">جستجو</label>
                        <input type="text" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="ایمیل، نام یا UUID">
                    </div>
                    <div class="field">
                        <label for="app_id">اپلیکیشن</label>
                        <select id="app_id" name="app_id">
                            <option value="">همه</option>
                            <?php foreach ($apps as $app): ?>
                                <option value="<?= e((string) $app['id']) ?>" <?= $filters['app_id'] === (int) $app['id'] ? 'selected' : '' ?>><?= e((string) $app['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="role">نقش</label>
                        <select id="role" name="role">
                            <option value="">همه</option>
                            <?php foreach (Roles::all() as $role): ?>
                                <option value="<?= e($role) ?>" <?= $filters['role'] === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="status">وضعیت</label>
                        <select id="status" name="status">
                            <option value="">همه</option>
                            <?php foreach (User::statuses() as $status): ?>
                                <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <button type="submit" class="btn">اعمال</button>
                        <a class="btn btn-ghost btn-sm" href="<?= e(\sso_url('/admin/users.php')) ?>">پاک‌سازی</a>
                    </div>
                </div>
                <label class="small"><input type="checkbox" name="admins" value="1" <?= $filters['admins'] ? 'checked' : '' ?>> فقط ادمین‌های کل</label>
            </form>
        </div>
    </section>

    <section class="card">
        <header class="card-head">
            <h2>کاربران <span class="muted small">(<?= e((string) $result['total']) ?> مورد)</span></h2>
        </header>
        <div class="card-body" style="padding:0">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>وضعیت</th>
                            <th>اپلیکیشن‌ها</th>
                            <th class="num">آخرین ورود</th>
                            <th class="num">عضویت</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result['items'] === []): ?>
                            <tr><td colspan="6" class="empty-row">کاربری با این فیلترها یافت نشد.</td></tr>
                        <?php else: ?>
                            <?php foreach ($result['items'] as $user): ?>
                                <tr>
                                    <td>
                                        <a href="<?= e(\sso_url('/admin/user.php?id=' . (int) $user['id'])) ?>">
                                            <?= e((string) ($user['full_name'] ?: $user['email'])) ?>
                                        </a>
                                        <?php if (!empty($user['is_super_admin'])): ?>
                                            <?= Layout::badge('ادمین کل', 'purple') ?>
                                        <?php endif; ?>
                                        <div class="small muted"><?= e((string) $user['email']) ?></div>
                                    </td>
                                    <td><?= Layout::statusBadge((string) $user['status']) ?></td>
                                    <td>
                                        <?php if ($user['memberships'] === []): ?>
                                            <span class="muted small">—</span>
                                        <?php else: ?>
                                            <?php foreach (array_slice((array) $user['memberships'], 0, 3) as $membership): ?>
                                                <span class="code" title="<?= e((string) $membership['role']) ?>"><?= e((string) ($membership['app']['slug'] ?? '')) ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count((array) $user['memberships']) > 3): ?>
                                                <span class="muted small">+<?= e((string) (count((array) $user['memberships']) - 3)) ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num small muted"><?= e(Layout::human($user['last_login_at'] ?? null)) ?></td>
                                    <td class="num small muted"><?= e(Layout::date($user['created_at'] ?? null)) ?></td>
                                    <td><a class="btn btn-ghost btn-sm" href="<?= e(\sso_url('/admin/user.php?id=' . (int) $user['id'])) ?>">جزئیات</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php

    Layout::pagination($result['page'], $result['pages'], \sso_url('/admin/users.php'), $query);
    Layout::end();
});
