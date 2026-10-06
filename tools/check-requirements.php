<?php

declare(strict_types=1);

/**
 * ابزار عیب‌یابیِ پیش‌نیازها — مخصوصِ سرور (ویندوز/IIS).
 *
 * استفاده:
 *   php tools/check-requirements.php
 *   php tools/check-requirements.php --host=127.0.0.1 --port=3306 --database=sso \
 *        --username=root --password=...
 *
 * این اسکریپت هیچ چیزی تغییر نمی‌دهد؛ فقط گزارش می‌دهد.
 * اگر مرحله‌ی ۱ یا ۲ نصاب وب رد نمی‌شود، خروجیِ این ابزار را بفرستید.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Sso\Install\Installer;

if (!sso_is_cli()) {
    fwrite(STDERR, "این اسکریپت فقط از خط فرمان اجرا می‌شود.\n");
    exit(1);
}

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $arg = substr($arg, 2);
    if (str_contains($arg, '=')) {
        [$key, $value] = explode('=', $arg, 2);
        $options[$key] = $value;
    } else {
        $options[$arg] = true;
    }
}

$line = static fn(string $text = ''): string => $text . PHP_EOL;
$out = static function (string $text = '') use ($line): void {
    echo $line($text);
};

$out(str_repeat('=', 70));
$out('  گزارش پیش‌نیازهای سامانه احراز هویت یکپارچه (SSO)');
$out(str_repeat('=', 70));
$out();

// ---------------------------------------------------------------- محیط
$out('۱. محیط اجرا');
$out('   نسخه PHP        : ' . PHP_VERSION . '  (SAPI: ' . PHP_SAPI . ')');
$out('   سیستم‌عامل       : ' . PHP_OS_FAMILY . ' / ' . php_uname('s') . ' ' . php_uname('r'));
$ini = php_ini_loaded_file();
$out('   php.ini بارگذاری: ' . ($ini !== false ? $ini : 'نامشخص'));
$extraIni = php_ini_scanned_files();
if (is_string($extraIni) && $extraIni !== '') {
    $out('   فایل‌های الحاقی  : ' . str_replace("\n", ' ', $extraIni));
}
$out('   کاربر جاری PHP  : ' . get_current_user() . '  (uid=' . (function_exists('posix_getuid') ? (string) posix_getuid() : 'n/a') . ')');
$out('   ریشه پروژه      : ' . SSO_ROOT);
$out('   پوشه عمومی      : ' . SSO_PUBLIC);
$out();

// ---------------------------------------------------------------- پیش‌نیازها
$out('۲. بررسی پیش‌نیازها');
$failed = 0;
foreach (Installer::requirements() as $check) {
    $mark = $check['ok'] ? '[OK]  ' : '[خطا] ';
    if (!$check['ok']) {
        $failed++;
    }
    $out('   ' . $mark . $check['name'] . ' — ' . $check['message']);
    if (!empty($check['detail'])) {
        $out('         جزئیات: ' . $check['detail']);
    }
    if (!$check['ok'] && !empty($check['hint'])) {
        $out('         راه حل: ' . $check['hint']);
    }
}
$out();

// ---------------------------------------------------------------- دیتابیس (اختیاری)
if (isset($options['host'])) {
    $out('۳. تست اتصال به دیتابیس');
    $dbConfig = [
        'driver' => (string) ($options['driver'] ?? 'mysql'),
        'host' => (string) $options['host'],
        'port' => (int) ($options['port'] ?? 3306),
        'database' => (string) ($options['database'] ?? ''),
        'username' => (string) ($options['username'] ?? ''),
        'password' => (string) ($options['password'] ?? ''),
        'charset' => (string) ($options['charset'] ?? 'utf8mb4'),
        'unix_socket' => null,
        'path' => $options['path'] ?? null,
    ];

    $result = Installer::testConnection($dbConfig);
    $out('   درایور : ' . $dbConfig['driver']);
    $out('   میزبان : ' . $dbConfig['host'] . ':' . $dbConfig['port']);
    $out('   نتیجه  : ' . ($result['ok'] ? 'اتصال برقرار شد' : 'اتصال ناموفق'));
    if (!empty($result['message'])) {
        $out('   پیام   : ' . (string) $result['message']);
    }
    $out();
}

// ---------------------------------------------------------------- خلاصه
$out(str_repeat('-', 70));
if ($failed === 0) {
    $out('  همه‌ی پیش‌نیازها برآورده شده‌اند.');
    $out('  اگر مرحله‌ی ۱ نصاب وب همچنان رد می‌شود، مرورگر را با Ctrl+F5 تازه کنید:');
    $out('  ممکن است نتیجه‌ی قدیمی در حافظه‌ی PHP (opcache) مانده باشد.');
} else {
    $out("  {$failed} مورد برآورده نشده است. راه حلِ هر مورد بالا نوشته شده.");
    $out('  پس از اصلاح، IIS را بازیابی (Recycle) کنید و دوباره اجرا بگیرید.');
}
$out(str_repeat('=', 70));
