<?php

declare(strict_types=1);

namespace Sso\Support;

/**
 * کاتالوگِ مستنداتِ API.
 *
 * چرا این‌جا یک کلاس است و نه یک فایلِ متنی؟
 * چون «فهرست مسیرها» از روی جدولِ مسیرهای واقعی (src/Api/routes.php) تولید
 * می‌شود و متدِ missing() بررسی می‌کند که آیا برای تک‌تکِ آن‌ها توضیح نوشته
 * شده است یا نه. با این کار، اگر مسیری به برنامه اضافه شود و مستندش نوشته
 * نشود، تست خطا می‌دهد — یعنی مستنداتِ داخل پنل هیچ‌وقت از کد عقب نمی‌ماند.
 *
 * این کلاس فقط داده برمی‌گرداند و هیچ کاری انجام نمی‌دهد (بدون I/O).
 */
final class ApiDocs
{
    /** فایلِ دروازه‌ی API در پوشه‌ی عمومی */
    public const GATEWAY = '/api/index.php';

    /**
     * گروه‌های مستندات.
     *
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            'health' => 'سلامت سامانه',
            'auth' => 'احراز هویت',
            'users' => 'مدیریت کاربران (قابلِ تفویض)',
            'me' => 'کاربرِ جاری',
            'apps' => 'اپلیکیشن جاری',
        ];
    }

    /**
     * معنای حالت‌های احراز هویت که در جدولِ مسیرها دیده می‌شود.
     *
     * @return array<string, array{label: string, desc: string, headers: array<int, string>}>
     */
    public static function authModes(): array
    {
        return [
            'public' => [
                'label' => 'عمومی',
                'desc' => 'هیچ کلیدی لازم نیست.',
                'headers' => [],
            ],
            'app' => [
                'label' => 'کلید اپلیکیشن',
                'desc' => 'فقط از سمتِ سرور صدا زده شود؛ کلید و رازِ اپ را نباید در مرورگر یا اپِ موبایل قرار داد.',
                'headers' => ['X-Api-Key', 'X-Api-Secret'],
            ],
            'optional_app' => [
                'label' => 'کلید اپلیکیشن (اختیاری)',
                'desc' => 'با یا بدون کلید کار می‌کند؛ اگر کلید بفرستید، توکنِ جدید به همان اپ محدود می‌شود.',
                'headers' => ['X-Api-Key (اختیاری)', 'X-Api-Secret (اختیاری)'],
            ],
            'user' => [
                'label' => 'توکن کاربر',
                'desc' => 'کاربر باید قبلاً وارد شده باشد و توکنِ دسترسی را بفرستد.',
                'headers' => ['Authorization: Bearer <access_token>'],
            ],
            'app_or_user' => [
                'label' => 'کلید اپ یا توکن کاربر',
                'desc' => 'هر کدام را بفرستید پاسخ می‌گیرید؛ برای بررسیِ اعتبارِ توکن از سمتِ سرور مناسب است.',
                'headers' => ['X-Api-Key', 'X-Api-Secret'],
            ],
        ];
    }

    /**
     * توضیحِ هر مسیر. کلیدِ هر مدخل دقیقاً «METHOD path» است،
     * همان‌طور که Router::routes() برمی‌گرداند.
     *
     * @return array<string, array{group: string, title: string, summary: string, params: array<int, array<string, string>>, body: string|null, response: string, note: string|null}>
     */
    public static function catalog(): array
    {
        return [
            // ---------------------------------------------------------- سلامت
            'GET /v1/health' => [
                'group' => 'health',
                'title' => 'وضعیت سامانه',
                'summary' => 'برای بررسیِ بالا بودنِ سرویس و اتصال به دیتابیس. نیازی به هیچ کلیدی ندارد.',
                'params' => [],
                'body' => null,
                'response' => '{
  "status": "ok",
  "time": "2026-10-08 12:00:00"
}',
                'note' => 'اگر دیتابیس در دسترس نباشد، وضعیت "error" برمی‌گردد.',
            ],

            // ------------------------------------------------------ احراز هویت
            'POST /v1/auth/register' => [
                'group' => 'auth',
                'title' => 'ثبت‌نام کاربر',
                'summary' => 'یک کاربرِ جدید می‌سازد و بلافاصله به این اپلیکیشن متصلش می‌کند و توکن برمی‌گرداند.',
                'params' => [
                    ['name' => 'email', 'type' => 'string', 'required' => 'بله', 'desc' => 'ایمیل کاربر (یکتا در کل سامانه)'],
                    ['name' => 'password', 'type' => 'string', 'required' => 'بله', 'desc' => 'حداقل ۸ کاراکتر'],
                    ['name' => 'full_name', 'type' => 'string', 'required' => 'خیر', 'desc' => 'نام کامل'],
                    ['name' => 'phone', 'type' => 'string', 'required' => 'خیر', 'desc' => 'شماره تماس'],
                    ['name' => 'avatar_url', 'type' => 'string', 'required' => 'خیر', 'desc' => 'نشانی تصویر'],
                    ['name' => 'role', 'type' => 'string', 'required' => 'خیر', 'desc' => 'یکی از owner / admin / member / viewer (پیش‌فرض member)'],
                    ['name' => 'metadata', 'type' => 'object', 'required' => 'خیر', 'desc' => 'داده‌ی دلخواه شما برای این کاربر'],
                ],
                'body' => '{
  "email": "ali@example.com",
  "password": "YekRamez123",
  "full_name": "علی رضایی",
  "role": "member"
}',
                'response' => '{
  "user": { "id": 12, "email": "ali@example.com", "full_name": "علی رضایی", "status": "active" },
  "membership": { "role": "member", "status": "active" },
  "access_token": "eyJ...",
  "refresh_token": "eyJ...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_expires_in": 2592000
}',
                'note' => 'اگر کاربر از قبل در سامانه وجود داشته باشد، ساخته نمی‌شود؛ فقط به این اپ متصل می‌شود (کد ۲۰۰).',
            ],
            'POST /v1/auth/login' => [
                'group' => 'auth',
                'title' => 'ورود کاربر',
                'summary' => 'با ایمیل و رمز عبور، توکنِ دسترسی و توکنِ تمدید می‌گیرد.',
                'params' => [
                    ['name' => 'email', 'type' => 'string', 'required' => 'بله', 'desc' => 'ایمیل کاربر'],
                    ['name' => 'password', 'type' => 'string', 'required' => 'بله', 'desc' => 'رمز عبور'],
                ],
                'body' => '{
  "email": "ali@example.com",
  "password": "YekRamez123"
}',
                'response' => '{
  "user": { "id": 12, "email": "ali@example.com", "role": "member" },
  "access_token": "eyJ...",
  "refresh_token": "eyJ...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_expires_in": 2592000
}',
                'note' => 'محدودیتِ نرخ دارد: ۱۵ تلاش برای هر ایمیل و ۳۰ تلاش برای هر IP در هر ۱۵ دقیقه.',
            ],
            'POST /v1/auth/refresh' => [
                'group' => 'auth',
                'title' => 'تمدید توکن',
                'summary' => 'با توکنِ تمدید، یک توکنِ دسترسیِ تازه می‌گیرد و کاربر را بی‌نیاز از ورودِ دوباره نگه می‌دارد.',
                'params' => [
                    ['name' => 'refresh_token', 'type' => 'string', 'required' => 'بله', 'desc' => 'توکنِ تمدیدِ گرفته‌شده هنگام ورود'],
                ],
                'body' => '{ "refresh_token": "eyJ..." }',
                'response' => '{
  "access_token": "eyJ...",
  "refresh_token": "eyJ...",
  "token_type": "Bearer",
  "expires_in": 3600
}',
                'note' => 'هر توکنِ تمدید فقط یک‌بار قابل استفاده است. توکنِ تمدیدِ قبلی باطل می‌شود اما نشست‌های دیگرِ کاربر سر جایشان می‌مانند.',
            ],
            'POST /v1/auth/logout' => [
                'group' => 'auth',
                'title' => 'خروج',
                'summary' => 'توکنِ دسترسیِ فعلی را باطل می‌کند.',
                'params' => [],
                'body' => null,
                'response' => '{ "revoked": true }',
                'note' => 'برای خروج از همه‌ی دستگاه‌ها از /v1/me/logout استفاده کنید.',
            ],
            'POST /v1/auth/introspect' => [
                'group' => 'auth',
                'title' => 'بررسی اعتبار توکن',
                'summary' => 'می‌گوید یک توکن معتبر است یا نه و متعلق به کدام کاربر است. برای بررسی از سمتِ سرورِ خودتان.',
                'params' => [
                    ['name' => 'token', 'type' => 'string', 'required' => 'خیر', 'desc' => 'اگر نفرستید، هدرِ Authorization بررسی می‌شود'],
                ],
                'body' => '{ "token": "eyJ..." }',
                'response' => '{
  "active": true,
  "user": { "id": 12, "email": "ali@example.com" },
  "app_id": 3,
  "expires_at": "2026-10-08 13:00:00"
}',
                'note' => 'توکنِ نامعتبر کدِ ۴۰۱ نمی‌دهد؛ با ۲۰۰ و "active": false برمی‌گردد تا برنامه‌تان ساده‌تر شود.',
            ],
            'POST /v1/auth/password/forgot' => [
                'group' => 'auth',
                'title' => 'درخواست بازیابی رمز',
                'summary' => 'یک توکنِ بازیابی برای کاربر می‌سازد.',
                'params' => [
                    ['name' => 'email', 'type' => 'string', 'required' => 'بله', 'desc' => 'ایمیل کاربر'],
                ],
                'body' => '{ "email": "ali@example.com" }',
                'response' => '{ "sent": true, "token": "eyJ..." }',
                'note' => 'برای ایمیلِ ناموجود هم همان پاسخ برمی‌گردد تا وجود حساب فاش نشود. توکن فقط یک‌بار و تا زمانِ انقضا معتبر است.',
            ],
            'POST /v1/auth/password/reset' => [
                'group' => 'auth',
                'title' => 'تغییر رمز با توکنِ بازیابی',
                'summary' => 'با توکنِ بازیابی، رمز عبور را عوض می‌کند و همه‌ی توکن‌های فعالِ کاربر را باطل می‌کند.',
                'params' => [
                    ['name' => 'token', 'type' => 'string', 'required' => 'بله', 'desc' => 'توکنِ دریافت‌شده از forgot'],
                    ['name' => 'password', 'type' => 'string', 'required' => 'بله', 'desc' => 'رمز عبور جدید (حداقل ۸ کاراکتر)'],
                ],
                'body' => '{ "token": "eyJ...", "password": "YekRamezeJadid456" }',
                'response' => '{ "reset": true, "user": { "id": 12, "email": "ali@example.com" } }',
                'note' => 'استفاده‌ی دوباره از همان توکن خطای invalid_reset_token می‌دهد.',
            ],
            'POST /v1/auth/verify-email' => [
                'group' => 'auth',
                'title' => 'تأیید ایمیل کاربر',
                'summary' => 'ایمیلِ کاربر را تأییدشده علامت می‌زند و اگر در انتظار بود، فعالش می‌کند.',
                'params' => [
                    ['name' => 'user_id', 'type' => 'string', 'required' => 'بله', 'desc' => 'شناسه‌ی کاربر'],
                ],
                'body' => '{ "user_id": "12" }',
                'response' => '{ "user": { "id": 12, "email_verified_at": "2026-10-08 12:00:00", "status": "active" } }',
                'note' => null,
            ],

            // ---------------------------------------------------------- کاربران
            'GET /v1/users' => [
                'group' => 'users',
                'title' => 'فهرست کاربرانِ اپ',
                'summary' => 'فقط کاربرانی را برمی‌گرداند که عضوِ این اپلیکیشن هستند، با نقشِ مربوط به همین اپ.',
                'params' => [
                    ['name' => 'q', 'type' => 'string', 'required' => 'خیر', 'desc' => 'جست‌وجو در ایمیل و نام'],
                    ['name' => 'status', 'type' => 'string', 'required' => 'خیر', 'desc' => 'active / suspended / pending'],
                    ['name' => 'role', 'type' => 'string', 'required' => 'خیر', 'desc' => 'owner / admin / member / viewer'],
                    ['name' => 'page', 'type' => 'integer', 'required' => 'خیر', 'desc' => 'شماره صفحه (پیش‌فرض ۱)'],
                    ['name' => 'per_page', 'type' => 'integer', 'required' => 'خیر', 'desc' => 'تعداد در هر صفحه (پیش‌فرض ۲۰، حداکثر ۱۰۰)'],
                ],
                'body' => null,
                'response' => '{
  "data": [ { "id": 12, "email": "ali@example.com", "role": "member", "status": "active" } ],
  "meta": { "page": 1, "per_page": 20, "total": 1, "last_page": 1 }
}',
                'note' => null,
            ],
            'POST /v1/users' => [
                'group' => 'users',
                'title' => 'ساخت کاربر',
                'summary' => 'یک کاربر می‌سازد و به این اپ متصلش می‌کند؛ معادلِ register اما بدون صدور توکن.',
                'params' => [
                    ['name' => 'email', 'type' => 'string', 'required' => 'بله', 'desc' => 'ایمیل کاربر'],
                    ['name' => 'password', 'type' => 'string', 'required' => 'خیر', 'desc' => 'اگر نفرستید، کاربر باید بعداً رمز تعیین کند'],
                    ['name' => 'full_name', 'type' => 'string', 'required' => 'خیر', 'desc' => 'نام کامل'],
                    ['name' => 'phone', 'type' => 'string', 'required' => 'خیر', 'desc' => 'شماره تماس'],
                    ['name' => 'role', 'type' => 'string', 'required' => 'خیر', 'desc' => 'نقش در این اپ (پیش‌فرض member)'],
                    ['name' => 'metadata', 'type' => 'object', 'required' => 'خیر', 'desc' => 'داده‌ی دلخواه'],
                ],
                'body' => '{ "email": "sara@example.com", "full_name": "سارا احمدی", "role": "admin" }',
                'response' => '{ "user": { "id": 13, "email": "sara@example.com", "role": "admin" } }',
                'note' => 'اگر کاربر از قبل وجود داشته باشد فقط به اپ متصل می‌شود (کد ۲۰۰)؛ در غیر این صورت ۲۰۱.',
            ],
            'GET /v1/users/{id}' => [
                'group' => 'users',
                'title' => 'دریافت یک کاربر',
                'summary' => 'اطلاعاتِ کاربر به همراه نقش و وضعیتش در این اپلیکیشن.',
                'params' => [
                    ['name' => 'id', 'type' => 'integer', 'required' => 'بله', 'desc' => 'شناسه‌ی کاربر (در نشانی)'],
                ],
                'body' => null,
                'response' => '{ "user": { "id": 12, "email": "ali@example.com", "role": "member", "status": "active", "metadata": {} } }',
                'note' => null,
            ],
            'PATCH /v1/users/{id}' => [
                'group' => 'users',
                'title' => 'به‌روزرسانی کاربر',
                'summary' => 'فقط فیلدهایی را بفرستید که می‌خواهید تغییر کنند.',
                'params' => [
                    ['name' => 'full_name', 'type' => 'string', 'required' => 'خیر', 'desc' => 'نام کامل'],
                    ['name' => 'phone', 'type' => 'string', 'required' => 'خیر', 'desc' => 'شماره تماس'],
                    ['name' => 'avatar_url', 'type' => 'string', 'required' => 'خیر', 'desc' => 'نشانی تصویر'],
                    ['name' => 'metadata', 'type' => 'object', 'required' => 'خیر', 'desc' => 'داده‌ی دلخواه'],
                    ['name' => 'role', 'type' => 'string', 'required' => 'خیر', 'desc' => 'تغییر نقش در این اپ'],
                ],
                'body' => '{ "full_name": "علی رضایی‌نژاد", "role": "admin" }',
                'response' => '{ "user": { "id": 12, "full_name": "علی رضایی‌نژاد", "role": "admin" } }',
                'note' => null,
            ],
            'DELETE /v1/users/{id}' => [
                'group' => 'users',
                'title' => 'قطع دسترسی کاربر',
                'summary' => 'عضویتِ کاربر را در این اپلیکیشن حذف و توکن‌هایش را باطل می‌کند.',
                'params' => [
                    ['name' => 'id', 'type' => 'integer', 'required' => 'بله', 'desc' => 'شناسه‌ی کاربر (در نشانی)'],
                ],
                'body' => null,
                'response' => '{ "detached": true }',
                'note' => 'خودِ کاربر از سامانه حذف نمی‌شود (SSO مشترک است)؛ فقط از این اپ جدا می‌شود.',
            ],
            'POST /v1/users/{id}/role' => [
                'group' => 'users',
                'title' => 'تغییر نقش',
                'summary' => 'نقشِ کاربر را در این اپلیکیشن عوض می‌کند.',
                'params' => [
                    ['name' => 'role', 'type' => 'string', 'required' => 'بله', 'desc' => 'owner / admin / member / viewer'],
                ],
                'body' => '{ "role": "admin" }',
                'response' => '{ "user": { "id": 12, "role": "admin" } }',
                'note' => 'ترتیبِ سطح: owner > admin > member > viewer. فقط می‌توانید نقشی هم‌سطح یا پایین‌تر از خودتان بدهید.',
            ],
            'POST /v1/users/{id}/suspend' => [
                'group' => 'users',
                'title' => 'تعلیق کاربر',
                'summary' => 'دسترسیِ کاربر را در این اپلیکیشن موقتاً قطع و همه‌ی توکن‌هایش را در همین اپ باطل می‌کند.',
                'params' => [
                    ['name' => 'id', 'type' => 'integer', 'required' => 'بله', 'desc' => 'شناسه‌ی کاربر (در نشانی)'],
                ],
                'body' => null,
                'response' => '{ "user": { "id": 12, "status": "suspended" } }',
                'note' => 'تعلیق «برای هر اپ» جداگانه است؛ کاربر در اپ‌های دیگر همچنان فعال می‌ماند.',
            ],
            'POST /v1/users/{id}/activate' => [
                'group' => 'users',
                'title' => 'فعال‌سازی کاربر',
                'summary' => 'دسترسیِ کاربر را در این اپلیکیشن برمی‌گرداند.',
                'params' => [
                    ['name' => 'id', 'type' => 'integer', 'required' => 'بله', 'desc' => 'شناسه‌ی کاربر (در نشانی)'],
                ],
                'body' => null,
                'response' => '{ "user": { "id": 12, "status": "active" } }',
                'note' => null,
            ],
            'POST /v1/users/{id}/password' => [
                'group' => 'users',
                'title' => 'تعیین رمز عبور',
                'summary' => 'رمز عبورِ کاربر را مستقیماً عوض می‌کند (بدون نیاز به رمزِ قبلی).',
                'params' => [
                    ['name' => 'password', 'type' => 'string', 'required' => 'بله', 'desc' => 'رمز جدید (حداقل ۸ کاراکتر)'],
                ],
                'body' => '{ "password": "YekRamezeJadid456" }',
                'response' => '{ "updated": true }',
                'note' => null,
            ],
            'POST /v1/users/{id}/password-reset' => [
                'group' => 'users',
                'title' => 'ساخت لینکِ بازیابی',
                'summary' => 'یک توکنِ بازیابی برای کاربر می‌سازد تا خودش رمزش را عوض کند.',
                'params' => [
                    ['name' => 'id', 'type' => 'integer', 'required' => 'بله', 'desc' => 'شناسه‌ی کاربر (در نشانی)'],
                ],
                'body' => null,
                'response' => '{ "token": "eyJ...", "expires_in": 3600 }',
                'note' => 'این توکن را فقط از سمتِ سرور نگه دارید و در ایمیل/پیامک برای کاربر بفرستید.',
            ],
            'POST /v1/users/{id}/verify-email' => [
                'group' => 'users',
                'title' => 'تأیید ایمیل',
                'summary' => 'ایمیلِ کاربر را تأییدشده علامت می‌زند.',
                'params' => [
                    ['name' => 'id', 'type' => 'integer', 'required' => 'بله', 'desc' => 'شناسه‌ی کاربر (در نشانی)'],
                ],
                'body' => null,
                'response' => '{ "user": { "id": 12, "email_verified_at": "2026-10-08 12:00:00" } }',
                'note' => null,
            ],

            // ------------------------------------------------------ کاربر جاری
            'GET /v1/me' => [
                'group' => 'me',
                'title' => 'اطلاعات کاربرِ جاری',
                'summary' => 'اطلاعاتِ کاربری که توکن متعلق به اوست، به همراه نقش در اپ جاری.',
                'params' => [],
                'body' => null,
                'response' => '{
  "user": { "id": 12, "email": "ali@example.com", "full_name": "علی رضایی", "role": "member" },
  "app": { "id": 3, "name": "اپ من" },
  "token": { "expires_at": "2026-10-08 13:00:00" }
}',
                'note' => 'این مسیر با توکنِ کاربر کار می‌کند، نه کلیدِ اپ.',
            ],
            'PATCH /v1/me' => [
                'group' => 'me',
                'title' => 'ویرایش پروفایل خود',
                'summary' => 'کاربر اطلاعاتِ خودش را عوض می‌کند.',
                'params' => [
                    ['name' => 'full_name', 'type' => 'string', 'required' => 'خیر', 'desc' => 'نام کامل'],
                    ['name' => 'phone', 'type' => 'string', 'required' => 'خیر', 'desc' => 'شماره تماس'],
                    ['name' => 'avatar_url', 'type' => 'string', 'required' => 'خیر', 'desc' => 'نشانی تصویر'],
                    ['name' => 'metadata', 'type' => 'object', 'required' => 'خیر', 'desc' => 'داده‌ی دلخواه'],
                ],
                'body' => '{ "full_name": "علی ر." }',
                'response' => '{ "user": { "id": 12, "full_name": "علی ر." } }',
                'note' => 'کاربر نمی‌تواند نقشِ خودش را با این مسیر تغییر دهد.',
            ],
            'POST /v1/me/password' => [
                'group' => 'me',
                'title' => 'تغییر رمز عبور خود',
                'summary' => 'برای تغییر رمز، رمزِ فعلی لازم است.',
                'params' => [
                    ['name' => 'current_password', 'type' => 'string', 'required' => 'بله', 'desc' => 'رمز فعلی'],
                    ['name' => 'new_password', 'type' => 'string', 'required' => 'بله', 'desc' => 'رمز جدید (حداقل ۸ کاراکتر)'],
                ],
                'body' => '{ "current_password": "YekRamez123", "new_password": "YekRamezeJadid456" }',
                'response' => '{ "updated": true }',
                'note' => null,
            ],
            'GET /v1/me/apps' => [
                'group' => 'me',
                'title' => 'اپ‌های کاربر',
                'summary' => 'همه‌ی اپلیکیشن‌هایی که این کاربر عضوشان است، با نقش در هر کدام.',
                'params' => [],
                'body' => null,
                'response' => '{
  "data": [ { "id": 3, "name": "اپ من", "role": "member", "status": "active" } ]
}',
                'note' => 'برای ساخت یک «انتخاب‌گر اپ» بعد از ورودِ یکپارچه مناسب است.',
            ],
            'POST /v1/me/logout' => [
                'group' => 'me',
                'title' => 'خروج از همه‌ی دستگاه‌ها',
                'summary' => 'همه‌ی توکن‌های فعالِ کاربر در اپ جاری را باطل می‌کند.',
                'params' => [],
                'body' => null,
                'response' => '{ "revoked": 3 }',
                'note' => 'عددِ برگشتی تعداد توکن‌های باطل‌شده است.',
            ],

            // ------------------------------------------------------ اپ جاری
            'GET /v1/apps/me' => [
                'group' => 'apps',
                'title' => 'اطلاعات اپلیکیشن',
                'summary' => 'اطلاعاتِ اپی که با کلید و رازش احراز هویت کرده‌اید.',
                'params' => [],
                'body' => null,
                'response' => '{ "app": { "id": 3, "name": "اپ من", "status": "active" } }',
                'note' => null,
            ],
            'PATCH /v1/apps/me' => [
                'group' => 'apps',
                'title' => 'ویرایش اپلیکیشن',
                'summary' => 'نام، توضیح و نشانی وب‌هوکِ اپ را عوض می‌کند.',
                'params' => [
                    ['name' => 'name', 'type' => 'string', 'required' => 'خیر', 'desc' => 'نام اپ'],
                    ['name' => 'description', 'type' => 'string', 'required' => 'خیر', 'desc' => 'توضیح'],
                    ['name' => 'webhook_url', 'type' => 'string', 'required' => 'خیر', 'desc' => 'نشانی دریافت رویدادها'],
                ],
                'body' => '{ "name": "اپ جدید من" }',
                'response' => '{ "app": { "id": 3, "name": "اپ جدید من" } }',
                'note' => null,
            ],
            'GET /v1/apps/me/stats' => [
                'group' => 'apps',
                'title' => 'آمار اپلیکیشن',
                'summary' => 'تعداد کاربران، توکن‌های فعال و وضعیتِ عضویت‌ها.',
                'params' => [],
                'body' => null,
                'response' => '{ "users": 42, "active_tokens": 7, "suspended": 1 }',
                'note' => null,
            ],
        ];
    }

    /**
     * کدهای خطای رایج.
     *
     * @return array<int, array{code: string, http: int, desc: string}>
     */
    public static function errorCodes(): array
    {
        return [
            ['code' => 'validation_failed', 'http' => 422, 'desc' => 'داده‌ی ارسالی معتبر نیست؛ جزئیات در فیلد errors است.'],
            ['code' => 'app_credentials_required', 'http' => 401, 'desc' => 'کلید یا رازِ اپ ارسال نشده یا اشتباه است.'],
            ['code' => 'authentication_required', 'http' => 401, 'desc' => 'توکنِ دسترسی ارسال نشده یا منقضی شده است.'],
            ['code' => 'invalid_credentials', 'http' => 401, 'desc' => 'ایمیل یا رمز عبور اشتباه است.'],
            ['code' => 'account_locked', 'http' => 429, 'desc' => 'به‌دلیل تلاش‌های ناموفق، حساب موقتاً قفل شده است.'],
            ['code' => 'account_inactive', 'http' => 403, 'desc' => 'حساب کاربر غیرفعال است.'],
            ['code' => 'not_a_member', 'http' => 403, 'desc' => 'این کاربر عضو این اپلیکیشن نیست.'],
            ['code' => 'app_disabled', 'http' => 403, 'desc' => 'این اپلیکیشن غیرفعال شده است.'],
            ['code' => 'email_taken', 'http' => 409, 'desc' => 'این ایمیل قبلاً ثبت شده است.'],
            ['code' => 'not_found', 'http' => 404, 'desc' => 'منبع مورد نظر یافت نشد.'],
            ['code' => 'too_many_attempts', 'http' => 429, 'desc' => 'تعداد درخواست‌ها از حد مجاز گذشته است.'],
            ['code' => 'weak_password', 'http' => 422, 'desc' => 'رمز عبور ساده است یا کوتاه‌تر از حد مجاز.'],
            ['code' => 'invalid_reset_token', 'http' => 400, 'desc' => 'توکنِ بازیابی نامعتبر یا مصرف‌شده است.'],
        ];
    }

    /**
     * مسیرهایی که در جدولِ مسیرها هستند اما توضیحی برایشان نوشته نشده است.
     *
     * برای استفاده در تست: باید همیشه آرایه‌ی خالی برگرداند.
     *
     * @return array<int, string>
     */
    public static function missing(): array
    {
        $router = require dirname(__DIR__, 2) . '/src/Api/routes.php';
        $catalog = self::catalog();
        $missing = [];
        foreach ($router->routes() as $route) {
            $key = $route['method'] . ' ' . $route['path'];
            if (!isset($catalog[$key])) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /**
     * توضیحِ یک مسیر؛ اگر نوشته نشده باشد، یک مدخلِ حداقلی می‌سازد
     * تا صفحه‌ی مستندات هیچ‌وقت مسیری را جا نیندازد.
     *
     * @return array{group: string, title: string, summary: string, params: array<int, array<string, string>>, body: string|null, response: string, note: string|null}
     */
    public static function describe(string $method, string $path): array
    {
        $key = $method . ' ' . $path;
        $catalog = self::catalog();
        if (isset($catalog[$key])) {
            return $catalog[$key];
        }
        return [
            'group' => 'auth',
            'title' => $path,
            'summary' => 'برای این مسیر هنوز توضیحی نوشته نشده است.',
            'params' => [],
            'body' => null,
            'response' => '{}',
            'note' => null,
        ];
    }
    /**
     * ساخت مجموعه‌ی Postman (نسخه ۲.۱) از روی مسیرهای واقعی.
     *
     * @param array<int, array<string, mixed>> $routes خروجیِ Router::routes()
     * @return array<string, mixed>
     */
    public static function postmanCollection(array $routes, string $apiBase): array
    {
        $folders = [];
        foreach ($routes as $route) {
            $method = (string) $route['method'];
            $path = (string) $route['path'];
            $mode = (string) ($route['auth'] ?? 'public');
            $doc = self::describe($method, $path);

            $headers = [['key' => 'Accept', 'value' => 'application/json']];
            if ($mode === 'app' || $mode === 'app_or_user' || $mode === 'optional_app') {
                $headers[] = ['key' => 'X-Api-Key', 'value' => '{{api_key}}'];
                $headers[] = ['key' => 'X-Api-Secret', 'value' => '{{api_secret}}'];
            }
            if ($mode === 'user') {
                $headers[] = ['key' => 'Authorization', 'value' => 'Bearer {{access_token}}'];
            }

            $request = [
                'method' => $method,
                'header' => $headers,
                'url' => [
                    'raw' => '{{base_url}}' . self::suffix($path),
                    'host' => ['{{base_url}}'],
                    'path' => array_values(array_filter(explode('/', self::suffix($path)), 'strlen')),
                ],
                'description' => $doc['summary'],
            ];

            if ($doc['body'] !== null && $doc['body'] !== '') {
                $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
                $request['header'] = $headers;
                $request['body'] = [
                    'mode' => 'raw',
                    'raw' => (string) $doc['body'],
                    'options' => ['raw' => ['language' => 'json']],
                ];
            }

            $group = (string) $doc['group'];
            if (!isset($folders[$group])) {
                $folders[$group] = [
                    'name' => self::groups()[$group] ?? $group,
                    'item' => [],
                ];
            }
            $folders[$group]['item'][] = [
                'name' => $doc['title'],
                'request' => $request,
            ];
        }

        return [
            'info' => [
                'name' => 'سامانه احراز هویت یکپارچه (SSO)',
                'description' => "مستنداتِ خودکارِ API.\n"
                    . "متغیرهای base_url، api_key، api_secret و access_token را در محیطِ Postman پر کنید.",
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'variable' => [
                ['key' => 'base_url', 'value' => $apiBase],
                ['key' => 'api_key', 'value' => 'YOUR_API_KEY'],
                ['key' => 'api_secret', 'value' => 'YOUR_API_SECRET'],
                ['key' => 'access_token', 'value' => 'YOUR_ACCESS_TOKEN'],
            ],
            'item' => array_values($folders),
        ];
    }

    /**
     * ساخت سند OpenAPI ۳ از روی مسیرهای واقعی.
     *
     * @param array<int, array<string, mixed>> $routes خروجیِ Router::routes()
     * @return array<string, mixed>
     */
    public static function openapiDocument(array $routes, string $serverUrl): array
    {
        $paths = [];
        foreach ($routes as $route) {
            $method = (string) $route['method'];
            $path = (string) $route['path'];
            $mode = (string) ($route['auth'] ?? 'public');
            $doc = self::describe($method, $path);

            $operation = [
                'summary' => $doc['title'],
                'description' => $doc['summary'],
                'tags' => [self::groups()[$doc['group']] ?? $doc['group']],
            ];

            if ($mode === 'app' || $mode === 'app_or_user') {
                $operation['security'] = [['AppKey' => [], 'AppSecret' => []]];
                if ($mode === 'app_or_user') {
                    $operation['security'][] = ['BearerAuth' => []];
                }
            } elseif ($mode === 'user') {
                $operation['security'] = [['BearerAuth' => []]];
            }

            // پارامترهای مسیر (مثل {id})
            foreach (self::pathParams($path) as $name) {
                $operation['parameters'][] = [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ];
            }
            // پارامترهای پرس‌وجو برای GET
            if ($method === 'GET') {
                foreach ($doc['params'] as $param) {
                    if (in_array($param['name'], self::pathParams($path), true)) {
                        continue;
                    }
                    $operation['parameters'][] = [
                        'name' => $param['name'],
                        'in' => 'query',
                        'required' => false,
                        'description' => $param['desc'],
                        'schema' => ['type' => $param['type'] === 'integer' ? 'integer' : 'string'],
                    ];
                }
            }

            if ($doc['body'] !== null && $doc['body'] !== '') {
                $properties = [];
                $required = [];
                foreach ($doc['params'] as $param) {
                    if (in_array($param['name'], self::pathParams($path), true)) {
                        continue;
                    }
                    $properties[$param['name']] = [
                        'type' => $param['type'] === 'object' ? 'object' : ($param['type'] === 'integer' ? 'integer' : 'string'),
                        'description' => $param['desc'],
                    ];
                    if ($param['required'] === 'بله') {
                        $required[] = $param['name'];
                    }
                }
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => $properties,
                        ] + ($required !== [] ? ['required' => $required] : []),
                        'example' => json_decode((string) $doc['body'], true) ?? new \stdClass(),
                    ]],
                ];
            }

            $operation['responses'] = ['200' => [
                'description' => 'پاسخ موفق',
                'content' => ['application/json' => [
                    'example' => json_decode((string) $doc['response'], true) ?? new \stdClass(),
                ]],
            ]];

            $paths[$path][strtolower($method)] = $operation;
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'سامانه احراز هویت یکپارچه (SSO)',
                'version' => '1.0.0',
                'description' => 'مستنداتِ خودکارِ API برای اتصالِ اپلیکیشن‌ها به سامانه‌ی احراز هویت.',
            ],
            'servers' => [['url' => $serverUrl !== '' ? $serverUrl : '/']],
            'components' => ['securitySchemes' => [
                'AppKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                'AppSecret' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Secret'],
                'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
            ]],
            'paths' => $paths,
        ];
    }

    /**
     * مسیرِ نسبیِ یک endpoint (بدون پیشوندِ /v1).
     */
    public static function suffix(string $path): string
    {
        return str_starts_with($path, '/v1') ? substr($path, 3) : $path;
    }

    /**
     * نامِ پارامترهای موجود در نشانی (مانند {id}).
     *
     * @return array<int, string>
     */
    public static function pathParams(string $path): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $m);
        return $m[1] ?? [];
    }

    /**
     * نمونه‌کد Curl */
    public static function sampleCurl(): string
    {
        return <<<'CURLSAMPLE'
# ۱) ثبت‌نام کاربر
curl -X POST "{API_BASE}/auth/register" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: YOUR_API_KEY" \
  -H "X-Api-Secret: YOUR_API_SECRET" \
  -d '{"email":"ali@example.com","password":"YekRamez123","full_name":"علی رضایی"}'

# ۲) ورود
curl -X POST "{API_BASE}/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: YOUR_API_KEY" \
  -H "X-Api-Secret: YOUR_API_SECRET" \
  -d '{"email":"ali@example.com","password":"YekRamez123"}'

# ۳) استفاده از توکن برای گرفتن اطلاعات کاربر
curl -X GET "{API_BASE}/me" \
  -H "Authorization: Bearer ACCESS_TOKEN"

# ۴) وقتی توکن منقضی شد، با توکنِ تمدید یکی تازه بگیرید
curl -X POST "{API_BASE}/auth/refresh" \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"REFRESH_TOKEN"}'
CURLSAMPLE;
    }

    /**
     * نمونه‌کد Php */
    public static function samplePhp(): string
    {
        return <<<'PHPSAMPLE'
<?php

declare(strict_types=1);

/**
 * یک کلاینتِ ساده برای سامانه احراز هویت.
 * این کلاس فقط از cURL استفاده می‌کند؛ هیچ وابستگیِ خارجی ندارد.
 */
final class SsoClient
{
    public function __construct(
        private string $base,      // مثال: {API_BASE}
        private string $key,       // X-Api-Key
        private string $secret     // X-Api-Secret
    ) {
    }

    private function call(string $method, string $path, array $body = [], ?string $token = null): array
    {
        $headers = ['Accept: application/json'];

        if ($token !== null) {
            // درخواست به نیابت از کاربر
            $headers[] = 'Authorization: Bearer ' . $token;
        } else {
            // درخواستِ سرور به سرور با کلیدِ اپلیکیشن
            $headers[] = 'X-Api-Key: ' . $this->key;
            $headers[] = 'X-Api-Secret: ' . $this->secret;
        }
        if ($body !== []) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($body !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('SSO خطای ' . $status . ': ' . $raw);
        }

        return is_array($data) ? $data : [];
    }

    public function register(string $email, string $password, array $extra = []): array
    {
        return $this->call('POST', '/auth/register', $extra + [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function login(string $email, string $password): array
    {
        return $this->call('POST', '/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function me(string $token): array
    {
        return $this->call('GET', '/me', [], $token);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->call('POST', '/auth/refresh', ['refresh_token' => $refreshToken]);
    }

    public function users(string $token = '', array $filters = []): array
    {
        $query = $filters === [] ? '' : '?' . http_build_query($filters);
        return $this->call('GET', '/users' . $query);
    }
}

// ---- استفاده
$sso = new SsoClient('{API_BASE}', 'YOUR_API_KEY', 'YOUR_API_SECRET');

// ورود؛ توکن‌ها را در نشستِ کاربرِ خودتان نگه دارید
$tokens = $sso->login('ali@example.com', 'YekRamez123');

// گرفتن اطلاعات کاربر با توکنِ دسترسی
$user = $sso->me($tokens['access_token']);
echo $user['user']['email'];

// تمدید وقتی توکنِ دسترسی منقضی شد
$again = $sso->refresh($tokens['refresh_token']);
PHPSAMPLE;
    }

    /**
     * نمونه‌کد Js */
    public static function sampleJs(): string
    {
        return <<<'JSSAMPLE'
// توجه: این کد برای «سرور» است (Node.js یا بک‌اند).
// رازِ اپلیکیشن را هرگز در مرورگر یا اپلیکیشن موبایل قرار ندهید.

const BASE = '{API_BASE}';
const KEY = 'YOUR_API_KEY';
const SECRET = 'YOUR_API_SECRET';

async function sso(path, { method = 'GET', body, token } = {}) {
  const headers = { Accept: 'application/json' };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  } else {
    headers['X-Api-Key'] = KEY;
    headers['X-Api-Secret'] = SECRET;
  }
  if (body) headers['Content-Type'] = 'application/json';

  const res = await fetch(BASE + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.message || `خطا ${res.status}`);
  }
  return data;
}

// ۱) ورود
const tokens = await sso('/auth/login', {
  method: 'POST',
  body: { email: 'ali@example.com', password: 'YekRamez123' },
});

// ۲) اطلاعات کاربر با توکنِ دسترسی
const me = await sso('/me', { token: tokens.access_token });

// ۳) تمدید
const refreshed = await sso('/auth/refresh', {
  method: 'POST',
  body: { refresh_token: tokens.refresh_token },
});
JSSAMPLE;
    }


    /**
     * نمونه‌کدها با نشانیِ پایه‌ی واقعی.
     *
     * @return array{curl: string, php: string, js: string}
     */
    public static function filledSamples(string $apiBase): array
    {
        return [
            'curl' => str_replace('{API_BASE}', $apiBase, self::sampleCurl()),
            'php' => str_replace('{API_BASE}', $apiBase, self::samplePhp()),
            'js' => str_replace('{API_BASE}', $apiBase, self::sampleJs()),
        ];
    }
}
