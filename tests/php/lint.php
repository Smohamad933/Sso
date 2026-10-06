<?php

declare(strict_types=1);

/**
 * بررسی سینتکس تمام فایل‌های PHP پروژه با پارسر واقعی PHP.
 * (token_get_all با TOKEN_PARSE فایل را اجرا نمی‌کند، فقط parse می‌کند)
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$root = SSO_ROOT;
$errors = [];
$count = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, '/.git/') || str_contains($path, '/node_modules/')) {
        continue;
    }
    $count++;
    try {
        token_get_all((string) file_get_contents($path), TOKEN_PARSE);
    } catch (\ParseError $e) {
        $errors[] = [
            'file' => str_replace($root . '/', '', $path),
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
        ];
    }
}

echo "\n=====SSO_TEST_RESULT=====\n";
echo (string) json_encode(['checked' => $count, 'errors' => $errors], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
