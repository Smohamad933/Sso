# مرجع API (نسخه‌ی ۱)

همه‌ی مسیرها با پیشوند `/api` شروع می‌شوند. اگر سامانه روی `https://auth.example.com` باشد، نشانی کامل این است:

```
https://auth.example.com/api/v1/...
```

---

## فهرست

- [احراز هویت درخواست](#احراز-هویت-درخواست)
- [قالب پاسخ](#قالب-پاسخ)
- [محدودیت نرخ](#محدودیت-نرخ)
- [CORS](#cors)
- [سلامت](#سلامت)
- [احراز هویت کاربران](#احراز-هویت-کاربران)
- [مدیریت کاربران یک اپلیکیشن](#مدیریت-کاربران-یک-اپلیکیشن)
- [کاربر جاری](#کاربر-جاری)
- [اپلیکیشن جاری](#اپلیکیشن-جاری)
- [کدهای خطا](#کدهای-خطا)
- [شِمای آبجکت کاربر](#شمای-آبجکت-کاربر)

---

## احراز هویت درخواست

### ۱. کلید و راز اپلیکیشن

برای مسیرهایی که با سطح **`app`** مشخص شده‌اند. سه شیوه پشتیبانی می‌شود (هر کدام را انتخاب کنید):

```http
X-Api-Key: ak_live_xxxxxxxxxxxxxxxx
X-Api-Secret: <api_secret>
```

```http
Authorization: Key ak_live_xxxxxxxxxxxxxxxx
```

```http
Authorization: Basic base64(api_key:api_secret)
```

> در شیوه‌ی `Basic`، مقدارِ پس از واژه‌ی `Basic` همان `base64("<api_key>:<api_secret>")` است.

### ۲. توکن کاربر

برای مسیرهایی که با سطح **`user`** مشخص شده‌اند:

```http
Authorization: Bearer <access_token>
```

### سطوح دسترسی

| سطح | نیازمندی |
|---|---|
| `public` | هیچ. |
| `app` | فقط کلید + راز اپلیکیشن. |
| `optional_app` | کلید + راز در صورت ارسال (اختیاری). |
| `user` | فقط توکن معتبر کاربر. |
| `app_or_user` | کلید/راز اپلیکیشن **یا** توکن کاربر. |

### بدنه‌ی درخواست

همه‌ی مسیرهای نوشتاری `application/json` و همچنین `application/x-www-form-urlencoded` را می‌پذیرند. پاسخ همواره JSON است.

---

## قالب پاسخ

### موفق

```json
{
  "ok": true,
  "data": { }
}
```

> برخی مسیرها برای سازگاری، کلید `data` را هم‌سطح با بقیه می‌فرستند. در همه‌ی پاسخ‌های موفق `"ok": true` وجود دارد.

### خطا

```json
{
  "ok": false,
  "error": {
    "code": "validation_error",
    "message": "داده‌های ورودی معتبر نیست.",
    "details": {
      "email": "فرمت ایمیل معتبر نیست."
    }
  }
}
```

فیلد `details` برای خطاهای اعتبارسنجی (۴۲۲) یک آبجکتِ «فیلد → پیام» است و در بقیه‌ی خطاها `null` یا یک آبجکت کمکی (مثلاً `allowed_methods`).

---

## محدودیت نرخ

- سقفِ کلی برای هر اپلیکیشن: `security.api_rate_limit_per_minute` (پیش‌فرض ۶۰۰ در دقیقه).
- تلاش‌های ناموفق ورود: ۳۰ تلاش در ۱۵ دقیقه برای هر IP، و ۱۵ تلاش در ۱۵ دقیقه برای هر ایمیل.
- تلاش ناموفق ورود مدیر: ۱۰ تلاش در ۱۵ دقیقه برای هر IP.

هر پاسخ شامل این هدرهاست:

```http
X-RateLimit-Limit: 600
X-RateLimit-Remaining: 597
```

در صورت عبور از سقف، پاسخ `429` با کد `rate_limit_exceeded` یا `too_many_attempts` برمی‌گردد.

---

## CORS

درخواست `OPTIONS` با پاسخ `204` و هدرهای زیر پاسخ داده می‌شود:

```http
Access-Control-Allow-Origin: <Origin یا *>
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key, X-Api-Secret, X-Requested-With
Access-Control-Max-Age: 86400
Vary: Origin
```

---

## سلامت

### `GET /v1/health`

سطح: `public`

```bash
curl https://auth.example.com/api/v1/health
```

```json
{
  "ok": true,
  "data": {
    "status": "ok",
    "time": "2026-10-06 10:12:33",
    "database": { "driver": "mysql", "connected": true },
    "php": "8.2.10",
    "api_version": "v1"
  }
}
```

زمان‌ها به‌وقت UTC و با قالب `Y-m-d H:i:s` هستند. اگر اتصال به دیتابیس برقرار نباشد، `status` برابر `degraded` و کد پاسخ `503` است. در حالت `debug` فهرست مسیرها هم با کلید `routes` اضافه می‌شود.

---

## احراز هویت کاربران

### `POST /v1/auth/register`

سطح: `app` — ثبت‌نام کاربر جدید و اتصال خودکار به اپلیکیشن فراخوان.

| فیلد | نوع | اجباری | توضیح |
|---|---|---|---|
| `email` | string | بله | یکتا در کل سامانه. |
| `password` | string | بله | حداقل `security.password_min_length` کاراکتر (پیش‌فرض ۸). |
| `full_name` | string | خیر | حداکثر ۱۵۰ کاراکتر. |
| `phone` | string | خیر | حداکثر ۳۲ کاراکتر. |
| `avatar_url` | string(url) | خیر | حداکثر ۵۰۰ کاراکتر. |
| `role` | enum | خیر | یکی از `owner`، `admin`، `member`، `viewer`. فقط نقش‌های هم‌رتبه یا پایین‌تر از نقش اپلیکیشن پذیرفته می‌شود. |
| `metadata` | object | خیر | داده‌ی دلخواه اپلیکیشن. |

```bash
curl -X POST https://auth.example.com/api/v1/auth/register \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: ak_live_xxx' -H 'X-Api-Secret: ...' \
  -d '{
    "email": "ali@example.com",
    "password": "Secret1234",
    "full_name": "علی رضایی",
    "phone": "09123456789",
    "role": "member",
    "metadata": { "plan": "free" }
  }'
```

پاسخ `201`:

```json
{
  "ok": true,
  "data": {
    "user": { "id": 12, "email": "ali@example.com", "role": "member" },
    "tokens": {
      "access_token": "...",
      "refresh_token": "...",
      "token_type": "Bearer",
      "expires_in": 3600,
      "refresh_expires_in": 2592000,
      "scopes": ["profile:read", "profile:write"]
    }
  }
}
```

اگر کاربری با این ایمیل از پیش در سامانه باشد، پاسخ `409` با کد `email_taken` برمی‌گردد (برای اتصال دادن کاربر موجود، از `POST /v1/users` با `link_existing` استفاده کنید).

---

### `POST /v1/auth/login`

سطح: `app` — ورود کاربرِ عضوِ همین اپلیکیشن.

```json
{ "email": "ali@example.com", "password": "Secret1234" }
```

پاسخ `200` همان ساختارِ `register` را دارد (کلیدهای `user` و `tokens`).

خطاهای احتمالی:

| کد | وضعیت | زمان |
|---|---|---|
| `invalid_credentials` | ۴۰۱ | ایمیل یا رمز اشتباه (پیام همیشه یکسان است تا وجود حساب فاش نشود). |
| `account_locked` | ۴۲۹ | حساب به‌دلیل تلاش‌های ناموفق موقتاً قفل شده. |
| `account_inactive` | ۴۰۳ | حساب کاربر `active` نیست. |
| `membership_suspended` | ۴۰۳ | عضویت کاربر در این اپلیکیشن مسدود شده. |
| `not_a_member` | ۴۰۳ | کاربر اصلاً عضو این اپلیکیشن نیست. |
| `app_disabled` | ۴۰۳ | خود اپلیکیشن غیرفعال است. |
| `too_many_attempts` | ۴۲۹ | تلاش‌های زیاد. |

---

### `POST /v1/auth/refresh`

سطح: `optional_app` — تمدید توکن.

```json
{ "refresh_token": "..." }
```

پاسخ `200` شامل یک جفت توکن جدید است. توکن تمدیدِ مصرف‌شده بلافاصله باطل می‌شود (هر توکن تمدید فقط یک‌بار قابل استفاده است). تنها نشستِ مربوط به همان توکن باطل می‌شود، نه بقیه‌ی نشست‌های کاربر.

خطا: `401` با کد `invalid_token`.

---

### `POST /v1/auth/logout`

سطح: `user` — ابطال توکن جاری.

```json
{ "everywhere": false }
```

اگر `everywhere` برابر `true` باشد، همه‌ی توکن‌های این کاربر (در همه‌ی نشست‌ها) باطل می‌شود.

```json
{ "ok": true, "data": { "revoked": true, "everywhere": false } }
```

---

### `POST /v1/auth/introspect`

سطح: `app_or_user` — بررسی اعتبار یک توکن.

```json
{ "token": "<access_token یا refresh_token>" }
```

پاسخ در صورت معتبر بودن:

```json
{
  "ok": true,
  "data": {
    "active": true,
    "token_type": "access",
    "scopes": ["profile:read", "profile:write"],
    "expires_at": "2026-10-06 11:12:33",
    "app_id": 1,
    "user": { }
  }
}
```

در صورت نامعتبر بودن: `{"ok": true, "data": {"active": false}}` (وضعیت HTTP همواره ۲۰۰ است). اگر فراخوان با کلید/راز اپلیکیشن انجام شود و توکن متعلق به اپلیکیشن دیگری باشد، `active: false` برمی‌گردد.

---

### `POST /v1/auth/password/forgot`

سطح: `app` — صدور توکن بازیابی رمز.

```json
{ "email": "ali@example.com" }
```

```json
{
  "ok": true,
  "data": {
    "sent": true,
    "expires_in": 3600,
    "token": "...",
    "message": "توکن بازیابی صادر شد. اپلیکیشن باید آن را از طریق ایمیل برای کاربر بفرستد."
  }
}
```

اگر ایمیل وجود نداشته باشد یا کاربر عضو این اپلیکیشن نباشد، پاسخ یکسان اما **بدون** کلیدهای `token` و `expires_in` برمی‌گردد تا وجود حساب فاش نشود.

> سامانه ایمیل نمی‌فرستد؛ تحویل توکن به کاربر بر عهده‌ی اپلیکیشن شماست.

---

### `POST /v1/auth/password/reset`

سطح: `app` — تنظیم رمز جدید با توکن بازیابی.

```json
{ "token": "...", "password": "NewSecret1234" }
```

```json
{ "ok": true, "data": { "reset": true, "user": { } } }
```

پس از بازیابی موفق، همه‌ی توکن‌های قبلی کاربر باطل می‌شود.

---

### `POST /v1/auth/verify-email`

سطح: `app` — تأیید ایمیل کاربر.

```json
{ "user_id": 12 }
```

`user_id` می‌تواند شناسه‌ی عددی یا `uuid` باشد. اگر وضعیت کاربر `pending` باشد، به `active` تغییر می‌کند.

```json
{ "ok": true, "data": { "verified": true, "user": { } } }
```

---

## مدیریت کاربران یک اپلیکیشن

این گروه مخصوص واگذاری مدیریت کاربران به اپلیکیشن‌هاست. همه با سطح **`app`** فراخوانی می‌شوند و فقط روی کاربرانِ عضوِ همان اپلیکیشن اثر می‌گذارند.

### `GET /v1/users` — فهرست کاربران

پارامترهای query:

| پارامتر | توضیح |
|---|---|
| `q` | جست‌وجو در ایمیل و نام، یا تطبیق دقیق با `uuid`. |
| `status` | فیلتر بر اساس وضعیت حساب: `active`، `pending`، `suspended`، `disabled`. |
| `role` | فیلتر بر اساس نقش در این اپلیکیشن. |
| `sort` | یکی از `id`، `email`، `full_name`، `created_at`، `last_login_at`، `status` (پیش‌فرض `id`). |
| `direction` | `asc` یا `desc` (پیش‌فرض `desc`). |
| `page` | شماره صفحه (پیش‌فرض ۱). |
| `per_page` | تعداد در هر صفحه، حداکثر ۱۰۰ (پیش‌فرض ۲۵). |

```json
{
  "ok": true,
  "data": {
    "items": [ { } ],
    "total": 120,
    "page": 1,
    "per_page": 25,
    "pages": 5
  }
}
```

هر آیتم شامل `memberships` (فهرست همه‌ی اپلیکیشن‌های کاربر) و، در صورت عضویت در اپلیکیشن فراخوان، `role` و `membership_status` هم هست.

---

### `POST /v1/users` — ساخت یا اتصال کاربر

مانند `auth/register` است با این تفاوت‌ها:

| فیلد | توضیح |
|---|---|
| `password` | **اختیاری**. اگر نفرستید، سامانه یک رمز تصادفی می‌سازد و با کلید `generated_password` برمی‌گرداند. |
| `link_existing` | اگر `true` باشد و کاربر از پیش وجود داشته باشد، به‌جای خطا به اپلیکیشن متصل می‌شود. |
| `email_verified` | اگر `true` باشد، ایمیل از ابتدا تأییدشده فرض می‌شود. |

پاسخ `201`:

```json
{
  "ok": true,
  "data": {
    "user": { "id": 12, "role": "member" },
    "generated_password": "sk_a1b2c3d4e5f6g7h8"
  }
}
```

خطاها:

| کد | وضعیت | زمان |
|---|---|---|
| `email_taken` | ۴۰۹ | کاربر موجود است و `link_existing` ارسال نشده. |
| `already_registered` | ۴۰۹ | کاربر موجود است و از پیش عضو این اپلیکیشن است. |
| `weak_password` | ۴۲۲ | رمز ارسالی ضعیف است. |

---

### `GET /v1/users/{id}` — دریافت یک کاربر

`{id}` می‌تواند شناسه‌ی عددی یا `uuid` باشد. پاسخ شامل `membership_metadata` (متادیتای عضویت در این اپلیکیشن) هم هست.

---

### `PATCH /v1/users/{id}` — به‌روزرسانی

همه‌ی فیلدها اختیاری‌اند؛ فقط فیلدهای ارسالی تغییر می‌کنند.

| فیلد | توضیح |
|---|---|
| `email` | با بررسی یکتایی. |
| `full_name`، `phone`، `avatar_url` | اطلاعات پروفایل. |
| `metadata` | متادیتای سراسری کاربر. |
| `membership_metadata` | متادیتای عضویت در این اپلیکیشن. |
| `role` | تغییر نقش در این اپلیکیشن. |
| `email_verified` | `true` یا `false`. |

نکته: تغییر `membership_metadata` وضعیت عضویت را تغییر نمی‌دهد؛ کاربرِ مسدود، مسدود می‌ماند.

---

### `DELETE /v1/users/{id}` — قطع دسترسی

عضویت کاربر را از این اپلیکیشن حذف می‌کند (حساب سراسری کاربر حذف نمی‌شود، فقط دسترسی‌اش به این اپلیکیشن قطع می‌شود).

```json
{ "ok": true, "data": { "deleted": true, "user_id": 12 } }
```

---

### `POST /v1/users/{id}/role` — تغییر نقش

```json
{ "role": "admin" }
```

فقط می‌توان نقشی هم‌رتبه یا پایین‌تر از رتبه‌ی اپلیکیشن فراخوان را اختصاص داد (`owner` > `admin` > `member` > `viewer`).

---

### `POST /v1/users/{id}/suspend` — مسدود کردن

عضویت کاربر در این اپلیکیشن را مسدود می‌کند و همه‌ی توکن‌های او را برای این اپلیکیشن باطل می‌کند. پاسخ، آبجکت به‌روزشده‌ی کاربر است.

---

### `POST /v1/users/{id}/activate` — فعال‌سازی

عضویت را به `active` برمی‌گرداند. پاسخ، آبجکت به‌روزشده‌ی کاربر است.

---

### `POST /v1/users/{id}/password` — تنظیم مستقیم رمز

```json
{ "password": "NewSecret1234" }
```

مخصوص مدیر اپلیکیشن؛ نیازی به رمز قبلی نیست.

```json
{ "ok": true, "data": { "updated": true, "user_id": 12 } }
```

---

### `POST /v1/users/{id}/password-reset` — صدور توکن بازیابی

```json
{ "ok": true, "data": { "token": "...", "expires_at": "...", "expires_in": 3600, "user_id": 12 } }
```

---

### `POST /v1/users/{id}/verify-email` — تأیید ایمیل

```json
{ "ok": true, "data": { "verified": true, "user": { } } }
```

---

## کاربر جاری

این گروه با سطح **`user`** است (توکن کاربر).

### `GET /v1/me`

```json
{
  "ok": true,
  "data": {
    "user": { },
    "token": {
      "scopes": ["profile:read", "profile:write"],
      "expires_at": "2026-10-06 11:12:33",
      "app_id": 1
    }
  }
}
```

### `PATCH /v1/me`

فیلدهای قابل تغییر: `full_name`، `phone`، `avatar_url`، `metadata`. کاربر **نمی‌تواند** ایمیل یا نقش خودش را تغییر دهد.

### `POST /v1/me/password`

```json
{ "current_password": "Old12345", "new_password": "NewSecret1234" }
```

پس از تغییر موفق، همه‌ی توکن‌های قبلی کاربر باطل می‌شود.

### `GET /v1/me/apps`

فهرست اپلیکیشن‌هایی که کاربر در آن‌ها عضویت دارد، همراه با نقش در هرکدام.

```json
{ "ok": true, "data": { "apps": [ { "id": 1, "name": "فروشگاه", "role": "member" } ] } }
```

### `POST /v1/me/logout`

خروج از همه‌ی نشست‌ها (ابطال همه‌ی توکن‌های کاربر در همه‌ی اپلیکیشن‌ها).

```json
{ "ok": true, "data": { "revoked": 3 } }
```

---

## اپلیکیشن جاری

سطح **`app`**. این مسیرها به اپلیکیشن اجازه می‌دهد تنظیماتِ خودش را بخواند/تغییر دهد. (ساخت اپلیکیشن جدید فقط از پنل مدیریت یا خط فرمان ممکن است.)

### `GET /v1/apps/me`

```json
{ "ok": true, "data": { "app": { "id": 1, "name": "فروشگاه", "slug": "shop" } } }
```

فیلد `app` شامل تنظیمات اپلیکیشن هم هست.

### `PATCH /v1/apps/me`

فیلدهای قابل تغییر: `name`، `description`، `webhook_url`.

فیلد `settings` (آبجکت) فقط این کلیدها را می‌پذیرد:

| کلید | توضیح |
|---|---|
| `allow_registration` | اجازه‌ی ثبت‌نام خودکار. |
| `require_email_verification` | نیاز به تأیید ایمیل هنگام ثبت‌نام. |
| `default_role` | نقش پیش‌فرض کاربران جدید. |
| `access_token_ttl` | عمر توکن دسترسی برای این اپلیکیشن (ثانیه). |
| `refresh_token_ttl` | عمر توکن تمدید برای این اپلیکیشن (ثانیه). |
| `allowed_origins` | فهرست مبدأهای مجاز (CORS). |
| `metadata_fields` | فیلدهای دلخواهِ تعریف‌شده برای کاربران. |

### `GET /v1/apps/me/stats`

```json
{
  "ok": true,
  "data": {
    "app": { "id": 1, "name": "فروشگاه" },
    "users": 120,
    "active_tokens": 34
  }
}
```

`users` تعداد اعضای این اپلیکیشن و `active_tokens` تعداد توکن‌های باطل‌نشده و منقضی‌نشده است.

---

## کدهای خطا

| وضعیت | کد | معنا |
|---|---|---|
| ۴۰۰ | `bad_request` | درخواست نامعتبر (مثلاً JSON خراب). |
| ۴۰۱ | `invalid_credentials` | ایمیل یا رمز اشتباه. |
| ۴۰۱ | `invalid_token` | توکن نامعتبر، منقضی یا باطل‌شده. |
| ۴۰۱ | `unauthorized` | اعتبارنامه ارسال نشده. |
| ۴۰۳ | `forbidden` | دسترسی مجاز نیست. |
| ۴۰۳ | `account_inactive` | حساب غیرفعال است. |
| ۴۰۳ | `membership_suspended` | عضویت در این اپلیکیشن مسدود است. |
| ۴۰۳ | `not_a_member` | کاربر عضو این اپلیکیشن نیست. |
| ۴۰۳ | `app_disabled` | اپلیکیشن غیرفعال است. |
| ۴۰۴ | `not_found` | مسیر یا منبع یافت نشد. |
| ۴۰۵ | `method_not_allowed` | متد مجاز نیست (فهرست متدها در `details.allowed_methods`). |
| ۴۰۹ | `email_taken` | ایمیل تکراری. |
| ۴۰۹ | `already_registered` | کاربر از پیش عضو این اپلیکیشن است. |
| ۴۲۲ | `validation_error` | خطای اعتبارسنجی (جزئیات در `details`). |
| ۴۲۲ | `weak_password` | رمز عبور ضعیف. |
| ۴۲۹ | `rate_limit_exceeded` | عبور از سقف نرخ درخواست. |
| ۴۲۹ | `too_many_attempts` | تلاش‌های ناموفق زیاد. |
| ۴۲۹ | `account_locked` | حساب موقتاً قفل شده. |
| ۵۰۰ | `server_error` | خطای داخلی (جزئیات در لاگ سرور). |
| ۵۰۳ | `service_unavailable` | دیتابیس در دسترس نیست. |

---

## شِمای آبجکت کاربر

```json
{
  "id": 12,
  "uuid": "7f3c1e2a-...",
  "email": "ali@example.com",
  "email_verified": true,
  "full_name": "علی رضایی",
  "phone": "09123456789",
  "avatar_url": null,
  "status": "active",
  "metadata": { "plan": "free" },
  "created_at": "2026-10-06 09:00:00",
  "updated_at": "2026-10-06 09:30:00",
  "last_login_at": "2026-10-06 10:00:00",
  "role": "member",
  "membership_status": "active",
  "app": { "id": 1, "name": "فروشگاه", "slug": "shop" },
  "memberships": [
    { "app_id": 1, "app_name": "فروشگاه", "role": "member", "status": "active" }
  ]
}
```

وضعیت‌های حساب (`status`): `active`، `pending`، `suspended`، `disabled`.

وضعیت‌های عضویت (`membership_status`): `active`، `suspended`.

فیلدهای `role`، `membership_status` و `app` فقط زمانی حضور دارند که درخواست در چارچوب یک اپلیکیشن مشخص انجام شده باشد.

---

## نکته‌ی امنیتی برای کلاینت‌ها

- کلید و راز اپلیکیشن را **هرگز** در کدِ سمت مرورگر قرار ندهید؛ فقط در سرورِ خودتان نگه دارید و درخواست‌ها را از آنجا واسطه‌گری کنید.
- توکن کاربر را در سمت کلاینت در حافظه یا کوکی `HttpOnly` نگه دارید، نه در `localStorage` اگر امکانش هست.
- پیش از هر عمل حساس، با `POST /v1/auth/introspect` اعتبار توکن را بررسی کنید؛ ابطال در سامانه بلافاصله اثر می‌کند.
