<?php

declare(strict_types=1);

/**
 * مدیریت توکن‌های صادرشده.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Admin\Layout;
use Sso\Models\Application;
use Sso\Models\AuditLog;
use Sso\Models\Token;
use Sso\Models\User;
use Sso\Support\Clock;
use Sso\Support\Str;
use Sso\Admin\Page;

Page::run(static function (): void {

    Guard::boot();
    $admin = Guard::requireAuth();

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = Str::userAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!Guard::verifyCsrf($_POST['_token'] ?? null)) {
            Guard::redirectTo('/admin/tokens.php');
        }
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'revoke') {
            Token::revoke((int) ($_POST['token_id'] ?? 0));
            \Sso\Http\Session::flash('success', 'توکن باطل شد.');
            AuditLog::record('tokens.revoked', AuditLog::ACTOR_ADMIN, (int) $admin['id'], null, 'token', (int) ($_POST['token_id'] ?? 0), [], $ip, $ua);
        } elseif ($action === 'revoke_expired') {
            $count = \sso_db()->update(
                'api_tokens',
                ['revoked_at' => Clock::now()],
                '`revoked_at` IS NULL AND `expires_at` < ?',
                [Clock::now()]
            );
            \Sso\Http\Session::flash('success', $count . ' توکن منقضی باطل شد.');
            AuditLog::record('tokens.revoked_expired', AuditLog::ACTOR_ADMIN, (int) $admin['id'], null, 'token', null, ['count' => $count], $ip, $ua);
        } elseif ($action === 'revoke_all_for_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            Token::revokeAllForUser($userId);
            \Sso\Http\Session::flash('success', 'تمام توکن‌های کاربر باطل شد.');
        }

        Guard::redirectTo('/admin/tokens.php?' . http_build_query(array_filter([
            'user_id' => $_POST['back_user_id'] ?? null,
            'app_id' => $_POST['back_app_id'] ?? null,
            'type' => $_POST['back_type'] ?? null,
            'state' => $_POST['back_state'] ?? null,
        ], static fn($v): bool => $v !== null && $v !== '')));
    }

    $userId = (int) ($_GET['user_id'] ?? 0);
    $appId = (int) ($_GET['app_id'] ?? 0);
    $type = trim((string) ($_GET['type'] ?? ''));
    $state = trim((string) ($_GET['state'] ?? 'active'));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 30;

    $where = ['1 = 1'];
    $params = [];
    if ($userId > 0) { $where[] = 't.`user_id` = ?'; $params[] = $userId; }
    if ($appId > 0) { $where[] = 't.`app_id` = ?'; $params[] = $appId; }
    if (in_array($type, [Token::TYPE_ACCESS, Token::TYPE_REFRESH], true)) { $where[] = 't.`token_type` = ?'; $params[] = $type; }
    if ($state === 'active') { $where[] = 't.`revoked_at` IS NULL AND t.`expires_at` > ?'; $params[] = Clock::now(); }
    elseif ($state === 'revoked') { $where[] = 't.`revoked_at` IS NOT NULL'; }
    elseif ($state === 'expired') { $where[] = 't.`revoked_at` IS NULL AND t.`expires_at` < ?'; $params[] = Clock::now(); }

    $whereSql = implode(' AND ', $where);
    $total = \sso_db()->count('SELECT COUNT(*) FROM `api_tokens` t WHERE ' . $whereSql, $params);
    $rows = \sso_db()->fetchAll(
        'SELECT t.*, u.`email` AS user_email, a.`name` AS app_name
         FROM `api_tokens` t
         LEFT JOIN `users` u ON u.`id` = t.`user_id`
         LEFT JOIN `apps` a ON a.`id` = t.`app_id`
         WHERE ' . $whereSql . '
         ORDER BY t.`id` DESC
         LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
        $params
    );
    $pages = max(1, (int) ceil($total / $perPage));
    $apps = Application::all();

    Layout::begin('توکن‌ها', 'tokens', $admin);

    ?>
    <section class="card">
        <header class="card-head"><h2>فیلتر</h2></header>
        <div class="card-body">
            <form method="get" action="<?= e(\sso_url('/admin/tokens.php')) ?>">
                <div class="filter-bar">
                    <div class="field">
                        <label for="user_id">شناسه کاربر</label>
                        <input type="number" id="user_id" name="user_id" value="<?= e($userId > 0 ? (string) $userId : '') ?>" placeholder="همه">
                    </div>
                    <div class="field">
                        <label for="app_id">اپلیکیشن</label>
                        <select id="app_id" name="app_id">
                            <option value="">همه</option>
                            <?php foreach ($apps as $app): ?>
                                <option value="<?= e((string) $app['id']) ?>" <?= $appId === (int) $app['id'] ? 'selected' : '' ?>><?= e((string) $app['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="type">نوع</label>
                        <select id="type" name="type">
                            <option value="">همه</option>
                            <option value="access" <?= $type === 'access' ? 'selected' : '' ?>>access</option>
                            <option value="refresh" <?= $type === 'refresh' ? 'selected' : '' ?>>refresh</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="state">وضعیت</label>
                        <select id="state" name="state">
                            <option value="">همه</option>
                            <option value="active" <?= $state === 'active' ? 'selected' : '' ?>>فعال</option>
                            <option value="revoked" <?= $state === 'revoked' ? 'selected' : '' ?>>باطل‌شده</option>
                            <option value="expired" <?= $state === 'expired' ? 'selected' : '' ?>>منقضی</option>
                        </select>
                    </div>
                    <div class="field">
                        <button type="submit" class="btn">اعمال</button>
                    </div>
                </div>
            </form>
        </div>
        <footer class="card-foot">
            <form method="post" action="<?= e(\sso_url('/admin/tokens.php')) ?>">
                <?= \Sso\Support\Csrf::field() ?>
                <input type="hidden" name="action" value="revoke_expired">
                <input type="hidden" name="back_state" value="<?= e($state) ?>">
                <button type="submit" class="btn btn-ghost btn-sm">ابطال همه‌ی توکن‌های منقضی</button>
            </form>
        </footer>
    </section>

    <section class="card">
        <header class="card-head"><h2>توکن‌ها <span class="muted small">(<?= e((string) $total) ?> مورد)</span></h2></header>
        <div class="card-body" style="padding:0">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>اپلیکیشن</th>
                            <th>نوع</th>
                            <th>پیشوند</th>
                            <th>IP</th>
                            <th class="num">انقضا</th>
                            <th>وضعیت</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="8" class="empty-row">توکنی یافت نشد.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php $info = Token::publicArray($row); ?>
                                <tr>
                                    <td>
                                        <?php if ($info['user_id'] > 0): ?>
                                            <a href="<?= e(\sso_url('/admin/user.php?id=' . (int) $info['user_id'])) ?>"><?= e((string) ($row['user_email'] ?? '')) ?></a>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= e((string) ($row['app_name'] ?? '—')) ?></td>
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
                                    <td>
                                        <?php if ($info['revoked_at'] === null): ?>
                                            <form method="post" action="<?= e(\sso_url('/admin/tokens.php')) ?>">
                                                <?= \Sso\Support\Csrf::field() ?>
                                                <input type="hidden" name="action" value="revoke">
                                                <input type="hidden" name="token_id" value="<?= e((string) $info['id']) ?>">
                                                <input type="hidden" name="back_user_id" value="<?= e($userId > 0 ? (string) $userId : '') ?>">
                                                <input type="hidden" name="back_app_id" value="<?= e($appId > 0 ? (string) $appId : '') ?>">
                                                <input type="hidden" name="back_type" value="<?= e($type) ?>">
                                                <input type="hidden" name="back_state" value="<?= e($state) ?>">
                                                <button type="submit" class="btn btn-ghost btn-sm" data-confirm="این توکن بلافاصله بی‌اعتبار می‌شود. ادامه می‌دهید؟">ابطال</button>
                                            </form>
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
    <?php

    Layout::pagination($page, $pages, \sso_url('/admin/tokens.php'), array_filter([
        'user_id' => $userId > 0 ? $userId : null,
        'app_id' => $appId > 0 ? $appId : null,
        'type' => $type,
        'state' => $state,
    ], static fn($v): bool => $v !== null && $v !== ''));

    Layout::end();
});
