<?php

declare(strict_types=1);

/**
 * داشبورد مدیریت.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Admin\Layout;
use Sso\Models\AuditLog;
use Sso\Models\Membership;
use Sso\Models\User;
use Sso\Admin\Page;

Page::run(static function (): void {

    Guard::boot();
    $admin = Guard::requireAuth();

    $stats = \sso_app()->users()->stats();
    $recentUsers = \sso_app()->users()->paginate([], 1, 8);
    $recentLogs = AuditLog::search([], 10);
    $apps = \Sso\Models\Application::all();

    Layout::begin('داشبورد', 'dashboard', $admin);

    ?>
    <div class="grid grid-4">
        <div class="stat">
            <div class="stat-label">کاربران</div>
            <div class="stat-value"><?= e((string) $stats['users']) ?></div>
            <div class="stat-hint"><?= e((string) $stats['users_active']) ?> فعال · <?= e((string) $stats['users_suspended']) ?> مسدود</div>
        </div>
        <div class="stat">
            <div class="stat-label">اپلیکیشن‌ها</div>
            <div class="stat-value"><?= e((string) $stats['apps']) ?></div>
            <div class="stat-hint"><?= e((string) $stats['apps_active']) ?> فعال · <?= e((string) $stats['memberships']) ?> عضویت</div>
        </div>
        <div class="stat">
            <div class="stat-label">توکن‌های فعال</div>
            <div class="stat-value"><?= e((string) $stats['active_tokens']) ?></div>
            <div class="stat-hint">در سراسر سامانه</div>
        </div>
        <div class="stat">
            <div class="stat-label">ورودهای ۲۴ ساعت گذشته</div>
            <div class="stat-value"><?= e((string) $stats['logins_today']) ?></div>
            <div class="stat-hint"><?= e((string) $stats['failed_logins_today']) ?> تلاش ناموفق</div>
        </div>
    </div>

    <div class="grid grid-2">
        <section class="card">
            <header class="card-head"><h2>آخرین کاربران</h2></header>
            <div class="card-body" style="padding:0">
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr><th>کاربر</th><th>وضعیت</th><th class="num">تاریخ ثبت‌نام</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($recentUsers['items'] === []): ?>
                                <tr><td colspan="3" class="empty-row">هنوز کاربری ثبت نشده است.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentUsers['items'] as $user): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= e(\sso_url('/admin/user.php?id=' . (int) $user['id'])) ?>">
                                                <?= e((string) ($user['full_name'] ?: $user['email'])) ?>
                                            </a>
                                            <div class="small muted"><?= e((string) $user['email']) ?></div>
                                        </td>
                                        <td><?= Layout::statusBadge((string) $user['status']) ?></td>
                                        <td class="num small muted"><?= e(Layout::human($user['created_at'] ?? null)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <footer class="card-foot"><a href="<?= e(\sso_url('/admin/users.php')) ?>">مشاهده همه‌ی کاربران ›</a></footer>
        </section>

        <section class="card">
            <header class="card-head"><h2>آخرین رویدادها</h2></header>
            <div class="card-body" style="padding:0">
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr><th>رویداد</th><th>عامل</th><th class="num">زمان</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($recentLogs === []): ?>
                                <tr><td colspan="3" class="empty-row">رویدادی ثبت نشده است.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentLogs as $log): ?>
                                    <tr>
                                        <td><span class="code"><?= e((string) $log['action']) ?></span></td>
                                        <td class="small">
                                            <?php if ($log['actor_email'] !== null): ?>
                                                <?= e((string) $log['actor_email']) ?>
                                            <?php elseif ($log['actor_app_name'] !== null): ?>
                                                <?= e((string) $log['actor_app_name']) ?>
                                            <?php else: ?>
                                                <span class="muted">سیستم</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="num small muted"><?= e(Layout::human($log['created_at'] ?? null)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <footer class="card-foot"><a href="<?= e(\sso_url('/admin/audit.php')) ?>">مشاهده همه‌ی رویدادها ›</a></footer>
        </section>
    </div>

    <section class="card">
        <header class="card-head"><h2>اپلیکیشن‌ها</h2></header>
        <div class="card-body" style="padding:0">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>نام</th><th>شناسه (slug)</th><th>وضعیت</th><th class="num">اعضا</th><th class="num">تاریخ ساخت</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php if ($apps === []): ?>
                            <tr><td colspan="6" class="empty-row">هنوز اپلیکیشنی نساخته‌اید.</td></tr>
                        <?php else: ?>
                            <?php foreach ($apps as $app): ?>
                                <tr>
                                    <td><a href="<?= e(\sso_url('/admin/app.php?id=' . (int) $app['id'])) ?>"><?= e((string) $app['name']) ?></a></td>
                                    <td><span class="code"><?= e((string) $app['slug']) ?></span></td>
                                    <td><?= Layout::statusBadge((string) $app['status']) ?></td>
                                    <td class="num"><?= e((string) Membership::countForApp((int) $app['id'])) ?></td>
                                    <td class="num small muted"><?= e(Layout::date($app['created_at'] ?? null)) ?></td>
                                    <td><a class="btn btn-ghost btn-sm" href="<?= e(\sso_url('/admin/users.php?app_id=' . (int) $app['id'])) ?>">کاربران</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <footer class="card-foot">
            <a class="btn btn-subtle btn-sm" href="<?= e(\sso_url('/admin/apps.php')) ?>">مدیریت اپلیکیشن‌ها</a>
        </footer>
    </section>
    <?php

    Layout::end();
});
