<?php

declare(strict_types=1);

/**
 * بررسی قواعد معماری پروژه (بدون نیاز به وب‌سرور).
 *
 * تنها استثنای قاعده‌ی «بدون exit» فایل src/bootstrap.php است: پردازشگر
 * استثنای کنترل‌نشده در حالت CLI باید کد خروجِ غیرصفر برگرداند و چاره‌ای
 * جز exit ندارد. آن مسیر با PHP_SAPI === 'cli' محافظت شده است.
 *
 * ورودی:  بدون ورودی (مسیرها از ثابت‌های bootstrap گرفته می‌شوند)
 * خروجی:  یک بلوک JSON بعد از نشانگر SSO_ARCH_RESULT
 *
 * قواعد بررسی‌شده:
 *  1. در هیچ رشته‌ی SQL ی از توابع زمان/تاریخ SQL استفاده نشده باشد.
 *  2. هیچ upsert ی (ON DUPLICATE KEY / INSERT IGNORE / REPLACE INTO) نباشد.
 *  3. در مسیرهای وب (public و src) هیچ exit/die ی نباشد.
 *  4. هیچ رشته‌ی SQL ی شامل متغیر نباشد (همه‌ی مقادیر باید bind شوند).
 *  5. در src هیچ خروجیِ اشکال‌زدایی (var_dump/print_r) نباشد.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

/** الگوهای ممنوع درون رشته‌های SQL */
$sqlPatterns = [
    'sql_now'           => '/(?<![A-Za-z0-9_:>$])NOW\s*\(\s*\)/i',
    'sql_current_ts'    => '/CURRENT_TIMESTAMP/i',
    'sql_utc_ts'        => '/UTC_TIMESTAMP/i',
    'sql_curdate'       => '/\bCURDATE\s*\(/i',
    'sql_unix_ts'       => '/\bUNIX_TIMESTAMP\s*\(/i',
    'sql_from_unix'     => '/\bFROM_UNIXTIME\s*\(/i',
    'sql_date_add'      => '/\bDATE_(ADD|SUB)\s*\(/i',
    'sql_interval'      => '/\bINTERVAL\s+[\d\']/i',
    'sql_upsert'        => '/ON\s+DUPLICATE\s+KEY/i',
    'sql_insert_ignore' => '/INSERT\s+IGNORE/i',
    'sql_replace_into'  => '/REPLACE\s+INTO/i',
];

/** تشخیص رشته‌ی SQL بودن یک لیترال */
$isSql = static function (string $value): bool {
    return (bool) preg_match(
        '/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE\s+TABLE|ALTER\s+TABLE|DROP|TRUNCATE)\b/i',
        $value
    );
};

/**
 * استخراج لیترال‌های رشته‌ای یک فایل با token_get_all.
 *
 * @return array<int, array{value: string, line: int}>
 */
$stringLiterals = static function (string $file): array {
    try {
        $tokens = token_get_all(file_get_contents($file), TOKEN_PARSE);
    } catch (\Throwable $e) {
        // خطای نحو در lint.php گزارش می‌شود؛ اینجا فقط از شکست کل بررسی جلوگیری می‌کنیم
        return [['value' => '/*syntax*/' . $e->getMessage(), 'line' => 0, 'raw' => false]];
    }
    $out = [];
    $line = 1;
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            $line += substr_count((string) $token, "\n");
            continue;
        }
        [$id, $text, $tokenLine] = $token;
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $out[] = ['value' => $text, 'line' => $tokenLine, 'raw' => true];
        } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
            $out[] = ['value' => $text, 'line' => $tokenLine, 'raw' => false];
        } elseif ($id === T_EXIT) {
            $out[] = ['value' => '/*exit*/' . $text, 'line' => $tokenLine, 'raw' => false];
        }
        $line = $tokenLine;
    }
    return $out;
};

/**
 * @return array<int, string>
 */
$phpFiles = static function (string $dir): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
};

$violations = [];
$checkedStrings = 0;
$checkedFiles = 0;

foreach ([SSO_SRC, SSO_PUBLIC] as $base) {
    foreach ($phpFiles($base) as $file) {
        $checkedFiles++;
        $relative = str_replace(str_replace('\\', '/', SSO_ROOT) . '/', '', str_replace('\\', '/', $file));

        foreach ($stringLiterals($file) as $literal) {
            $value = $literal['value'];

            // exit / die در مسیر وب ممنوع است (runtime تست را از بین می‌برد)
            if (str_starts_with($value, '/*syntax*/')) {
                $violations[] = [
                    'rule' => 'syntax_error',
                    'file' => $relative,
                    'line' => $literal['line'],
                    'detail' => substr($value, 10),
                ];
                continue;
            }

            if (str_starts_with($value, '/*exit*/') && $relative !== 'src/bootstrap.php') {
                $violations[] = [
                    'rule' => 'no_exit',
                    'file' => $relative,
                    'line' => $literal['line'],
                    'detail' => 'استفاده از exit/die در مسیر وب ممنوع است.',
                ];
                continue;
            }

            if (!$isSql($value)) {
                continue;
            }
            $checkedStrings++;

            foreach ($sqlPatterns as $rule => $pattern) {
                if (preg_match($pattern, $value)) {
                    $violations[] = [
                        'rule' => $rule,
                        'file' => $relative,
                        'line' => $literal['line'],
                        'detail' => trim(substr($value, 0, 160)),
                    ];
                }
            }

            // همه‌ی مقادیر باید به صورت پارامتر bind شوند؛ در رشته‌ی SQL نباید متغیری باشد
            if ($literal['raw'] === false && str_contains($value, '$')) {
                $violations[] = [
                    'rule' => 'no_interpolation',
                    'file' => $relative,
                    'line' => $literal['line'],
                    'detail' => trim(substr($value, 0, 160)),
                ];
            }
        }
    }
}

// خروجی اشکال‌زدایی در src ممنوع است (var_export در Installer مجاز است)
foreach ($phpFiles(SSO_SRC) as $file) {
    $source = file_get_contents($file);
    $relative = str_replace(str_replace('\\', '/', SSO_ROOT) . '/', '', str_replace('\\', '/', $file));
    foreach (['var_dump', 'print_r', 'echo_r'] as $fn) {
        if (preg_match('/(?<![A-Za-z0-9_>])' . preg_quote($fn, '/') . '\s*\(/i', $source, $m, PREG_OFFSET_CAPTURE)) {
            $violations[] = [
                'rule' => 'no_debug_output',
                'file' => $relative,
                'line' => substr_count(substr($source, 0, (int) $m[0][1]), "\n") + 1,
                'detail' => $fn . '()',
            ];
        }
    }
}

echo "\n=====SSO_TEST_RESULT=====\n";
echo (string) json_encode([
    'checked_files' => $checkedFiles,
    'checked_sql_strings' => $checkedStrings,
    'violations' => $violations,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
