<?php

declare(strict_types=1);

namespace Sso\Admin;

use Sso\Api\ApiException;
use Sso\Http\HttpException;
use Sso\Support\Log;

/**
 * اجرای یک صفحه‌ی پنل مدیریت.
 *
 * تمام صفحات به جای exit() از استثنا استفاده می‌کنند تا پاسخ
 * در یک نقطه و به صورت کنترل‌شده ارسال شود.
 */
final class Page
{
    /**
     * @param callable(): void $body
     */
    public static function run(callable $body): void
    {
        try {
            $body();
        } catch (HttpException $e) {
            $e->response->send();
        } catch (ApiException $e) {
            self::renderError($e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('admin page: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            self::renderError('خطای داخلی سرور.', 500);
        }
    }

    public static function renderError(string $message, int $status = 500): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }
        echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
            . '<body style="font-family:tahoma;text-align:center;padding:80px">'
            . '<h2>' . e((string) $status) . '</h2><p>' . e($message) . '</p>'
            . '<p><a href="' . e(\sso_url('/admin/index.php')) . '">بازگشت به داشبورد</a></p>'
            . '</body></html>';
    }
}
