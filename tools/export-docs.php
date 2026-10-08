<?php

declare(strict_types=1);

/**
 * خروجی گرفتن از مستنداتِ API برای تحویل به برنامه‌نویس.
 *
 * سه فایل می‌سازد:
 *   sso-docs.html                      یک فایلِ مستقل؛ با مرورگر باز می‌شود،
 *                                      چاپ می‌شود و می‌توان آن را به PDF تبدیل کرد.
 *   sso-api.postman_collection.json     وارد کردن مستقیم در Postman / Insomnia
 *   sso-api.openapi.json                سند OpenAPI ۳ برای ابزارهای مختلف
 *
 * استفاده:
 *   php tools/export-docs.php
 *   php tools/export-docs.php --out=C:\temp\docs
 *
 * هیچ چیزی را تغییر نمی‌دهد؛ فقط فایل می‌نویسد.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Sso\Support\ApiDocs;

if (!sso_is_cli()) {
    \fwrite(STDOUT, 'این ابزار فقط از خط فرمان اجرا می‌شود.' . PHP_EOL);
    exit(1);
}

// ------------------------------------------------------------------ آرگومان‌ها
$outDir = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    }
}
if ($outDir === null || $outDir === '') {
    $outDir = SSO_STORAGE . '/export';
}
$outDir = rtrim((string) $outDir, '/\\');

if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    \fwrite(STDERR, 'نمی‌توان پوشه‌ی خروجی را ساخت: ' . $outDir . PHP_EOL);
    exit(1);
}

// ------------------------------------------------------------------ داده‌ها
$base = '';
if (is_file(SSO_CONFIG_FILE)) {
    \Sso\Core\App::boot();
    $base = rtrim((string) \sso_config('base_url', ''), '/');
}
if ($base === '') {
    // نصب انجام نشده است؛ باز هم مستندات را می‌سازیم اما با نشانیِ نمونه
    // تا ابزار در هر حالتی قابل استفاده باشد.
    $base = 'https://YOUR-DOMAIN';
    \fwrite(STDOUT, 'هشدار: سامانه هنوز نصب نشده است؛ نشانی پایه با یک مقدار نمونه پر می‌شود.' . PHP_EOL . PHP_EOL);
}
$apiBase = $base . '/api/v1';

/** @var \Sso\Api\Router $router */
$router = require dirname(__DIR__) . '/src/Api/routes.php';
$routes = $router->routes();
$samples = ApiDocs::filledSamples($apiBase);
$groups = ApiDocs::groups();
$modes = ApiDocs::authModes();

// ------------------------------------------------------------------ رندرِ HTML
/**
 * خروجیِ امن برای HTML.
 */
$esc = static fn(mixed $value): string => \htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$endpointHtml = '';
$grouped = [];
foreach ($routes as $route) {
    $doc = ApiDocs::describe((string) $route['method'], (string) $route['path']);
    $grouped[$doc['group']][] = [$route, $doc];
}

foreach ($groups as $groupKey => $groupLabel) {
    if (!isset($grouped[$groupKey])) {
        continue;
    }
    $endpointHtml .= '<h2 id="group-' . $esc($groupKey) . '">' . $esc($groupLabel) . '</h2>' . "\n";
    foreach ($grouped[$groupKey] as $pair) {
        [$route, $doc] = $pair;
        $method = (string) $route['method'];
        $path = (string) $route['path'];
        $mode = (string) ($route['auth'] ?? 'public');

        $endpointHtml .= '<div class="endpoint">' . "\n";
        $endpointHtml .= '  <div class="ep-head">'
            . '<span class="method m-' . $esc(strtolower($method)) . '">' . $esc($method) . '</span>'
            . '<code class="path">' . $esc($path) . '</code>'
            . '<span class="pill">' . $esc($modes[$mode]['label'] ?? 'عمومی') . '</span>'
            . '</div>' . "\n";
        $endpointHtml .= '  <h3>' . $esc($doc['title']) . '</h3>' . "\n";
        $endpointHtml .= '  <p>' . $esc($doc['summary']) . '</p>' . "\n";

        if ($doc['params'] !== []) {
            $endpointHtml .= "  <table>\n    <thead><tr><th>فیلد</th><th>نوع</th><th>اجباری</th><th>توضیح</th></tr></thead>\n    <tbody>\n";
            foreach ($doc['params'] as $param) {
                $endpointHtml .= '      <tr><td><code dir="ltr">' . $esc($param['name']) . '</code></td>'
                    . '<td><code dir="ltr">' . $esc($param['type']) . '</code></td>'
                    . '<td>' . $esc($param['required']) . '</td>'
                    . '<td>' . $esc($param['desc']) . '</td></tr>' . "\n";
            }
            $endpointHtml .= "    </tbody>\n  </table>\n";
        }

        $endpointHtml .= '  <div class="label">درخواست</div>' . "\n";
        $endpointHtml .= '  <pre dir="ltr">' . $esc(curlFor($route, $doc, $apiBase)) . "</pre>\n";

        if ($doc['body'] !== null && $doc['body'] !== '') {
            $endpointHtml .= '  <div class="label">بدنه</div>' . "\n";
            $endpointHtml .= '  <pre dir="ltr">' . $esc((string) $doc['body']) . "</pre>\n";
        }

        $endpointHtml .= '  <div class="label">پاسخ</div>' . "\n";
        $endpointHtml .= '  <pre dir="ltr">' . $esc((string) $doc['response']) . "</pre>\n";

        if ($doc['note'] !== null && $doc['note'] !== '') {
            $endpointHtml .= '  <p class="note">' . $esc((string) $doc['note']) . "</p>\n";
        }
        $endpointHtml .= "</div>\n";
    }
}

/**
 * نمونه‌ی cURL برای یک مسیر (همان منطقِ صفحه‌ی پنل).
 */
function curlFor(array $route, array $doc, string $apiBase): string
{
    $method = (string) $route['method'];
    $mode = (string) ($route['auth'] ?? 'public');
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
        $lines[] = "  -d '" . (string) preg_replace('/\s+/', ' ', (string) $doc['body']) . "'";
    }

    $count = count($lines);
    $out = '';
    foreach ($lines as $i => $line) {
        $out .= $line . ($i < $count - 1 ? " \\\n" : "\n");
    }
    return rtrim($out);
}

$modeRows = '';
foreach ($modes as $key => $mode) {
    $headers = $mode['headers'] === [] ? '—' : implode('، ', $mode['headers']);
    $modeRows .= '      <tr><td><code dir="ltr">' . $esc($key) . '</code></td>'
        . '<td><strong>' . $esc($mode['label']) . '</strong><br>' . $esc($mode['desc']) . '</td>'
        . '<td><code dir="ltr">' . $esc($headers) . '</code></td></tr>' . "\n";
}

$errorRows = '';
foreach (ApiDocs::errorCodes() as $err) {
    $errorRows .= '      <tr><td><code dir="ltr">' . $esc($err['code']) . '</code></td>'
        . '<td>' . $esc((string) $err['http']) . '</td>'
        . '<td>' . $esc($err['desc']) . '</td></tr>' . "\n";
}

$generatedAt = date('Y-m-d H:i');
$routeCount = count($routes);

$html = <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>مستنداتِ API — سامانه احراز هویت یکپارچه</title>
<style>
  :root { --line: #e3e7f0; --muted: #6b7490; --brand: #4f46e5; --bg: #f4f6fb; }
  * { box-sizing: border-box; }
  body {
    font-family: "Segoe UI", Tahoma, "Vazirmatn", Arial, sans-serif;
    margin: 0; padding: 0 0 60px;
    background: var(--bg); color: #1c2333; font-size: 14px; line-height: 1.9;
  }
  .wrap { max-width: 1000px; margin: 0 auto; padding: 0 20px; }
  header.top { background: #171c2e; color: #fff; padding: 30px 0; margin-bottom: 28px; }
  header.top h1 { margin: 0 0 6px; font-size: 22px; }
  header.top p { margin: 0; opacity: .8; font-size: 13px; }
  section, .endpoint {
    background: #fff; border: 1px solid var(--line);
    border-radius: 12px; padding: 20px 22px; margin-bottom: 18px;
  }
  h2 { font-size: 18px; margin: 34px 0 14px; color: #171c2e; }
  h3 { font-size: 15px; margin: 14px 0 6px; }
  table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; font-size: 13px; }
  th, td { border: 1px solid var(--line); padding: 7px 10px; text-align: right; vertical-align: top; }
  th { background: var(--bg); font-weight: 600; }
  code { font-family: "Cascadia Mono", Consolas, monospace; font-size: 12.5px; }
  pre {
    background: #f7f8fc; border: 1px solid var(--line); border-radius: 8px;
    padding: 12px 14px; overflow-x: auto; font-size: 12.5px; line-height: 1.7;
    font-family: "Cascadia Mono", Consolas, monospace;
  }
  .ep-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .method {
    color: #fff; padding: 3px 9px; border-radius: 6px;
    font-size: 11px; font-weight: 700; background: var(--muted);
  }
  .m-get { background: #0f9d58; }
  .m-post { background: #175cd3; }
  .m-patch { background: #b54708; }
  .m-delete { background: #d92d20; }
  .path { font-weight: 600; font-size: 13px; }
  .pill { background: #eef0ff; color: var(--brand); padding: 2px 9px; border-radius: 20px; font-size: 11px; }
  .label { margin: 14px 0 5px; font-size: 12px; font-weight: 600; color: var(--muted); }
  .note { background: #e8f0fe; border-radius: 8px; padding: 10px 14px; font-size: 13px; }
  .warn { background: #fdf1e3; border-radius: 8px; padding: 12px 16px; font-size: 13px; }
  .muted { color: var(--muted); }
  .kv { margin-bottom: 8px; }
  footer { text-align: center; color: var(--muted); font-size: 12px; margin-top: 30px; }

  @media print {
    body { background: #fff; font-size: 11.5px; }
    header.top { background: #171c2e !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    section, .endpoint { break-inside: avoid; border-color: #ccc; }
    pre { white-space: pre-wrap; word-break: break-word; }
  }
</style>
</head>
<body>
<header class="top">
  <div class="wrap">
    <h1>مستنداتِ API — سامانه احراز هویت یکپارچه</h1>
    <p>راهنمای اتصالِ اپلیکیشن شما به سامانه‌ی احراز هویت — {$routeCount} مسیر — تولیدشده در {$generatedAt}</p>
  </div>
</header>

<div class="wrap">

<section>
  <h2 style="margin-top:0">۱. نشانی پایه و کلیدها</h2>
  <div class="kv"><strong>نشانی پایه‌ی API:</strong> <code dir="ltr">{$apiBase}</code></div>
  <p>برای دریافتِ <strong>کلید</strong> و <strong>رازِ</strong> اپلیکیشن، مدیرِ سامانه باید در پنل مدیریت
  یک اپلیکیشن بسازد. کلید و راز فقط یک‌بار نمایش داده می‌شوند.</p>
  <table>
    <thead><tr><th>نوع اعتبار</th><th>نحوه‌ی ارسال</th><th>کجا استفاده شود</th></tr></thead>
    <tbody>
      <tr><td><strong>کلید و رازِ اپلیکیشن</strong></td>
          <td><code dir="ltr">X-Api-Key</code> / <code dir="ltr">X-Api-Secret</code></td>
          <td>فقط سرور به سرور: مدیریت کاربران، ثبت‌نام، ورود، آمار.</td></tr>
      <tr><td><strong>توکنِ کاربر</strong></td>
          <td><code dir="ltr">Authorization: Bearer …</code></td>
          <td>وقتی خودِ کاربر وارد شده و برنامه به نیابت از او کار می‌کند.</td></tr>
    </tbody>
  </table>
  <div class="warn"><strong>هشدار امنیتی:</strong> رازِ اپلیکیشن را هرگز در کدِ جاوااسکریپتِ مرورگر،
  اپلیکیشن موبایل یا مخزن عمومی قرار ندهید. کلیدها فقط روی سرورِ خودتان باشند.</div>
</section>

<section>
  <h2 style="margin-top:0">۲. شروع سریع — cURL</h2>
  <pre dir="ltr">{$samples['curl']}</pre>
</section>

<section>
  <h2 style="margin-top:0">۳. شروع سریع — PHP</h2>
  <pre dir="ltr">{$samples['php']}</pre>
</section>

<section>
  <h2 style="margin-top:0">۴. شروع سریع — JavaScript</h2>
  <pre dir="ltr">{$samples['js']}</pre>
</section>

<section>
  <h2 style="margin-top:0">۵. معنای حالت‌های احراز هویت</h2>
  <table>
    <thead><tr><th>حالت</th><th>یعنی چه</th><th>هدرهای لازم</th></tr></thead>
    <tbody>
{$modeRows}    </tbody>
  </table>
</section>

<h2>۶. مسیرها</h2>
{$endpointHtml}
<h2>۷. خطاها</h2>
<section>
  <p>همه‌ی خطاها همین قالب را دارند؛ در کدِ خودتان همیشه
  <code dir="ltr">error</code> را بررسی کنید، نه فقط کدِ HTTP را.</p>
  <pre dir="ltr">{
  "error": "invalid_credentials",
  "message": "ایمیل یا رمز عبور اشتباه است.",
  "errors": { "email": "..." }
}</pre>
  <table>
    <thead><tr><th>کد خطا</th><th>HTTP</th><th>علت</th></tr></thead>
    <tbody>
{$errorRows}    </tbody>
  </table>
</section>

<footer>
  این سند به‌طور خودکار از روی کدِ سامانه ساخته شده است، بنابراین همیشه با نسخه‌ی در حال اجرا هماهنگ است.
</footer>

</div>
</body>
</html>
HTML;

// ------------------------------------------------------------------ نوشتن
$files = [
    'sso-docs.html' => $html,
    'sso-api.postman_collection.json' => (string) \json_encode(
        ApiDocs::postmanCollection($routes, $apiBase),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ),
    'sso-api.openapi.json' => (string) \json_encode(
        ApiDocs::openapiDocument($routes, $base),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ),
];

echo 'پوشه‌ی خروجی: ' . $outDir . PHP_EOL . PHP_EOL;
foreach ($files as $name => $content) {
    $target = $outDir . '/' . $name;
    $ok = @\file_put_contents($target, $content);
    if ($ok === false) {
        \fwrite(STDERR, 'خطا در نوشتن: ' . $target . PHP_EOL);
        exit(1);
    }
    echo '  ✓ ' . $name . '  (' . number_format((int) $ok) . ' بایت)' . PHP_EOL;
}

echo PHP_EOL . 'این فایل‌ها را می‌توانید مستقیماً برای برنامه‌نویس بفرستید.' . PHP_EOL;
