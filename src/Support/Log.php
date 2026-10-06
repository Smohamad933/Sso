<?php

declare(strict_types=1);

namespace Sso\Support;

/**
 * لاگر ساده‌ی مبتنی بر فایل.
 */
final class Log
{
    public static function write(string $level, string $message, array $context = []): void
    {
        $dir = SSO_LOG_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::write('critical', $message, $context);
    }
}
