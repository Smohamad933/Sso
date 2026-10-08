<?php

declare(strict_types=1);

/**
 * مستنداتِ API در پنل مدیریت.
 *
 * این صفحه برای این است که مدیر بتواند آن را به برنامه‌نویس بدهد تا طبقِ آن
 * احراز هویت را پیاده‌سازی کند. نکته‌ی مهم: «فهرست مسیرها» از روی جدولِ
 * مسیرهای واقعیِ برنامه ساخته می‌شود، نه از یک متنِ دستی؛ بنابراین اگر مسیری
 * اضافه شود این‌جا هم پیدایش می‌شود، و اگر توضیحش نوشته نشده باشد تست خطا
 * می‌دهد (Sso\Support\ApiDocs::missing()).
 *
 * دو خروجیِ قابل دانلود هم دارد: مجموعه‌ی Postman و سند OpenAPI.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Sso\Admin\Guard;
use Sso\Admin\Layout;
use Sso\Admin\Page;
use Sso\Support\ApiDocs;

Page::run(static function (): void {

    Guard::boot();
    $admin = Guard::requireAuth();

    $base = rtrim((string) \sso_config('base_url', ''), '/');
    $apiBase = $base . '/api/v1';

    /** @var \Sso\Api\Router $router */
    $router = require dirname(__DIR__, 2) . '/src/Api/routes.php';
    $routes = $router->routes();

    // ------------------------------------------------------------------ خروجی
    $export = (string) ($_GET['export'] ?? '');
    if ($export === 'postman' || $export === 'openapi') {
        $payload = $export === 'postman'
            ? ApiDocs::postmanCollection($routes, $apiBase)
            : ApiDocs::openapiDocument($routes, $base);

        \header('Content-Type: application/json; charset=utf-8');
        \header('Cache-Control: no-store, no-cache, must-revalidate');
        \header('Content-Disposition: attachment; filename="sso-api-'
            . ($export === 'postman' ? 'postman-collection' : 'openapi') . '.json"');
        echo \json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    // ------------------------------------------------------- نمونه‌کدهای آماده
    // با NOWDOC نوشته شده‌اند تا هیچ نیازی به فرار دادنِ کاراکترها نباشد.
    $curlSample = str_replace('{API_BASE}', $apiBase, <<<'CURL'
# ۱) ثبت‌نام کاربر
curl -X POST "{API_BASE}/auth/register" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: YOUR_API_KEY" \
  -H "X-Api-Secret: YOUR_API_SECRET" \
  -d '{"email":"ali@example.com","password":"YekRamez123","full_name":"علی رضایی"}'

# ۲) ورود
curl -X POST "{API_BASE}/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: YOUR_API_KEY" \
  -H "X-Api-Secret: YOUR_API_SECRET" \
  -d '{"email":"ali@example.com","password":"YekRamez123"}'

# ۳) استفاده از توکن برای گرفتن اطلاعات کاربر
curl -X GET "{API_BASE}/me" \
  -H "Authorization: Bearer ACCESS_TOKEN"

# ۴) وقتی توکن منقضی شد، با توکنِ تمدید یکی تازه بگیرید
curl -X POST "{API_BASE}/auth/refresh" \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"REFRESH_TOKEN"}'
CURL);

    $phpSample = str_replace('{API_BASE}', $apiBase, <<<'PHPSAMPLE'
<?php

declare(strict_types=1);

/**
 * یک کلاینتِ ساده برای سامانه احراز هویت.
 * این کلاس فقط از cURL استفاده می‌کند؛ هیچ وابستگیِ خارجی ندارد.
 */
final class SsoClient
{
    public function __construct(
        private string $base,      // مثال: {API_BASE}
        private string $key,       // X-Api-Key
        private string $secret     // X-Api-Secret
    ) {
    }

    private function call(string $method, string $path, array $body = [], ?string $token = null): array
    {
        $headers = ['Accept: application/json'];

        if ($token !== null) {
            // درخواست به نیابت از کاربر
            $headers[] = 'Authorization: Bearer ' . $token;
        } else {
            // درخواستِ سرور به سرور با کلیدِ اپلیکیشن
            $headers[] = 'X-Api-Key: ' . $this->key;
            $headers[] = 'X-Api-Secret: ' . $this->secret;
        }
        if ($body !== []) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($body !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('SSO خطای ' . $status . ': ' . $raw);
        }

        return is_array($data) ? $data : [];
    }

    public function register(string $email, string $password, array $extra = []): array
    {
        return $this->call('POST', '/auth/register', $extra + [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function login(string $email, string $password): array
    {
        return $this->call('POST', '/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function me(string $token): array
    {
        return $this->call('GET', '/me', [], $token);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->call('POST', '/auth/refresh', ['refresh_token' => $refreshToken]);
    }

    public function users(string $token = '', array $filters = []): array
    {
        $query = $filters === [] ? '' : '?' . http_build_query($filters);
        return $this->call('GET', '/users' . $query);
    }
}

// ---- استفاده
$sso = new SsoClient('{API_BASE}', 'YOUR_API_KEY', 'YOUR_API_SECRET');

// ورود؛ توکن‌ها را در نشستِ کاربرِ خودتان نگه دارید
$tokens = $sso->login('ali@example.com', 'YekRamez123');

// گرفتن اطلاعات کاربر با توکنِ دسترسی
$user = $sso->me($tokens['access_token']);
echo $user['user']['email'];

// تمدید وقتی توکنِ دسترسی منقضی شد
$again = $sso->refresh($tokens['refresh_token']);
PHPSAMPLE);

    $jsSample = str_replace('{API_BASE}', $apiBase, <<<'JSSAMPLE'
// توجه: این کد برای «سرور» است (Node.js یا بک‌اند).
// رازِ اپلیکیشن را هرگز در مرورگر یا اپلیکیشن موبایل قرار ندهید.

const BASE = '{API_BASE}';
const KEY = 'YOUR_API_KEY';
const SECRET = 'YOUR_API_SECRET';

async function sso(path, { method = 'GET', body, token } = {}) {
  const headers = { Accept: 'application/json' };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  } else {
    headers['X-Api-Key'] = KEY;
    headers['X-Api-Secret'] = SECRET;
  }
  if (body) headers['Content-Type'] = 'application/json';

  const res = await fetch(BASE + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.message || `خطا ${res.status}`);
  }
  return data;
}

// ۱) ورود
const tokens = await sso('/auth/login', {
  method: 'POST',
  body: { email: 'ali@example.com', password: 'YekRamez123' },
});

// ۲) اطلاعات کاربر با توکنِ دسترسی
const me = await sso('/me', { token: tokens.access_token });

// ۳) تمدید
const refreshed = await sso('/auth/refresh', {
  method: 'POST',
  body: { refresh_token: tokens.refresh_token },
});
JSSAMPLE);

    // ------------------------------------------------------------------ نمایش
    $groups = ApiDocs::groups();
    $modes = ApiDocs::authModes();
    $seq = 0;

    /** شناسه‌ی یکتا برای هر بلوکِ کد (برای دکمه‌ی کپی) */
    $uid = static function (string $prefix) use (&$seq): string {
        $seq++;
        return $prefix . '-' . $seq;
    };

    /** بلوکِ کد همراه با دکمه‌ی کپی */
    $code = static function (string $text) use ($uid): string {
        $id = $uid('code');
        $out = '<div class="code-wrap">';
        $out .= '<button type="button" class="btn btn-ghost btn-sm code-copy" data-copy="#' . $id . '">کپی</button>';
        $out .= '<pre class="code-block" id="' . $id . '" dir="ltr">' . e($text) . '</pre>';
        $out .= '</div>';
        return $out;
    };

    /** نمونه‌ی cURL برای یک مسیر */
    $curl = static function (array $route, array $doc) use ($apiBase): string {
        $method = (string) $route['method'];
        $mode = (string) ($route['auth'] ?? 'public');
        // {id} را با یک مقدار نمونه پر می‌کنیم تا مثال آماده‌ی اجرا باشد
        $path = str_replace('{id}', '12', (string) $route['path']);

        $lines = ['curl -X ' . $method . ' "' . $apiBase . ApiDocs::suffix($path) . '" \\'];
        $lines[] = '  -H "Accept: application/json"';

        if ($mode === 'app' || $mode === 'app_or_user' || $mode === 'optional_app') {
            $optional = $mode === 'optional_app' ? '   # اختیاری' : '';
            $lines[] = '  -H "X-Api-Key: YOUR_API_KEY"' . $optional;
            $lines[] = '  -H "X-Api-Secret: YOUR_API_SECRET"' . $optional;
        }
        if ($mode === 'user') {
            $lines[] = '  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"';
        }
        if ($doc['body'] !== null && $doc['body'] !== '') {
            $lines[] = '  -H "Content-Type: application/json"';
            $compact = (string) preg_replace('/\s+/', ' ', (string) $doc['body']);
            $lines[] = "  -d '" . $compact . "'";
        }

        $count = count($lines);
        $out = '';
        foreach ($lines as $i => $line) {
            $out .= $line . ($i < $count - 1 ? " \\\n" : "\n");
        }
        return rtrim($out);
    };

    Layout::begin('مستندات', 'docs', $admin);
    ?>
    <div class="page-title">
        <h2>مستنداتِ API</h2>
        <p class="muted small">این صفحه را به برنامه‌نویس بدهید تا طبق آن احراز هویت را پیاده‌سازی کند.</p>
    </div>

    <section class="card">
        <header class="card-head"><h2>۱. نشانی پایه و کلیدها</h2></header>
        <div class="card-body">
            <div class="field">
                <label>نشانی پایه‌ی API</label>
                <div class="secret-box" id="docsBaseUrl"><?= e($apiBase) ?></div>
                <button type="button" class="btn btn-ghost btn-sm" data-copy="#docsBaseUrl">کپی نشانی</button>
            </div>
            <p class="muted small">
                برای ساخت کلید و راز به صفحه‌ی <a href="<?= e(\sso_url('/admin/apps.php')) ?>">اپلیکیشن‌ها</a>
                بروید و یک اپلیکیشن بسازید. کلید و راز فقط یک‌بار نمایش داده می‌شوند.
            </p>

            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>نوع اعتبار</th><th>نحوه‌ی ارسال</th><th>کجا استفاده شود</th></tr></thead>
                <tbody>
                <tr>
                    <td><strong>کلید و رازِ اپلیکیشن</strong></td>
                    <td dir="ltr" class="code">X-Api-Key / X-Api-Secret</td>
                    <td>فقط سرور به سرور. مدیریت کاربران، ثبت‌نام، ورود و دریافت آمار.</td>
                </tr>
                <tr>
                    <td><strong>توکنِ کاربر</strong></td>
                    <td dir="ltr" class="code">Authorization: Bearer …</td>
                    <td>وقتی خودِ کاربر وارد شده و برنامه به نیابت از او کار می‌کند.</td>
                </tr>
                </tbody>
            </table>
            </div>

            <div class="flash flash-warning">
                <strong>هشدار امنیتی:</strong> رازِ اپلیکیشن را هرگز در کدِ جاوااسکریپتِ مرورگر،
                اپلیکیشن موبایل یا مخزن عمومی قرار ندهید. کلیدها فقط روی سرورِ خودتان باشند.
            </div>
        </div>
    </section>

    <section class="card">
        <header class="card-head"><h2>۲. شروع سریع</h2></header>
        <div class="card-body">
            <div data-tab-group>
                <div class="docs-tabs">
                    <button type="button" class="docs-tab is-active" data-tab="curl">cURL</button>
                    <button type="button" class="docs-tab" data-tab="php">PHP</button>
                    <button type="button" class="docs-tab" data-tab="js">JavaScript</button>
                </div>

                <div class="docs-panel" data-tab-panel="curl">
                    <?= $code($curlSample) ?>
                </div>
                <div class="docs-panel" data-tab-panel="php" hidden>
                    <?= $code($phpSample) ?>
                </div>
                <div class="docs-panel" data-tab-panel="js" hidden>
                    <?= $code($jsSample) ?>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <header class="card-head"><h2>۳. معنای حالت‌های احراز هویت</h2></header>
        <div class="card-body">
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>حالت</th><th>یعنی چه</th><th>هدرهای لازم</th></tr></thead>
                <tbody>
                <?php foreach ($modes as $key => $mode): ?>
                    <tr>
                        <td><span class="code" dir="ltr"><?= e($key) ?></span></td>
                        <td>
                            <strong><?= e($mode['label']) ?></strong><br>
                            <span class="muted small"><?= e($mode['desc']) ?></span>
                        </td>
                        <td dir="ltr" class="code small"><?= $mode['headers'] === [] ? '—' : e(implode('، ', $mode['headers'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </section>

    <?php
    $grouped = [];
    foreach ($routes as $route) {
        $doc = ApiDocs::describe((string) $route['method'], (string) $route['path']);
        $grouped[$doc['group']][] = [$route, $doc];
    }
    ?>

    <?php foreach ($groups as $groupKey => $groupLabel): ?>
        <?php if (!isset($grouped[$groupKey])) { continue; } ?>
        <section class="card">
            <header class="card-head"><h2><?= e($groupLabel) ?></h2></header>
            <div class="card-body">
                <?php foreach ($grouped[$groupKey] as $pair): ?>
                    <?php
                    $route = $pair[0];
                    $doc = $pair[1];
                    $method = (string) $route['method'];
                    $path = (string) $route['path'];
                    ?>
                    <div class="docs-endpoint">
                        <div class="docs-endpoint-head">
                            <span class="docs-method docs-method-<?= e(strtolower($method)) ?>"><?= e($method) ?></span>
                            <span class="docs-path" dir="ltr"><?= e($path) ?></span>
                            <span class="badge badge-info"><?= e($modes[(string) ($route['auth'] ?? 'public')]['label'] ?? 'عمومی') ?></span>
                        </div>
                        <h4 class="docs-endpoint-title"><?= e($doc['title']) ?></h4>
                        <p class="muted small"><?= e($doc['summary']) ?></p>

                        <?php if ($doc['params'] !== []): ?>
                            <div class="table-wrap">
                            <table class="table">
                                <thead><tr><th>فیلد</th><th>نوع</th><th>اجباری</th><th>توضیح</th></tr></thead>
                                <tbody>
                                <?php foreach ($doc['params'] as $param): ?>
                                    <tr>
                                        <td dir="ltr" class="code"><?= e($param['name']) ?></td>
                                        <td dir="ltr" class="code"><?= e($param['type']) ?></td>
                                        <td><?= e($param['required']) ?></td>
                                        <td><?= e($param['desc']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php endif; ?>

                        <div class="docs-label">نمونه‌ی درخواست</div>
                        <?= $code($curl($route, $doc)) ?>

                        <?php if ($doc['body'] !== null && $doc['body'] !== ''): ?>
                            <div class="docs-label">بدنه‌ی درخواست</div>
                            <?= $code((string) $doc['body']) ?>
                        <?php endif; ?>

                        <div class="docs-label">پاسخ</div>
                        <?= $code((string) $doc['response']) ?>

                        <?php if ($doc['note'] !== null && $doc['note'] !== ''): ?>
                            <div class="flash flash-info small"><?= e((string) $doc['note']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="card">
        <header class="card-head"><h2>قالبِ خطاها</h2></header>
        <div class="card-body">
            <p class="muted small">
                همه‌ی خطاها همین قالب را دارند؛ در کدِ خودتان همیشه
                <span class="code">error</span> را بررسی کنید، نه فقط کدِ HTTP را.
            </p>
            <?= $code("{\n  \"error\": \"invalid_credentials\",\n  \"message\": \"ایمیل یا رمز عبور اشتباه است.\",\n  \"errors\": { \"email\": \"...\" }\n}") ?>

            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>کد خطا</th><th>HTTP</th><th>علت</th></tr></thead>
                <tbody>
                <?php foreach (ApiDocs::errorCodes() as $err): ?>
                    <tr>
                        <td dir="ltr" class="code"><?= e($err['code']) ?></td>
                        <td><?= e((string) $err['http']) ?></td>
                        <td><?= e($err['desc']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <footer class="card-foot">
            <a class="btn btn-ghost btn-sm" href="<?= e(\sso_url('/admin/docs.php')) ?>?export=postman">دانلود مجموعه‌ی Postman</a>
            <a class="btn btn-ghost btn-sm" href="<?= e(\sso_url('/admin/docs.php')) ?>?export=openapi">دانلود فایل OpenAPI</a>
        </footer>
    </section>

    <?php
    Layout::end();
});
