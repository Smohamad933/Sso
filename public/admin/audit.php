<?php

declare(strict_types=1);

/**
 * مشاهده‌ی لاگ رویدادها.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Admin\Layout;
use Sso\Models\Application;
use Sso\Models\AuditLog;
use Sso\Admin\Page;

Page::run(static function (): void {

    Guard::boot();
    $admin = Guard::requireAuth();

    $filters = [
        'actor_type' => trim((string) ($_GET['actor_type'] ?? '')),
        'action' => trim((string) ($_GET['action'] ?? '')),
        'actor_app_id' => (int) ($_GET['actor_app_id'] ?? 0),
        'q' => trim((string) ($_GET['q'] ?? '')),
    ];

    $search = array_filter([
        'actor_type' => $filters['actor_type'] ?: null,
        'action' => $filters['action'] ?: null,
        'actor_app_id' => $filters['actor_app_id'] ?: null,
        'q' => $filters['q'] ?: null,
    ], static fn($v): bool => $v !== null && $v !== '');

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 40;
    $logs = AuditLog::search($search, $perPage, ($page - 1) * $perPage);
    $total = AuditLog::count($search);
    $pages = max(1, (int) ceil($total / $perPage));
    $apps = Application::all();

    Layout::begin('رویدادها', 'audit', $admin);

    ?>
    <section class="card">
        <header class="card-head"><h2>فیلتر</h2></header>
        <div class="card-body">
            <form method="get" action="<?= e(\sso_url('/admin/audit.php')) ?>">
                <div class="filter-bar">
                    <div class="field">
                        <label for="q">جستجو</label>
                        <input type="text" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="رویداد یا متن">
                    </div>
                    <div class="field">
                        <label for="action">نوع رویداد</label>
                        <input type="text" id="action" name="action" value="<?= e($filters['action']) ?>" placeholder="auth.login">
                    </div>
                    <div class="field">
                        <label for="actor_type">عامل</label>
                        <select id="actor_type" name="actor_type">
                            <option value="">همه</option>
                            <?php foreach (['user', 'app', 'admin', 'system'] as $type): ?>
                                <option value="<?= e($type) ?>" <?= $filters['actor_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="actor_app_id">اپلیکیشن</label>
                        <select id="actor_app_id" name="actor_app_id">
                            <option value="">همه</option>
                            <?php foreach ($apps as $app): ?>
                                <option value="<?= e((string) $app['id']) ?>" <?= $filters['actor_app_id'] === (int) $app['id'] ? 'selected' : '' ?>><?= e((string) $app['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <button type="submit" class="btn">اعمال</button>
                        <a class="btn btn-ghost btn-sm" href="<?= e(\sso_url('/admin/audit.php')) ?>">پاک‌سازی</a>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <section class="card">
        <header class="card-head"><h2>رویدادها <span class="muted small">(<?= e((string) $total) ?> مورد)</span></h2></header>
        <div class="card-body" style="padding:0">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>رویداد</th>
                            <th>عامل</th>
                            <th>هدف</th>
                            <th>جزئیات</th>
                            <th>IP</th>
                            <th class="num">زمان</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logs === []): ?>
                            <tr><td colspan="6" class="empty-row">رویدادی یافت نشد.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><span class="code"><?= e((string) $log['action']) ?></span></td>
                                    <td class="small">
                                        <?= Layout::badge((string) $log['actor_type'], (string) $log['actor_type'] === 'admin' ? 'purple' : 'neutral') ?>
                                        <?php if ($log['actor_email'] !== null): ?>
                                            <div><a href="<?= e(\sso_url('/admin/user.php?id=' . (int) $log['actor_user_id'])) ?>"><?= e((string) $log['actor_email']) ?></a></div>
                                        <?php elseif ($log['actor_app_name'] !== null): ?>
                                            <div class="muted"><?= e((string) $log['actor_app_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small muted">
                                        <?php if ($log['target_type'] !== null): ?>
                                            <?= e((string) $log['target_type']) ?>#<?= e((string) $log['target_id']) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="small muted">
                                        <?php if ($log['context'] !== null): ?>
                                            <span class="truncate"><?= e((string) json_encode($log['context'], JSON_UNESCAPED_UNICODE)) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
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
    <?php

    Layout::pagination($page, $pages, \sso_url('/admin/audit.php'), array_filter([
        'q' => $filters['q'],
        'action' => $filters['action'],
        'actor_type' => $filters['actor_type'],
        'actor_app_id' => $filters['actor_app_id'] ?: null,
    ], static fn($v): bool => $v !== null && $v !== ''));

    Layout::end();
});
