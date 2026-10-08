<?php

declare(strict_types=1);

namespace Sso\Admin;

use Sso\Http\Session;
use Sso\Support\Csrf;
use Sso\Support\Jalali;

/**
 * قالب پنل مدیریت (HTML/CSS بدون هیچ فریمورکی).
 */
final class Layout
{
    /**
     * @var array<int, array{label: string, url: string, key: string}>
     */
    private static array $nav = [
        ['key' => 'dashboard', 'label' => 'داشبورد', 'url' => '/admin/index.php'],
        ['key' => 'apps', 'label' => 'اپلیکیشن‌ها', 'url' => '/admin/apps.php'],
        ['key' => 'users', 'label' => 'کاربران', 'url' => '/admin/users.php'],
        ['key' => 'tokens', 'label' => 'توکن‌ها', 'url' => '/admin/tokens.php'],
        ['key' => 'audit', 'label' => 'رویدادها', 'url' => '/admin/audit.php'],
        ['key' => 'docs', 'label' => 'مستندات', 'url' => '/admin/docs.php'],
    ];

    public static function begin(string $title, string $active = '', ?array $admin = null): void
    {
        // نشست باید پیش از هر خروجی آغاز شود (برای توکن CSRF)
        Session::start();

        $appName = e((string) \sso_config('app_name', 'سامانه احراز هویت'));
        $assetBase = \sso_url('/assets');
        $version = @filemtime(SSO_PUBLIC . '/assets/app.css') ?: 0;

        echo '<!doctype html>' . "\n";
        echo '<html lang="fa" dir="rtl">' . "\n";
        echo '<head>' . "\n";
        echo '  <meta charset="utf-8">' . "\n";
        echo '  <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        echo '  <meta name="robots" content="noindex, nofollow">' . "\n";
        echo '  <meta name="referrer" content="same-origin">' . "\n";

        // اجبارِ HTTPS و هدرهای امنیتیِ مرورگر (سامانه قرار است روی اینترنت باشد)
        if (\Sso\Support\Security::enforceHttps()) {
            return;
        }
        \Sso\Support\Security::sendHeaders(['X-Robots-Tag' => 'noindex, nofollow']);
        echo '  ' . Csrf::meta() . "\n";
        echo '  <title>' . e($title) . ' — ' . $appName . '</title>' . "\n";
        echo '  <link rel="stylesheet" href="' . e($assetBase . '/app.css?v=' . $version) . '">' . "\n";
        echo '</head>' . "\n";
        echo '<body class="app">' . "\n";

        echo '  <aside class="sidebar">' . "\n";
        echo '    <div class="brand"><span class="brand-mark">SSO</span><span class="brand-text">' . $appName . '</span></div>' . "\n";
        echo '    <nav class="nav">' . "\n";
        foreach (self::$nav as $item) {
            $isActive = $item['key'] === $active ? ' is-active' : '';
            echo '      <a class="nav-item' . $isActive . '" href="' . e(\sso_url($item['url'])) . '">'
                . '<span class="nav-dot"></span>' . e($item['label']) . '</a>' . "\n";
        }
        echo '    </nav>' . "\n";
        echo '    <div class="sidebar-foot">'
            . '<a class="link" href="' . e(\sso_url('/api/v1/health')) . '" target="_blank" rel="noopener">وضعیت API</a>'
            . '</div>' . "\n";
        echo '  </aside>' . "\n";

        echo '  <main class="main">' . "\n";
        echo '    <header class="topbar">' . "\n";
        echo '      <h1 class="page-title">' . e($title) . '</h1>' . "\n";
        echo '      <div class="topbar-user">' . "\n";
        if ($admin !== null) {
            echo '        <span class="who">' . e((string) ($admin['full_name'] ?: $admin['email'])) . '</span>' . "\n";
            echo '        <a class="btn btn-ghost btn-sm" href="' . e(\sso_url('/admin/account.php')) . '">حساب من</a>' . "\n";
            echo '        <form method="post" action="' . e(\sso_url('/admin/logout.php')) . '" class="inline-form">'
                . Csrf::field()
                . '<button type="submit" class="btn btn-ghost btn-sm">خروج</button></form>' . "\n";
        }
        echo '      </div>' . "\n";
        echo '    </header>' . "\n";

        echo '    <div class="content">' . "\n";
        self::flash();
    }

    public static function end(): void
    {
        echo '    </div>' . "\n";
        echo '    <footer class="footer">سامانه احراز هویت یکپارچه — PHP ' . e(PHP_VERSION) . '</footer>' . "\n";
        echo '  </main>' . "\n";
        echo '  <script src="' . e(\sso_url('/assets/app.js')) . '" defer></script>' . "\n";
        echo '</body>' . "\n";
        echo '</html>' . "\n";
    }

    public static function flash(): void
    {
        $types = ['success', 'error', 'warning', 'info'];
        $flashes = Session::getFlash('flash_messages', []);
        if (!is_array($flashes)) {
            $flashes = [];
        }
        foreach ($types as $type) {
            $stored = Session::getFlash($type);
            if (is_string($stored) && $stored !== '') {
                $flashes[] = ['type' => $type, 'message' => $stored];
            }
        }
        if ($flashes === []) {
            return;
        }
        echo '<div class="flash-stack">' . "\n";
        foreach ($flashes as $flash) {
            if (!is_array($flash)) {
                continue;
            }
            $type = (string) ($flash['type'] ?? 'info');
            echo '  <div class="flash flash-' . e($type) . '">' . e((string) ($flash['message'] ?? '')) . '</div>' . "\n";
        }
        echo '</div>' . "\n";
    }

    /**
     * @param array<string, string> $fields
     */
    public static function errorBox(array $fields): void
    {
        if ($fields === []) {
            return;
        }
        echo '<div class="flash flash-error"><ul class="tight">' . "\n";
        foreach ($fields as $message) {
            echo '  <li>' . e($message) . '</li>' . "\n";
        }
        echo '</ul></div>' . "\n";
    }

    public static function badge(string $text, string $tone = 'neutral'): string
    {
        return '<span class="badge badge-' . e($tone) . '">' . e($text) . '</span>';
    }

    public static function statusBadge(string $status): string
    {
        $map = [
            'active' => ['فعال', 'success'],
            'suspended' => ['مسدود', 'danger'],
            'pending' => ['در انتظار تأیید', 'warning'],
            'disabled' => ['غیرفعال', 'danger'],
        ];
        [$label, $tone] = $map[$status] ?? [$status, 'neutral'];
        return self::badge($label, $tone);
    }

    public static function roleBadge(string $role): string
    {
        $map = [
            'owner' => ['مالک', 'purple'],
            'admin' => ['مدیر', 'info'],
            'member' => ['عضو', 'neutral'],
            'viewer' => ['ناظر', 'neutral'],
        ];
        [$label, $tone] = $map[$role] ?? [$role, 'neutral'];
        return self::badge($label, $tone);
    }

    /**
     * @param array<string, string|int> $extraQuery
     */
    public static function pagination(int $page, int $pages, string $baseUrl, array $extraQuery = []): void
    {
        if ($pages <= 1) {
            return;
        }
        $build = static function (int $p) use ($baseUrl, $extraQuery): string {
            $query = array_merge($extraQuery, ['page' => $p]);
            return $baseUrl . '?' . http_build_query($query);
        };

        echo '<nav class="pagination">' . "\n";
        if ($page > 1) {
            echo '  <a class="page-link" href="' . e($build($page - 1)) . '">‹ قبلی</a>' . "\n";
        }
        $from = max(1, $page - 2);
        $to = min($pages, $from + 4);
        $from = max(1, $to - 4);
        for ($i = $from; $i <= $to; $i++) {
            $cls = $i === $page ? 'page-link is-current' : 'page-link';
            echo '  <a class="' . $cls . '" href="' . e($build($i)) . '">' . $i . '</a>' . "\n";
        }
        if ($page < $pages) {
            echo '  <a class="page-link" href="' . e($build($page + 1)) . '">بعدی ›</a>' . "\n";
        }
        echo '</nav>' . "\n";
    }

    public static function date(?string $datetime): string
    {
        return Jalali::format($datetime);
    }

    public static function human(?string $datetime): string
    {
        return Jalali::humanDiff($datetime);
    }

    public static function card(string $title, string $body, string $footer = ''): string
    {
        $html = '<section class="card"><header class="card-head"><h2>' . e($title) . '</h2></header>'
            . '<div class="card-body">' . $body . '</div>';
        if ($footer !== '') {
            $html .= '<footer class="card-foot">' . $footer . '</footer>';
        }
        return $html . '</section>';
    }
}
