<?php

declare(strict_types=1);

/**
 * ساخت اپلیکیشن جدید و چاپ کلیدهای API.
 *
 *   php tools/create-app.php --name="فروشگاه من" [--slug=my-shop] [--description="..."]
 */

require dirname(__DIR__) . '/src/bootstrap.php';

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

$name = trim((string) ($options['name'] ?? ''));
if ($name === '') {
    fwrite(STDERR, "استفاده: php tools/create-app.php --name=\"فروشگاه من\" [--slug=my-shop]\n");
    exit(1);
}

try {
    \Sso\Core\App::boot();
    $result = \sso_app()->apps()->create($name, $options['slug'] ?? null, $options['description'] ?? null);

    echo "اپلیکیشن ساخته شد:\n";
    echo '  id:         ' . (string) $result['app']['id'] . "\n";
    echo '  slug:       ' . (string) $result['app']['slug'] . "\n";
    echo '  API Key:    ' . $result['api_key'] . "\n";
    echo '  API Secret: ' . $result['api_secret'] . "\n";
    echo "\nاین مقادیر فقط یک‌بار نمایش داده می‌شوند.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
    exit(1);
}
