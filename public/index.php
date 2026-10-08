<?php

declare(strict_types=1);

/**
 * ریشه‌ی سایت — هدایت به نصب‌کننده یا پنل مدیریت.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Sso\Support\Security;

if (Security::enforceHttps()) {
    return;
}
Security::sendHeaders(['X-Robots-Tag' => 'noindex, nofollow']);

$script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$base = rtrim(str_replace('\\', '/', dirname($script)), '/');
if ($base === '.') {
    $base = '';
}

$target = is_file(SSO_CONFIG_FILE) ? '/admin/' : '/setup.php';

header('Location: ' . $base . $target, true, 302);
// return به‌جای exit، تا در محیط‌های embed (تست/پیش‌نمایش) فرایند زنده بماند
return;
