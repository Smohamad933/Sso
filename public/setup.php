<?php

declare(strict_types=1);

/**
 * نصب‌کننده‌ی تحت وب.
 *
 * بعد از اتمام نصب، فایل storage/installed.lock ساخته می‌شود و این صفحه
 * از دسترس خارج می‌شود. برای حفظ امنیت، بهتر است بعد از نصب این فایل را
 * حذف کنید یا در web.config مسدود کنید.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Sso\Data\Database;
use Sso\Install\Installer;
use Sso\Support\Str;

if (Installer::isInstalled() && !isset($_GET['force'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
        . '<body style="font-family:tahoma;text-align:center;padding:80px">'
        . '<h2>نصب قبلاً انجام شده است</h2>'
        . '<p>اگر می‌خواهید دوباره نصب کنید، فایل <code>storage/installed.lock</code> را حذف کنید.</p>'
        . '</body></html>';
    // به‌جای exit از return استفاده می‌شود تا در محیط‌های embed (تست/پیش‌نمایش)
    // اجرای فایل متوقف شود بدون اینکه کل فرایند از بین برود.
    return;
}

$lockFile = SSO_INSTALL_LOCK;
$step = (string) ($_GET['step'] ?? $_POST['step'] ?? '1');
$errors = [];
$notices = [];
$result = null;

$requirements = Installer::requirements();
$canContinue = Installer::missingRequirements() === [];
if (!$canContinue && $step !== '1') {
    $step = '1';
}

$assetBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$autoBaseUrl = '';
if ($assetBase !== '' && str_ends_with($assetBase, '/public')) {
    $autoBaseUrl = substr($assetBase, 0, -7);
} elseif ($assetBase !== '/') {
    $autoBaseUrl = $assetBase;
}
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443 ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$autoBaseUrl = $scheme . '://' . $host . $autoBaseUrl;

/** پیش‌فرض‌های فرم */
$dbValues = [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => 'sso',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'path' => SSO_STORAGE . '/database/sso.sqlite',
];
$adminValues = ['email' => '', 'full_name' => '', 'app_name' => 'سامانه احراز هویت یکپارچه', 'base_url' => $autoBaseUrl, 'demo_app' => '1'];

// ------------------------------------------------------------------ پردازش

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($step === '2') {
        $dbValues = array_merge($dbValues, [
            'driver' => (string) ($_POST['driver'] ?? 'mysql'),
            'host' => Str::trim((string) ($_POST['host'] ?? '')),
            'port' => (int) ($_POST['port'] ?? 3306),
            'database' => Str::trim((string) ($_POST['database'] ?? '')),
            'username' => Str::trim((string) ($_POST['username'] ?? '')),
            'password' => (string) ($_POST['password'] ?? ''),
            'charset' => (string) ($_POST['charset'] ?? 'utf8mb4'),
            'path' => Str::trim((string) ($_POST['path'] ?? '')) ?: (SSO_STORAGE . '/database/sso.sqlite'),
        ]);

        if ($dbValues['driver'] === 'mysql') {
            if ($dbValues['database'] === '') {
                $errors['database'] = 'نام دیتابیس الزامی است.';
            }
        }

        if ($errors === []) {
            $connection = Installer::testConnection($dbValues);
            if (!$connection['ok']) {
                $errors['general'] = 'اتصال ناموفق: ' . $connection['message'];
            } else {
                $notices[] = $connection['message'] . ($connection['create_database']
                    ? ' — دیتابیس «' . $dbValues['database'] . '» در مرحله‌ی بعد ساخته می‌شود.'
                    : '');
                $step = '3';
            }
        }
    } elseif ($step === '3') {
        // مقادیر دیتابیس را از فرم مرحله‌ی قبل همراه می‌آوریم
        foreach (['driver', 'host', 'port', 'database', 'username', 'password', 'charset', 'path'] as $key) {
            if (isset($_POST[$key])) {
                $dbValues[$key] = is_int($_POST[$key]) ? (int) $_POST[$key] : (string) $_POST[$key];
            }
        }
        $adminValues['email'] = Str::normalizeEmail((string) ($_POST['email'] ?? ''));
        $adminValues['full_name'] = Str::trim((string) ($_POST['full_name'] ?? ''));
        $adminValues['app_name'] = Str::trim((string) ($_POST['app_name'] ?? '')) ?: 'سامانه احراز هویت یکپارچه';
        $adminValues['base_url'] = Str::trim((string) ($_POST['base_url'] ?? ''));
        $adminValues['demo_app'] = isset($_POST['demo_app']) ? '1' : '0';

        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirmation'] ?? '');

        if (!Str::isEmail($adminValues['email'])) {
            $errors['email'] = 'ایمیل معتبر وارد کنید.';
        }
        if ($password !== $passwordConfirm) {
            $errors['password_confirmation'] = 'تکرار رمز عبور یکسان نیست.';
        }
        $policyError = \Sso\Services\TokenService::passwordError($password);
        if ($policyError !== null) {
            $errors['password'] = $policyError;
        }

        if ($errors === []) {
            try {
                if ($dbValues['driver'] === 'mysql') {
                    Installer::createDatabase($dbValues);
                }

                Installer::writeConfig(
                    $dbValues,
                    'base64:' . base64_encode(random_bytes(32)),
                    $adminValues['app_name'],
                    $adminValues['base_url']
                );

                // حالا برنامه را با تنظیمات جدید بالا می‌آوریم
                \Sso\Core\App::boot();

                Installer::runSchema(\sso_db());

                \Sso\Install\Installer::createSuperAdmin(
                    $adminValues['email'],
                    $password,
                    $adminValues['full_name'] === '' ? null : $adminValues['full_name']
                );

                $demoApp = null;
                if ($adminValues['demo_app'] === '1') {
                    $demoApp = Installer::createDemoApp('اپلیکیشن نمونه');
                }

                Installer::lock();
                $result = ['admin_email' => $adminValues['email'], 'demo_app' => $demoApp];
                $step = '4';
            } catch (\Throwable $e) {
                \Sso\Support\Log::critical('setup: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                $errors['general'] = 'نصب ناموفق بود: ' . $e->getMessage();
            }
        }
    }
}

$steps = [
    '1' => 'بررسی پیش‌نیازها',
    '2' => 'تنظیم دیتابیس',
    '3' => 'ساخت مدیر',
    '4' => 'پایان',
];

?><!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>نصب سامانه احراز هویت</title>
    <style><?= (string) @file_get_contents(SSO_PUBLIC . '/assets/app.css') ?></style>
</head>
<body class="setup-page">
<div class="setup-wrap">
    <h1>نصب سامانه احراز هویت یکپارچه</h1>
    <p class="muted small">PHP + MySQL روی IIS — چند مرحله‌ی ساده</p>

    <div class="steps">
        <?php foreach ($steps as $key => $label): ?>
            <?php
            $class = 'step';
            if ((string) $key === (string) $step) { $class .= ' is-active'; }
            elseif ((int) $key < (int) $step) { $class .= ' is-done'; }
            ?>
            <div class="<?= e($class) ?>"><?= e((string) $key) ?>. <?= e($label) ?></div>
        <?php endforeach; ?>
    </div>

    <?php if (isset($errors['general'])): ?>
        <div class="flash flash-error"><?= e((string) $errors['general']) ?></div>
    <?php endif; ?>
    <?php foreach ($notices as $notice): ?>
        <div class="flash flash-info"><?= e($notice) ?></div>
    <?php endforeach; ?>

    <?php if ($step === '1'): ?>
        <h3>بررسی پیش‌نیازها</h3>
        <?php foreach ($requirements as $check): ?>
            <div class="check-item">
                <span><?= e($check['name']) ?></span>
                <span><?= $check['ok']
                    ? '<span class="badge badge-success">OK</span>'
                    : '<span class="badge badge-danger">خطا</span>' ?>
                    <span class="muted small"><?= e($check['message']) ?></span></span>
            </div>
            <?php if (!$check['ok']): ?>
                <div class="check-detail">
                    <?php if (!empty($check['detail'])): ?>
                        <div><strong>جزئیات:</strong> <code dir="ltr"><?= e((string) ($check['detail'] ?? '')) ?></code></div>
                    <?php endif; ?>
                    <?php if (!empty($check['hint'])): ?>
                        <div><strong>راه‌حل:</strong> <?= e((string) ($check['hint'] ?? '')) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <div style="margin-top:22px">
            <?php if ($canContinue): ?>
                <form method="get" action="">
                    <input type="hidden" name="step" value="2">
                    <button type="submit" class="btn">مرحله‌ی بعد ›</button>
                </form>
            <?php else: ?>
                <div class="flash flash-error">برخی پیش‌نیازها برآورده نشده‌اند؛ لطفاً آن‌ها را رفع کنید و صفحه را دوباره بارگذاری کنید.</div>
            <?php endif; ?>
                <div class="flash flash-info">
                    <strong>برای دیدنِ دلیلِ دقیق در سرور، از خط فرمان اجرا کنید:</strong>
                    <div><code dir="ltr">php tools/check-requirements.php</code></div>
                    <div class="small">و برای تست اتصال دیتابیس:</div>
                    <div><code dir="ltr">php tools/check-requirements.php --host=127.0.0.1 --port=3306 --database=sso --username=root --password=***</code></div>
                    <div class="small">پس از هر تغییر در php.ini، استخرِ برنامه (Application Pool) را در IIS بازیابی کنید.</div>
                </div>
        </div>

    <?php elseif ($step === '2'): ?>
        <h3>تنظیمات دیتابیس</h3>
        <form method="post" action="">
            <input type="hidden" name="step" value="2">
            <div class="field">
                <label for="driver">نوع دیتابیس</label>
                <select id="driver" name="driver">
                    <option value="mysql" <?= $dbValues['driver'] === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB (پیش‌فرض)</option>
                    <option value="sqlite" <?= $dbValues['driver'] === 'sqlite' ? 'selected' : '' ?>>SQLite (فایل محلی)</option>
                </select>
                <div class="hint">برای استفاده روی سرور واقعی، MySQL توصیه می‌شود.</div>
            </div>

            <div id="mysql-fields">
                <div class="form-row">
                    <div class="field">
                        <label for="host">هاست</label>
                        <input type="text" id="host" name="host" value="<?= e((string) $dbValues['host']) ?>" placeholder="127.0.0.1 یا localhost">
                    </div>
                    <div class="field">
                        <label for="port">پورت</label>
                        <input type="number" id="port" name="port" value="<?= e((string) $dbValues['port']) ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="database">نام دیتابیس</label>
                    <input type="text" id="database" name="database" value="<?= e((string) $dbValues['database']) ?>">
                    <?php if (isset($errors['database'])): ?><div class="error"><?= e((string) $errors['database']) ?></div><?php endif; ?>
                    <div class="hint">اگر وجود نداشته باشد، ساخته می‌شود.</div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="username">نام کاربری</label>
                        <input type="text" id="username" name="username" value="<?= e((string) $dbValues['username']) ?>" autocomplete="off">
                    </div>
                    <div class="field">
                        <label for="password">رمز عبور</label>
                        <input type="password" id="password" name="password" value="<?= e((string) $dbValues['password']) ?>" autocomplete="off">
                    </div>
                </div>
                <div class="field">
                    <label for="charset">مجموعه کاراکتر</label>
                    <input type="text" id="charset" name="charset" value="<?= e((string) $dbValues['charset']) ?>">
                </div>
            </div>

            <button type="submit" class="btn">تست اتصال و ادامه ›</button>
            <a class="btn btn-ghost btn-sm" href="?step=1">‹ بازگشت</a>
        </form>

    <?php elseif ($step === '3'): ?>
        <h3>ساخت حساب مدیر</h3>
        <form method="post" action="">
            <input type="hidden" name="step" value="3">
            <?php foreach ($dbValues as $key => $value): ?>
                <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
            <?php endforeach; ?>

            <div class="form-row">
                <div class="field">
                    <label for="email">ایمیل مدیر</label>
                    <input type="email" id="email" name="email" value="<?= e($adminValues['email']) ?>" required autocomplete="off">
                    <?php if (isset($errors['email'])): ?><div class="error"><?= e((string) $errors['email']) ?></div><?php endif; ?>
                </div>
                <div class="field">
                    <label for="full_name">نام (اختیاری)</label>
                    <input type="text" id="full_name" name="full_name" value="<?= e($adminValues['full_name']) ?>" maxlength="150">
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="admin_password">رمز عبور</label>
                    <input type="password" id="admin_password" name="password" required minlength="8" maxlength="128" autocomplete="new-password">
                    <?php if (isset($errors['password'])): ?><div class="error"><?= e((string) $errors['password']) ?></div><?php endif; ?>
                </div>
                <div class="field">
                    <label for="password_confirmation">تکرار رمز عبور</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
                    <?php if (isset($errors['password_confirmation'])): ?><div class="error"><?= e((string) $errors['password_confirmation']) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="field">
                <label for="app_name">عنوان سامانه</label>
                <input type="text" id="app_name" name="app_name" value="<?= e($adminValues['app_name']) ?>" maxlength="120">
            </div>
            <div class="field">
                <label for="base_url">آدرس پایه (base_url)</label>
                <input type="url" id="base_url" name="base_url" value="<?= e($adminValues['base_url']) ?>">
                <div class="hint">معمولاً همان چیزی است که بالا تشخیص داده شده؛ اگر زیر شاخه نصب می‌کنید آن را اصلاح کنید.</div>
            </div>
            <div class="field">
                <label><input type="checkbox" name="demo_app" value="1" <?= $adminValues['demo_app'] === '1' ? 'checked' : '' ?>> ساخت یک اپلیکیشن نمونه برای شروع</label>
            </div>

            <button type="submit" class="btn">نصب و راه‌اندازی</button>
            <a class="btn btn-ghost btn-sm" href="?step=2">‹ بازگشت</a>
        </form>

    <?php elseif ($step === '4' && $result !== null): ?>
        <div class="flash flash-success">نصب با موفقیت انجام شد.</div>

        <h3>ورود به پنل مدیریت</h3>
        <dl class="kv">
            <dt>ایمیل مدیر</dt><dd><span class="code"><?= e((string) $result['admin_email']) ?></span></dd>
            <dt>آدرس پنل</dt><dd><a href="<?= e(rtrim((string) $adminValues['base_url'], '/') . '/admin/') ?>"><?= e(rtrim((string) $adminValues['base_url'], '/') . '/admin/') ?></a></dd>
        </dl>

        <?php if (is_array($result['demo_app'])): ?>
            <h3 style="margin-top:24px">اپلیکیشن نمونه</h3>
            <p class="small muted">این مقادیر فقط یک‌بار نمایش داده می‌شوند. آن‌ها را کپی و در اپلیکیشن خود نگه دارید.</p>
            <div class="secret-box" id="setupKey"><?= e((string) $result['demo_app']['api_key']) ?></div>
            <div class="secret-box" id="setupSecret"><?= e((string) $result['demo_app']['api_secret']) ?></div>
            <button type="button" class="btn btn-ghost btn-sm" data-copy="#setupKey">کپی کلید</button>
            <button type="button" class="btn btn-ghost btn-sm" data-copy="#setupSecret">کپی سکرت</button>
        <?php endif; ?>

        <div style="margin-top:26px">
            <a class="btn" href="<?= e(rtrim((string) $adminValues['base_url'], '/') . '/admin/') ?>">رفتن به پنل مدیریت</a>
        </div>

        <div class="flash flash-warning" style="margin-top:26px">
            <strong>توصیه امنیتی:</strong> پس از نصب، فایل <code class="code">public/setup.php</code> را حذف کنید
            یا در <code class="code">web.config</code> دسترسی به آن را مسدود کنید.
        </div>
    <?php endif; ?>
</div>
<script><?= (string) @file_get_contents(SSO_PUBLIC . '/assets/app.js') ?></script>
</body>
</html>
<?php
