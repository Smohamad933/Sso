# نصب روی IIS و MySQL

این راهنما گام‌به‌گام انتشار سامانه روی **Windows Server + IIS + MySQL** را توضیح می‌دهد.

---

## ۱. پیش‌نیازها

### نرم‌افزارها

| مورد | نسخه‌ی پیشنهادی |
|---|---|
| Windows Server | 2016 / 2019 / 2022 |
| IIS | 10 |
| PHP | 8.1 یا 8.2 (**غیرِ thread-safe** برای FastCGI) |
| MySQL | 5.7 یا 8.0 |
| ماژول IIS | **URL Rewrite 2.1** |

### افزونه‌های لازم PHP

در `php.ini` این افزونه‌ها باید فعال باشند:

```ini
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=json        ; در PHP 8 همواره درونی است
extension=fileinfo    ; اختیاری
```

الگوریتم رمز عبور: در صورت وجود `sodium`/`argon2id` از آن استفاده می‌شود، در غیر این صورت به‌طور خودکار به `bcrypt` برمی‌گردد. هر دو امن هستند.

توصیه‌شده در `php.ini`:

```ini
memory_limit = 256M
upload_max_filesize = 8M
post_max_size = 8M
max_execution_time = 60
date.timezone = UTC
display_errors = Off
log_errors = On
error_log = "C:\inetpub\logs\php-errors.log"
session.save_path = "C:\inetpub\temp\sessions"
```

> `date.timezone` را روی UTC بگذارید؛ سامانه همه‌ی زمان‌ها را به‌وقت UTC ذخیره می‌کند و برای نمایش به منطقه‌ی زمانیِ تنظیمات (`timezone`) تبدیل می‌کند.

---

## ۲. پایگاه داده

وارد MySQL شوید و یک دیتابیس با排序 فارسی بسازید:

```sql
CREATE DATABASE sso
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_persian_ci;

CREATE USER 'sso_user'@'localhost' IDENTIFIED BY 'یک-رمز-قوی-اینجا';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
  ON sso.* TO 'sso_user'@'localhost';
FLUSH PRIVILEGES;
```

اگر ترجیح می‌دهید خودتان جدول‌ها را بسازید (و نخواهید نصاب این کار را انجام دهد):

```bash
mysql -u sso_user -p sso < database/schema.mysql.sql
```

در غیر این صورت نصاب وب (`/setup.php`) شِما را اجرا می‌کند.

---

## ۳. استقرار فایل‌ها

سامانه طوری طراحی شده که **فقط پوشه‌ی `public` از طریق وب در دسترس باشد**. بقیه‌ی پوشه‌ها (`src`، `config`، `storage`، `database`، `tools`) بیرون از ریشه‌ی وب می‌مانند.

```
C:\inetpub\sso\            ← محل استقرار کد
├── public\                ← DocumentRoot سایت در IIS
├── src\
├── config\
├── storage\
├── database\
└── tools\
```

کد را کپی یا کلون کنید:

```powershell
git clone <آدرس-مخزن> C:\inetpub\sso
```

اگر نمی‌توانید DocumentRoot را روی `public` تنظیم کنید (مثلاً هاست اشتراکی)، کل محتوا را در ریشه بگذارید؛ فایل `public/web.config` در این حالت مسیرهای حساس را مسدود می‌کند، اما گزینه‌ی اول امن‌تر است.

---

## ۴. مجوزهای نوشتن

حساب کاربریِ استخرِ برنامه (Application Pool) باید بتواند در این مسیرها بنویسد:

| مسیر | دلیل |
|---|---|
| `config\` | نوشتن `config.php` هنگام نصب |
| `storage\logs\` | لاگ خطاها و رویدادها |
| `storage\sessions\` | ذخیره‌ی نشست مدیریت (در صورت استفاده از save path سفارشی) |
| `storage\cache\` | کش داخلی |
| `storage\database\` | فقط در حالت SQLite |

در PowerShell (با دسترسی مدیر):

```powershell
$pool = "IIS AppPool\SsoPool"
$paths = @(
  "C:\inetpub\sso\config",
  "C:\inetpub\sso\storage",
  "C:\inetpub\sso\storage\logs",
  "C:\inetpub\sso\storage\sessions",
  "C:\inetpub\sso\storage\cache",
  "C:\inetpub\sso\storage\database"
)
foreach ($p in $paths) {
  if (-not (Test-Path $p)) { New-Item -ItemType Directory -Path $p -Force | Out-Null }
  icacls $p /grant "${pool}:(OI)(CI)M" /T
}
```

> نام استخر را با نام واقعی خودتان جایگزین کنید. اگر از `ApplicationPoolIdentity` استفاده نمی‌کنید، همان حساب را بدهید.

---

## ۵. پیکربندی IIS

### ۵.۱ نصب ماژول URL Rewrite

اگر نصب نیست، از [سایت رسمی مایکروسافت](https://www.iis.net/downloads/microsoft/url-rewrite) دریافت کنید. این ماژول برای مسیرهای API ضروری است.

### ۵.۲ ثبت PHP در IIS

فایل `public/web.config` همراه پروژه یک بلوکِ `<handlers>` دارد که PHP را از
طریق FastCGI ثبت می‌کند، بنابراین در بسیاری از موارد نیازی به کار دستی نیست.

دو حالت وجود دارد:

- **اگر PHP در `C:\php\php-cgi.exe` نصب است یا آن را اصلاح می‌کنید:** همان بلوک
  را نگه دارید و فقط مقدار `scriptProcessor` را با مسیر واقعی `php-cgi.exe`
  جایگزین کنید. بقیه‌ی موارد را رها کنید.
- **اگر PHP در سطح سرور ثبت شده** (IIS Manager → سطح سرور → Handler Mappings):
  بلوک `<handlers>` را از `web.config` **حذف** کنید تا تداخل ایجاد نشود.

ثبت دستی در IIS Manager (در صورت نیاز):

| فیلد | مقدار |
|---|---|
| Request path | `*.php` |
| Module | `FastCgiModule` |
| Executable | `C:\Program Files\PHP\v8.2\php-cgi.exe` |
| Name | `PHP_via_FastCGI` |

### ۵.۳ ساخت سایت

- **Physical path**: `C:\inetpub\sso\public`
- **Application pool**: یک استخر اختصاصی با `.NET CLR Version = No Managed Code`
- **Default document**: `index.php`

### ۵.۴ بازنویسی URL

فایل `public/web.config` که همراه پروژه است این کار را انجام می‌دهد (قانونِ `SsoApiRewrite`). اگر آن را تغییر داده‌اید، مطمئن شوید شامل این قاعده باشد:

```xml
<rule name="SsoApiRewrite" stopProcessing="true">
  <match url="^api/(.*)$" />
  <conditions logicalGrouping="MatchAll">
    <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
    <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
  </conditions>
  <action type="Rewrite" url="api/index.php/{R:1}" appendQueryString="true" />
</rule>
```

این قاعده همه‌ی درخواست‌های `/api/v1/...` را به `public/api/index.php` می‌فرستد.

### ۵.۵ مسدود کردن مسیرهای حساس

`public/web.config` دو لایه برای این کار دارد:

1. قانونِ بازنویسیِ `SsoBlockInternal` که درخواست‌های `src/`، `config/`، `storage/`، `database/`، `tests/`، `tools/`، `docs/` و چند نامِ رایجِ دیگر را با پاسخ ۴۰۴ رد می‌کند (۴۰۴ نه ۴۰۳، تا وجود مسیر معلوم نشود).
2. بخش `<security><requestFiltering>` که همان پوشه‌ها را در `<hiddenSegments>` و پسوندهای `.sql`، `.sqlite`، `.json`، `.lock`، `.log`، `.md` و `.env` را در `<fileExtensions>` مسدود می‌کند.

> توجه: سامانه هیچ فایل JSON ایستا سرو نمی‌کند (پاسخ‌های JSON را PHP تولید می‌کند)،
> بنابراین مسدود کردن `.json` بی‌خطر است. اگر بعداً فایلی مانند
> `public/manifest.json` اضافه کردید، آن خط را از `web.config` حذف کنید.

اگر در محیطی هستید که `web.config` نادیده گرفته می‌شود، این مسیرها را بیرون از ریشه‌ی وب قرار دهید (توصیه‌ی بخش ۳).

### ۵.۶ HTTPS

در production حتماً TLS فعال کنید. قانونی برای هدایت HTTP به HTTPS اضافه کنید:

```xml
<rule name="ForceHttps" stopProcessing="true">
  <match url="(.*)" />
  <conditions>
    <add input="{HTTPS}" pattern="^OFF$" />
  </conditions>
  <action type="Redirect" url="https://{HTTP_HOST}/{R:1}" redirectType="Permanent" />
</rule>
```

سپس در `config/config.php` مقدار `base_url` را با `https://` تنظیم کنید.

---

## ۶. اجرای نصاب

مرورگر را باز کنید:

```
http://<میزبان>/setup.php
```

سه مرحله:

1. **پیش‌نیازها** — نسخه‌ی PHP، افزونه‌ها و مجوز نوشتن بررسی می‌شود. همه باید سبز باشند.
2. **دیتابیس** — درایور `mysql` را انتخاب کنید و `host`، `port`، `database`، `username` و `password` را وارد کنید. می‌توانید گزینه‌ی ساخت اپلیکیشن نمونه را هم بزنید.
3. **مدیر ارشد** — ایمیل، نام و رمز مدیر کل را وارد کنید.

در پایان:

- فایل `config/config.php` نوشته می‌شود.
- قفل `storage/installed.lock` ساخته می‌شود (پس از آن `setup.php` دیگر در دسترس نیست).
- جفت کلید/راز اپلیکیشن نمونه یک بار نمایش داده می‌شود — **آن را یادداشت کنید**، بعداً قابل بازیابی نیست (فقط می‌توانید راز را بازتولید کنید).

اگر بعداً خواستید دوباره نصب کنید، فایل‌های `config/config.php` و `storage/installed.lock` را حذف کنید.

### نصب با خط فرمان (جایگزین)

```powershell
cd C:\inetpub\sso
php tools/install.php
```

---

## ۷. پس از نصب

### ورود به پنل مدیریت

```
https://auth.example.com/admin/
```

فقط کاربری که ستون `is_super_admin` در جدول `users` برایش برابر با ۱ باشد می‌تواند وارد شود. سایر کاربران حتی با رمز درست به صفحه‌ی ورود برمی‌گردند. نقش یک کاربر در اپلیکیشن‌ها (`owner`، `admin` و …) تأثیری در دسترسی به پنل ندارد.

### ساخت اپلیکیشن جدید

در پنل: **اپلیکیشن‌ها** → **جدید**. پس از ساخت، کلید و راز به شما داده می‌شود؛ راز فقط یک بار نمایش داده می‌شود.

یا از خط فرمان:

```powershell
php tools/create-app.php --name="فروشگاه" --slug=shop --callback=https://shop.example.com/callback
```

### ساخت مدیر دیگر

```powershell
php tools/create-admin.php --email=admin2@example.com --password=Secret1234 --name="مدیر دوم"
```

### بررسی سلامت

```bash
curl https://auth.example.com/api/v1/health
```

```json
{ "ok": true, "data": { "status": "ok", "database": { "driver": "mysql", "connected": true } } }
```

---

## ۸. وظایف دوره‌ای (Cron / Task Scheduler)

جدول‌های `login_attempts` و `rate_limits` به‌طور خودکار هنگام درخواست پاک‌سازی می‌شوند، اما اگر ترافیک کمی دارید بهتر است یک کار زمان‌بندی‌شده بگذارید:

```sql
DELETE FROM `login_attempts` WHERE `expires_at` < UTC_TIMESTAMP();
DELETE FROM `rate_limits`   WHERE `expires_at` < UTC_TIMESTAMP();
DELETE FROM `password_resets` WHERE `expires_at` < UTC_TIMESTAMP();
```

در **Task Scheduler** یک وظیفه‌ی روزانه بسازید:

```powershell
$task = @"
DELETE FROM login_attempts WHERE expires_at < UTC_TIMESTAMP();
DELETE FROM rate_limits WHERE expires_at < UTC_TIMESTAMP();
DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP();
"@
$task | Out-File C:\inetpub\sso\storage\cleanup.sql -Encoding utf8

schtasks /create /tn "SsoCleanup" /tr "`"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe`" -u sso_user -p<رمز> sso < C:\inetpub\sso\storage\cleanup.sql" /sc daily /st 03:00
```

> فایل حاوی رمز را در مسیری امن و خارج از ریشه‌ی وب نگه دارید.

---

## ۹. پشتیبان‌گیری

دو بخش را باید پشتیبان بگیرید:

1. **دیتابیس** — با `mysqldump`:

```powershell
mysqldump -u sso_user -p --single-transaction --routines sso > C:\backups\sso-$(Get-Date -Format yyyyMMdd).sql
```

2. **فایل `config/config.php`** — شامل `app_key` است؛ بدون آن توکن‌های موجود نامعتبر می‌شوند.

پوشه‌ی `storage/` نیازی به پشتیبان ندارد (لاگ و کش است).

---

## ۱۰. عیب‌یابی

| نشانه | علت احتمالی و راه‌حل |
|---|---|
| صفحه سفید | `display_errors` خاموش است. فایل `storage\logs\app.log` و `php-errors.log` را ببینید. |
| خطای ۴۰۴ روی `/api/v1/...` | ماژول URL Rewrite نصب نیست، یا `web.config` اعمال نشده. در IIS Manager قواعد را بررسی کنید. |
| خطای ۵۰۰ پس از نصب | مجوز نوشتن روی `storage\logs`. بخش ۴ را مرور کنید. |
| «فایل تنظیمات یافت نشد» | نصب انجام نشده یا `config\config.php` حذف شده. به `/setup.php` بروید. |
| خطای اتصال به دیتابیس | نام کاربری/رمز یا `host` را در `config\config.php` بررسی کنید. برای MySQL روی ویندوز گاهی `127.0.0.1` بهتر از `localhost` کار می‌کند (به‌خاطر named pipe). |
| خطای `could not find driver` | افزونه‌ی `pdo_mysql` در `php.ini` فعال نیست؛ IIS را پس از تغییر بازیابی (Recycle) کنید. |
| ورود مدیر ناموفق است، ولی رمز درست است | پرچم `is_super_admin` برای کاربر فعال نیست، یا وضعیت کاربر `active` نیست. با `php tools/create-admin.php` مدیر بسازید یا از بخش کاربران پنل پرچم را فعال کنید. |
| خطای CSRF در پنل | نشست منقضی شده یا تاریخ/ساعت سرور نامیزان است. صفحه را تازه‌سازی (Reload) کنید. |
| خروجی JSON نامفهوم/بریده | یک افزونه یا فایل PHP کاراکتر ناخواسته (BOM) چاپ می‌کند. فایل‌ها را با UTF-8 **بدون BOM** ذخیره کنید. |
| لاگ‌ها خالی می‌مانند | مجوز نوشتن روی `storage\logs` را بررسی کنید. |

### ابزار عیب‌یابیِ پیش‌نیازها

اگر مرحله‌ی ۱ یا ۲ نصاب وب رد نمی‌شود، به‌جای حدس زدن این ابزار را اجرا کنید.
هیچ چیزی را تغییر نمی‌دهد؛ فقط گزارش می‌دهد:

```powershell
cd C:\inetpub\sso
php tools\check-requirements.php
```

خروجی شامل: نسخه و SAPI ی PHP، مسیر دقیقِ `php.ini`، کاربری که PHP با آن اجرا
می‌شود، وضعیت تک‌تک پیش‌نیازها (با جزئیات و راه‌حل)، و مسیر مطلقِ پوشه‌هایی
که باید قابل نوشتن باشند.

برای تست اتصال دیتابیس هم می‌توانید مشخصات را بدهید تا خطای دقیقِ PDO چاپ شود:

```powershell
php tools\check-requirements.php --host=127.0.0.1 --port=3306 --database=sso --username=sso_user --password=<رمز>
```

نمونه‌ای از خروجی وقتی یک پوشه قابل نوشتن نیست:

```
[خطا]  نوشتن در storage/logs — قابل نوشتن نیست
       جزئیات: C:\inetpub\sso\storage\logs
       راه‌حل: نوشتن در پوشه انجام نشد (Failed to open stream: Permission denied)
               — در IIS دسترسیِ Modify را برای حسابِ استخرِ برنامه
                 (IIS AppPool\<نام-استخر>) روی این پوشه بدهید.
```

> نکته: نصاب وب همین اطلاعات را در صفحه نشان می‌دهد (زیر هر موردِ خطادار،
> بخش‌های «جزئیات» و «راه‌حل»). ابزارِ خط فرمان برای وقتی است که می‌خواهید
> خروجی را کپی کنید یا دسترسیِ مرورگر ندارید.

### نکته‌ی مهم: نسخه‌ی PHP و افزونه‌ی random

نصاب برای بررسیِ پیش‌نیازها، **تواناییِ مورد نیاز** را آزمایش می‌کند، نه
نامِ افزونه را. علت:

افزونه‌ی `random` (که شامل `random_bytes` است) فقط از **PHP 8.2** وجود
دارد. روی PHP 8.1 تابعِ `extension_loaded('random')` همیشه `false`
برمی‌گرداند، در حالی که `random_bytes()` از PHP 7 در هسته‌ی PHP هست و
به‌درستی کار می‌کند. (در نسخه‌ی اولیه‌ی این سامانه بررسی بر اساسِ نامِ
افزونه بود و روی PHP 8.1 مرحله‌ی ۱ همیشه خطا می‌داد، بدون اینکه خطی به نام
`extension=random` برای فعال کردن وجود داشته باشد.)

حداقل نسخه‌ی پشتیبانی‌شده **PHP 8.1** است.

### بررسی سریع

```powershell
php -v
php -m | Select-String -Pattern "pdo_mysql|mbstring|openssl|sodium"
php -r "var_dump(class_exists('PDO'), PDO::getAvailableDrivers());"
Get-WebGlobalModule | Where-Object { $_.Name -like "*Rewrite*" }
```

> **نکته‌ی مهم روی ویندوز:** تابعِ `is_writable()` در PHP روی ویندوز قابل اعتماد
> نیست، چون مجوزها بر پایه‌ی ACL است و این تابع فقط ویژگیِ readonly را می‌بیند.
> نصاب این سامانه به آن تکیه نمی‌کند و یک فایلِ موقت واقعاً می‌نویسد و پاک
> می‌کند تا نتیجه قطعی باشد.

---

## ۱۱. به‌روزرسانی

```powershell
cd C:\inetpub\sso
git pull
# در صورت تغییر شِما، فایل migration مربوط را اجرا کنید
```

فایل‌های `config/config.php` و `storage/` در git نادیده گرفته شده‌اند و دست‌نخورده می‌مانند. همیشه پیش از به‌روزرسانی از دیتابیس پشتیبان بگیرید.
