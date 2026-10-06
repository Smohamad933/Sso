<?php

declare(strict_types=1);

/**
 * ساخت/به‌روزرسانی حساب ادمین کل از طریق CLI.
 *
 *   php tools/create-admin.php --email=admin@example.com --password=Secret123 --name="مدیر"
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Sso\Install\Installer;
use Sso\Support\Str;

if (!sso_is_cli()) {
    fwrite(STDERR, "فقط از خط فرمان.\n");
    exit(1);
}

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $options[$key] = $value;
    }
}

$email = Str::normalizeEmail((string) ($options['email'] ?? ''));
$password = (string) ($options['password'] ?? '');
$name = (string) ($options['name'] ?? '');

if (!Str::isEmail($email) || $password === '') {
    fwrite(STDERR, "استفاده: php tools/create-admin.php --email=a@b.com --password=Secret123 [--name=\"مدیر\"]\n");
    exit(1);
}

$error = \Sso\Services\TokenService::passwordError($password);
if ($error !== null) {
    fwrite(STDERR, 'رمز عبور: ' . $error . "\n");
    exit(1);
}

try {
    \Sso\Core\App::boot();
    $user = Installer::createSuperAdmin($email, $password, $name === '' ? null : $name);
    echo 'ادمین آماده است: ' . (string) $user['email'] . ' (id=' . (string) $user['id'] . ")\n";
    // return به‌جای exit: در حالت عادیِ CLI تفاوتی ندارد (پایان اسکریپت با کد ۰)،
    // اما امکان فراخوانیِ برنامه‌ای (تست یکپارچه) را هم فراهم می‌کند.
    return;
} catch (\Throwable $e) {
    fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
    exit(1);
}
