# سامانه احراز هویت یکپارچه (SSO)

یک سامانه‌ی احراز هویت سبک و مستقل که با **PHP 8** و **MySQL** روی **IIS** اجرا می‌شود و شامل سه بخش است:

1. **پنل مدیریت وب** — فقط برای مدیر (admin): داشبورد، مدیریت اپلیکیشن‌ها، مدیریت کاربران، صدور/ابطال توکن، گزارش رویدادها.
2. **API مبتنی بر توکن** — تا مدیر بتواند مدیریت کاربران (ثبت‌نام، ورود، نقش، تعلیق، بازیابی رمز) را به اپلیکیشن‌های دیگر واگذار کند.
3. **پایگاه داده‌ی کاربران** — یک جدول کاربران سراسری به‌همراه جدول عضویت که به هر کاربر در هر اپلیکیشن یک نقش می‌دهد (SSO واقعی).

بدون هیچ فریم‌ورک، بدون Composer و بدون npm: فقط PHP، HTML و CSS دستی (رابط فارسی/راست‌به‌چپ).

---

## مستندات برای برنامه‌نویس

در پنل مدیریت، گزینه‌ی **مستندات** (`/admin/docs.php`) صفحه‌ای است که می‌توانید
مستقیماً به برنامه‌نویس بدهید:

- فهرستِ **همه‌ی مسیرهای API** با توضیح، پارامترها، نمونه‌ی درخواست و نمونه‌ی پاسخ
- نمونه‌کدِ آماده برای **cURL**، **PHP** و **JavaScript**
- جدولِ کاملِ کدهای خطا
- دو خروجیِ قابل دانلود: **مجموعه‌ی Postman** و **سند OpenAPI**

```
GET /admin/docs.php?export=postman   →  مجموعه‌ی Postman (نسخه ۲.۱)
GET /admin/docs.php?export=openapi   →  سند OpenAPI ۳
```

> نکته‌ی مهم: فهرستِ مسیرها از روی **جدولِ مسیرهای واقعیِ برنامه** ساخته می‌شود،
> نه از یک متنِ دستی. اگر مسیری به API اضافه شود و توضیحش نوشته نشود،
> تست خطا می‌دهد (`Sso\Support\ApiDocs::missing()`). به این ترتیب مستندات
> هیچ‌وقت از کد عقب نمی‌ماند.

## دانلود سورس

یک بسته‌ی آماده از کل سورس (بدون `.git` و وابستگی‌های توسعه) به صورت یک فایل زیپ در خودِ مخزن قرار دارد:

**<https://raw.githubusercontent.com/Smohamad933/Sso/arena/fb346cbb-sso/sso-source.zip>**

```
curl -L -o sso.zip https://raw.githubusercontent.com/Smohamad933/Sso/arena/fb346cbb-sso/sso-source.zip
```

> زیپ شامل `node_modules/` و فایل `config/config.php` نیست (دومی حاوی رمز دیتابیس شماست).
> پس از باز کردنِ زیپ، مراحلِ [نصب](#نصب) را انجام دهید.

برای بازسازیِ بسته پس از هر تغییر:

```bash
bash tools/build-source-zip.sh
```

این اسکریپت با `git archive` از آخرین commit بسته می‌سازد؛ بنابراین محتوا دقیقاً
همان چیزی است که در مخزن ثبت شده. خطوطِ جدیدِ فایل‌های متنی طبق `.gitattributes`
همیشه از نوع LF هستند تا روی IIS/Apache/Nginx یکسان باشد.

## فهرست

- [شروع سریع](#شروع-سریع)
- [نصب](#نصب)
- [مدل داده و مفاهیم](#مدل-داده-و-مفاهیم)
- [احراز هویت در API](#احراز-هویت-در-api)
- [نمونه‌ی فراخوانی API](#نمونهی-فراخوانی-api)
- [ابزارهای خط فرمان](#ابزارهای-خط-فرمان)
- [ساختار پروژه](#ساختار-پروژه)
- [تست‌ها](#تستها)
- [مستندات بیشتر](#مستندات-بیشتر)

---

## شروع سریع

پیش‌نیازها: PHP 8.0 یا بالاتر (با افزونه‌ی `pdo_mysql`، `mbstring` و `openssl`) و MySQL 5.7+/8.

```powershell
# 1) گرفتن کد
git clone <آدرس-مخزن> sso
cd sso

# 2) ساخت دیتابیس
mysql -u root -p -e "CREATE DATABASE sso CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;"

# 3) نصب از طریق مرورگر
#    سایت را روی IIS منتشر کنید و به آدرس /setup.php بروید.
```

همچنین می‌توان نصب را با خط فرمان انجام داد:

```bash
php tools/install.php
```

جزئیات کامل نصب روی IIS: **[docs/install-iis.md](docs/install-iis.md)**

---

## نصب

### نصب وب (توصیه‌شده)

آدرس `http://<میزبان>/setup.php` را باز کنید. سه مرحله دارد:

1. بررسی پیش‌نیازها (نسخه‌ی PHP، افزونه‌ها، مجوز نوشتن روی `config/` و `storage/`).
2. تنظیم دیتابیس و نام سامانه.
3. ساخت مدیر ارشد و (اختیاری) یک اپلیکیشن نمونه.

پس از نصب، فایل `config/config.php` و قفل `storage/installed.lock` ساخته می‌شود و دیگر به `setup.php` دسترسی نخواهید داشت.

### تنظیمات

همه‌ی تنظیمات در `config/config.php` است (نمونه: `config/config.example.php`):

| کلید | توضیح |
|---|---|
| `debug` | نمایش خطاها در لاگ. در production باید `false` باشد. |
| `app_name` | عنوان سامانه که در پنل نمایش داده می‌شود. |
| `base_url` | آدرس عمومی سامانه، مثل `https://auth.example.com`. خالی بگذارید تا لینک‌ها نسبی باشند. |
| `app_key` | کلید رمزنگاری داخلی؛ هنگام نصب به‌صورت تصادفی ساخته می‌شود. |
| `timezone` | منطقه‌ی زمانی PHP (همه‌ی زمان‌ها در دیتابیس UTC ذخیره می‌شوند). |
| `db.driver` | `mysql` (پیش‌فرض، مناسب IIS) یا `sqlite`. |
| `db.*` | مشخصات اتصال: `host`، `port`، `database`، `username`، `password`، `charset`. |
| `security.password_min_length` | حداقل طول رمز عبور. |
| `security.password_algorithm` | `auto` (argon2id در صورت پشتیبانی، در غیر این صورت bcrypt)، یا `bcrypt`. |
| `security.login_max_attempts` | تلاش‌های ناموفق ورود پیش از قفل شدن. |
| `security.login_lockout_minutes` | مدت قفل پس از تلاش‌های ناموفق. |
| `security.access_token_ttl` | عمر توکن دسترسی (ثانیه، پیش‌فرض ۳۶۰۰). |
| `security.refresh_token_ttl` | عمر توکن تازه‌سازی (ثانیه، پیش‌فرض ۳۰ روز). |
| `security.api_rate_limit_per_minute` | سقف درخواست‌های API برای هر اپلیکیشن. |
| `security.admin_session_idle_minutes` | زمان بیکاری نشست مدیر پیش از خروج خودکار. |
| `security.admin_ip_whitelist` | محدود کردن ورود مدیر به IP/بازه‌های مشخص (آرایه، خالی یعنی بدون محدودیت). |
| `security.trusted_proxies` | IPهایی که هدر `X-Forwarded-For`‌شان برای تشخیص IP واقعی معتبر است. |

### دیتابیس

شِمای MySQL در `database/schema.mysql.sql` و شِمای SQLite در `database/schema.sqlite.sql` است. هر دو هم‌ارزند و شامل ۸ جدول:

| جدول | نقش |
|---|---|
| `apps` | اپلیکیشن‌های متصل به سامانه (هر کدام یک جفت کلید/راز). |
| `users` | جدول **سراسری** کاربران (ایمیل/موبایل یکتا، وضعیت، پروفایل). |
| `memberships` | عضویت هر کاربر در هر اپلیکیشن به‌همراه نقش و متادیتا. |
| `api_tokens` | توکن‌های دسترسی و تازه‌سازی (قابل ابطال، دارای تاریخ انقضا). |
| `password_resets` | کدهای بازیابی رمز عبور. |
| `login_attempts` | شمارنده‌ی تلاش‌های ورود (ضد حمله‌ی Brute-force). |
| `rate_limits` | شمارنده‌ی نرخ درخواست API. |
| `audit_logs` | گزارش رویدادهای مهم (ورود، تغییر نقش، ابطال توکن و …). |

نکته‌ی مهم: در کل کد از توابع زمان/تاریخ SQL (مانند `NOW()`) و از دستورهای upsert استفاده **نشده** است؛ همه‌ی زمان‌ها در PHP (به‌وقت UTC) محاسبه و به‌صورت پارامتر ارسال می‌شوند. به همین دلیل یک کد روی MySQL و SQLite یکسان کار می‌کند.

---

## مدل داده و مفاهیم

### اشتراکی (Shared) به‌جای چندمستأجری

یک کاربر فقط **یک بار** در جدول `users` وجود دارد، اما می‌تواند عضو چند اپلیکیشن باشد. جدول `memberships` برای هر جفت «کاربر + اپلیکیشن» یک نقش نگه می‌دارد:

```
user #1  →  app «فروشگاه»      نقش: customer
user #1  →  app «پنل مالی»     نقش: admin
user #1  →  app «باشگاه»       نقش: member
```

بنابراین یک حساب کاربری در همه‌ی سامانه‌ها معتبر است (SSO واقعی) ولی سطح دسترسی‌اش در هرکدام مستقل تعیین می‌شود.

### نقش‌ها

نقش به «عضویت» تعلق می‌گیرد، نه به کاربر؛ بنابراین یک نفر می‌تواند در یک اپلیکیشن مدیر و در اپلیکیشنی دیگر عضو عادی باشد.

| نقش | رتبه | توضیح |
|---|---|---|
| `owner` | ۴۰ | مالک اپلیکیشن؛ بالاترین سطح در آن اپلیکیشن. |
| `admin` | ۳۰ | مدیر اپلیکیشن؛ می‌تواند کاربران همان اپ را از طریق API مدیریت کند. |
| `member` | ۲۰ | کاربر عضو (نقش پیش‌فرض هنگام ثبت‌نام). |
| `viewer` | ۱۰ | فقط خواندن. |

قاعده: هر نقش فقط می‌تواند نقش‌های هم‌رتبه یا پایین‌تر را مدیریت کند.

### مدیر کل سامانه

ورود به **پنل مدیریت** با نقش تعیین نمی‌شود، بلکه با ستون `users.is_super_admin`. یعنی حتی اگر کسی در یک اپلیکیشن `owner` باشد، بدون این پرچم نمی‌تواند وارد پنل شود. مدیر کل با نصاب وب، یا با `php tools/create-admin.php`، یا از بخش کاربران پنل تعیین می‌شود.

### دو لایه‌ی توکن

1. **کلید و راز اپلیکیشن** (`api_key` / `api_secret`) — ثابت، مربوط به یک اپلیکیشن. با آن می‌توان کاربر ساخت، کاربران را مدیریت کرد و توکن کاربر صادر نمود.
2. **توکن دسترسی کاربر** (`access_token`) — به‌ازای هر کاربر، در دیتابیس ذخیره می‌شود، تاریخ انقضا دارد و هر لحظه قابل ابطال است؛ به‌همراه یک `refresh_token` برای تمدید.

هر دو لایه هم‌زمان فعال‌اند: اپلیکیشن با کلید/راز خودش وارد می‌شود و برای هر کاربر توکن اختصاصی می‌گیرد. از JWT استفاده نشده است، چون ابطال فوری توکن در JWT بدون نگه‌داری وضعیت ممکن نیست.

---

## احراز هویت در API

همه‌ی مسیرها با پیشوند `/api` شروع می‌شوند (مثال: `https://auth.example.com/api/v1/me`).

### ارسال کلید/راز اپلیکیشن

سه شیوه پشتیبانی می‌شود:

```http
X-Api-Key: ak_live_xxxxxxxxxxxxxxxx
X-Api-Secret: <secret>
```

```http
Authorization: Key ak_live_xxxxxxxxxxxxxxxx
```

```http
Authorization: Basic base64(api_key:api_secret)
```

### ارسال توکن کاربر

```http
Authorization: Bearer <access_token>
```

### سطح دسترسی مسیرها

| سطح | یعنی |
|---|---|
| `public` | بدون احراز هویت (`/v1/health`). |
| `app` | فقط کلید/راز اپلیکیشن. |
| `optional_app` | کلید/راز در صورت وجود. |
| `user` | فقط توکن کاربر. |
| `app_or_user` | هر کدام. |

### قالب پاسخ

موفق:

```json
{ "ok": true, "data": { } }
```

خطا:

```json
{
  "ok": false,
  "error": {
    "code": "invalid_credentials",
    "message": "ایمیل یا رمز عبور اشتباه است.",
    "details": null
  }
}
```

کدهای رایج: `400` ورودی نامعتبر، `401` احراز هویت ناموفق، `403` عدم دسترسی، `404` یافت نشد، `405` متد مجاز نیست، `409` تکراری، `422` خطای اعتبارسنجی، `429` تعداد درخواست زیاد، `500` خطای سرور.

هر پاسخ شامل هدرهای زیر است:

```http
X-RateLimit-Limit: 600
X-RateLimit-Remaining: 597
```

---

## نمونه‌ی فراخوانی API

### ثبت‌نام کاربر توسط اپلیکیشن

```bash
curl -X POST https://auth.example.com/api/v1/auth/register \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: ak_live_xxxxxxxxxxxxxxxx' \
  -H 'X-Api-Secret: <secret>' \
  -d '{
    "email": "ali@example.com",
    "password": "Secret1234",
    "full_name": "علی رضایی",
    "phone": "09123456789",
    "role": "member",
    "metadata": { "plan": "free" }
  }'
```

پاسخ:

```json
{
  "ok": true,
  "data": {
    "user": { "id": 12, "email": "ali@example.com", "full_name": "علی رضایی" },
    "membership": { "app_id": 1, "role": "member" },
    "tokens": {
      "access_token": "...",
      "refresh_token": "...",
      "token_type": "Bearer",
      "expires_in": 3600
    }
  }
}
```

اگر اپلیکیشن فیلد `password` را نفرستد، سامانه یک رمز تصادفی می‌سازد و آن را با کلید `generated_password` برمی‌گرداند.

### ورود

```bash
curl -X POST https://auth.example.com/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: ak_live_xxxxxxxxxxxxxxxx' \
  -H 'X-Api-Secret: <secret>' \
  -d '{ "email": "ali@example.com", "password": "Secret1234" }'
```

### تازه‌سازی توکن

```bash
curl -X POST https://auth.example.com/api/v1/auth/refresh \
  -H 'Content-Type: application/json' \
  -d '{ "refresh_token": "..." }'
```

### خواندن اطلاعات کاربر جاری

```bash
curl https://auth.example.com/api/v1/me \
  -H 'Authorization: Bearer <access_token>'
```

### فهرست کاربران یک اپلیکیشن

```bash
curl 'https://auth.example.com/api/v1/users?role=member&status=active&per_page=50&page=1' \
  -H 'X-Api-Key: ak_live_xxxxxxxxxxxxxxxx' \
  -H 'X-Api-Secret: <secret>'
```

### تغییر نقش / تعلیق / فعال‌سازی

```bash
curl -X POST https://auth.example.com/api/v1/users/12/role \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: ...' -H 'X-Api-Secret: ...' \
  -d '{ "role": "admin" }'

curl -X POST https://auth.example.com/api/v1/users/12/suspend ...
curl -X POST https://auth.example.com/api/v1/users/12/activate ...
```

### خروج

```bash
curl -X POST https://auth.example.com/api/v1/auth/logout \
  -H 'Authorization: Bearer <access_token>'
```

فهرست کامل مسیرها، پارامترها و مثال‌ها: **[docs/api.md](docs/api.md)**

---

## ابزارهای خط فرمان

```bash
# نصب تعاملی (نوشتن تنظیمات، اجرای شِما، ساخت مدیر)
php tools/install.php

# یا نصب غیرتعاملی با پرچم‌ها
php tools/install.php --driver=mysql --host=127.0.0.1 --port=3306 \
     --database=sso --username=sso_user --password=... \
     --admin-email=admin@example.com --admin-password=Secret123 \
     --base-url=https://auth.example.com
```

```bash
# ساخت یا ارتقای یک مدیر کل
php tools/create-admin.php --email=admin@example.com --password=Secret1234 --name="مدیر"

# ساخت اپلیکیشن جدید و دریافت کلید/راز
php tools/create-app.php --name="فروشگاه من" --slug=my-shop --description="اپ فروشگاه"
```

> این سه ابزار فقط در خط فرمان اجرا می‌شوند و از طریق وب در دسترس نیستند.

---

## ساختار پروژه

```
.
├── public/                 ریشه‌ی وب (DocumentRoot در IIS همین پوشه است)
│   ├── index.php           هدایت به /admin/ یا /setup.php
│   ├── setup.php           نصاب وب (سه مرحله)
│   ├── web.config          تنظیمات IIS (بازنویسی URL، مسدود کردن مسیرهای حساس)
│   ├── .htaccess           معادل برای Apache (در صورت نیاز)
│   ├── assets/             app.css و app.js (بدون وابستگی خارجی)
│   ├── admin/              صفحات پنل مدیریت (فارسی/RTL)
│   └── api/index.php       نقطه‌ی ورود API
├── src/
│   ├── bootstrap.php       تعریف ثابت‌ها، autoloader، مدیریت خطا
│   ├── Support/            ابزارها: رشته، زمان، لاگ، CSRF، اعتبارسنجی، تاریخ جلالی
│   ├── Core/App.php        ظرف برنامه (تنظیمات، دیتابیس، سرویس‌ها)
│   ├── Data/Database.php   لایه‌ی PDO (mysql و sqlite)
│   ├── Http/               Request، Response، Session، استثناها
│   ├── Admin/              Guard، Layout، Page (مربوط به پنل)
│   ├── Models/             User، Application، Membership، Token، AuditLog، Roles
│   ├── Services/           Token، Auth، User، App، RateLimiter، Audit، PasswordBroker
│   ├── Api/                Router، Kernel، Context، مسیرها و کنترلرها
│   └── Install/Installer.php
├── config/
│   ├── config.example.php
│   └── config.php          پس از نصب ساخته می‌شود (در git نادیده گرفته شده)
├── database/               schema.mysql.sql و schema.sqlite.sql
├── storage/                logs، sessions، cache، database (قابل نوشتن)
├── tools/                  ابزارهای خط فرمان + dev-server.mjs (فقط پیش‌نمایش)
└── tests/                  مجموعه‌ی تست (Node + PHP در WebAssembly)
```

### پیش‌نمایش محلی (بدون IIS)

اگر می‌خواهید پیش از انتشار روی IIS نتیجه را ببینید:

```bash
cd tests && npm install && cd ..
node tools/dev-server.mjs
```

سپس `http://localhost:8000/admin/` را باز کنید. این سرور فقط برای پیش‌نمایش است و داده‌هایش در حافظه می‌مانند؛ روی سرور واقعی از IIS استفاده کنید.

---

## تست‌ها

تست‌ها کل پروژه را روی PHP 8.3 (در WebAssembly) اجرا می‌کنند — بدون نیاز به IIS یا MySQL.

```bash
cd tests
npm install
node run.mjs
```

خروجی شمار تست‌های موفق/ناموفق را نشان می‌دهد و در پایان خلاصه‌ی هر بخش را چاپ می‌کند. بخش‌ها:

| بخش | چه چیزی بررسی می‌شود |
|---|---|
| قواعد معماری | **بررسی ایستا:** نبودِ توابع زمان/تاریخِ SQL، نبودِ upsert، نبودِ `exit` در مسیر وب، نبودِ متغیر درون رشته‌های SQL |
| بررسی نحو | همه‌ی فایل‌های PHP با `token_get_all` تجزیه می‌شوند |
| سلامت، ثبت‌نام، ورود، تمدید، خروج | چرخه‌ی کامل احراز هویت |
| مدیریت کاربران، نقش، تعلیق، بازیابی رمز | مسیرهای واگذارشده به اپلیکیشن‌ها |
| فیلتر و صفحه‌بندی | جست‌وجو، فیلتر نقش/وضعیت، سقف `per_page`، مرتب‌سازی |
| پنل مدیریت | همه‌ی صفحات، ورود، عملیات نوشتاری، CSRF، فهرست سفید IP |
| امنیت داده‌ها | هش بودنِ رمز عبور، توکن و کلید/راز اپ در دیتابیس |
| محدودیت نرخ، CORS، ۴۰۴/۴۰۵ | رفتار لبه‌ایِ API |
| نصب‌کننده وب و CLI | نصب از مرورگر و از خط فرمان، همراه با بررسیِ اثر واقعی در دیتابیس |

> بررسی قواعد معماری با `token_get_all` فقط **رشته‌های SQL** را می‌سنجد، نه کامنت‌ها و نام متدها را؛
> بنابراین تخطی‌های واقعی را می‌گیرد بدون اینکه با توضیحات یا `Clock::now()` اشتباه بگیرد.

---

## مستندات بیشتر

- **[docs/install-iis.md](docs/install-iis.md)** — نصب گام‌به‌گام روی IIS و MySQL، تنظیم مجوزها، بازنویسی URL و عیب‌یابی.
- **[docs/api.md](docs/api.md)** — مرجع کامل API: همه‌ی مسیرها، پارامترها و پاسخ‌ها.
- **[docs/security.md](docs/security.md)** — ملاحظات امنیتی و چک‌لیست انتشار.

---

## قواعد معماری (تضمین‌شده توسط تست)

این پروژه چند قاعده‌ی سخت دارد که با یک بررسی خودکار (`tests/php/architecture.php`) enforce می‌شوند و شکستن‌شان باعث قرمز شدن تست‌ها می‌شود:

1. **هیچ تابع زمان/تاریخِ SQL ی در کوئری‌ها نیست** (`NOW()`, `CURRENT_TIMESTAMP`, `DATE_ADD`, `INTERVAL` و …). همه‌ی زمان‌ها در PHP و به‌وقت UTC محاسبه می‌شوند و به‌صورت پارامتر فرستاده می‌شوند.
2. **هیچ upsert ی نیست** (`ON DUPLICATE KEY`, `INSERT IGNORE`, `REPLACE INTO`).
3. **در مسیرهای وب هیچ `exit`/`die` ی نیست** — خروج از طریق استثنا (`HttpException`, `RedirectException`) انجام می‌شود تا پاسخ در یک نقطه و به‌شکل کنترل‌شده فرستاده شود.
4. **هیچ رشته‌ی SQL ی شامل متغیر نیست** — همه‌ی مقادیر با پارامتر bind می‌شوند.
5. **در `src/` هیچ خروجیِ اشکال‌زدایی نیست** (`var_dump`, `print_r`).

فایده‌ی عملیِ قواعد ۱ و ۲: یک کد روی MySQL و SQLite کاملاً یکسان اجرا می‌شود.

---

## مجوز

این پروژه تحت مجوز MIT منتشر شده است؛ فایل [LICENSE](LICENSE) را ببینید.
