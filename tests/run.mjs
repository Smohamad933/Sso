#!/usr/bin/env node
/**
 * مجموعه‌ی تست‌های یکپارچه.
 *
 *   cd tests && npm install && node run.mjs
 */

import {
  createRuntime,
  installProject,
  lintProject,
  architectureCheck,
  api,
  admin,
  headerOf,
} from './harness.mjs';

let passed = 0;
let failed = 0;
const failures = [];

function check(name, condition, detail = '') {
  if (condition) {
    passed++;
    console.log('  ✓ ' + name);
  } else {
    failed++;
    failures.push(name + (detail ? ' — ' + detail : ''));
    console.log('  ✗ ' + name + (detail ? '\n      ' + String(detail).replace(/\n/g, '\n      ') : ''));
  }
}

/**
 * ساخت کد PHP از آرایه‌ی خطوط.
 * (استفاده از آرایه به‌جای template literal، escape کردنِ بک‌تیک‌ها را بی‌نیاز می‌کند)
 */
function php(lines) {
  const body = lines[0] === '<?php' ? lines.slice(1) : lines;
  return '<?php\n' + body.join('\n') + '\n';
}

function section(title) {
  console.log('\n\x1b[1m' + title + '\x1b[0m');
}

function eq(name, actual, expected) {
  check(name, actual === expected, `انتظار: ${JSON.stringify(expected)} — دریافت: ${JSON.stringify(actual)}`);
}

async function main() {
  console.log('در حال راه‌اندازی PHP 8.3 (wasm) ...');
  const runtime = await createRuntime();

  console.log('در حال بررسی سینتکس فایل‌ها ...');
  {
    const lint = await lintProject(runtime);
    check('تعداد فایل‌های بررسی‌شده معقول است', (lint.checked ?? 0) > 25, 'checked=' + lint.checked);
    check('هیچ خطای سینتکسی وجود ندارد', (lint.errors ?? []).length === 0, JSON.stringify(lint.errors, null, 2));
  }

  console.log('در حال نصب پروژه ...');
  const installed = await installProject(runtime);
  const creds = { 'X-Api-Key': installed.apiKey, 'X-Api-Secret': installed.apiSecret };

  // ---------------------------------------------------------------- health
  section('سلامت سامانه');
  {
    const res = await api(runtime, 'GET', '/v1/health');
    eq('health سبز است', res.status, 200);
    eq('ok برابر true', res.json?.ok, true);
    eq('دیتابیس متصل است', res.json?.data?.database?.connected, true);
    eq('درایور sqlite', res.json?.data?.database?.driver, 'sqlite');
  }

  // ---------------------------------------------------------------- احراز هویت اپ
  section('احراز هویت اپلیکیشن');
  {
    const res = await api(runtime, 'GET', '/v1/users');
    eq('بدون کلید => 401', res.status, 401);
    eq('کد خطا', res.json?.error?.code, 'app_credentials_required');
  }
  {
    const res = await api(runtime, 'GET', '/v1/users', {
      headers: { 'X-Api-Key': installed.apiKey, 'X-Api-Secret': 'wrong-secret' },
    });
    eq('سکرت اشتباه => 401', res.status, 401);
  }
  {
    const res = await api(runtime, 'GET', '/v1/apps/me', { headers: creds });
    eq('اطلاعات اپ => 200', res.status, 200);
    check('slug وجود دارد', typeof res.json?.data?.app?.slug === 'string');
  }

  // ---------------------------------------------------------------- ثبت‌نام
  section('ثبت‌نام');
  let accessToken = null;
  let refreshToken = null;
  let userId = null;
  const email = 'user1@example.com';

  {
    const res = await api(runtime, 'POST', '/v1/auth/register', {
      headers: { ...creds, 'Content-Type': 'application/json' },
      json: { email, password: 'UserPass123', full_name: 'کاربر یک', metadata: { city: 'تهران' } },
    });
    eq('ثبت‌نام => 201', res.status, 201);
    check('access_token برگشت', typeof res.json?.data?.tokens?.access_token === 'string');
    check('refresh_token برگشت', typeof res.json?.data?.tokens?.refresh_token === 'string');
    eq('token_type', res.json?.data?.tokens?.token_type, 'Bearer');
    eq('ایمیل درست', res.json?.data?.user?.email, email);
    eq('متادیتا ذخیره شد', res.json?.data?.user?.metadata?.city, 'تهران');
    eq('نقش پیش‌فرض', res.json?.data?.user?.role, 'member');

    accessToken = res.json?.data?.tokens?.access_token ?? null;
    refreshToken = res.json?.data?.tokens?.refresh_token ?? null;
    userId = res.json?.data?.user?.id ?? null;
  }
  {
    const res = await api(runtime, 'POST', '/v1/auth/register', {
      headers: creds,
      json: { email: 'bad-email', password: 'UserPass123' },
    });
    eq('ایمیل نامعتبر => 422', res.status, 422);
    eq('کد خطا', res.json?.error?.code, 'validation_error');
  }
  {
    const res = await api(runtime, 'POST', '/v1/auth/register', {
      headers: creds,
      json: { email: 'weak@example.com', password: '123' },
    });
    eq('رمز کوتاه => 422', res.status, 422);
  }
  {
    const res = await api(runtime, 'POST', '/v1/auth/register', {
      headers: creds,
      json: { email, password: 'UserPass123' },
    });
    eq('ثبت‌نام تکراری => 409', res.status, 409);
    eq('کد خطا', res.json?.error?.code, 'already_registered');
  }
  {
    const res = await api(runtime, 'POST', '/v1/auth/register', {
      headers: creds,
      json: { email, password: 'WrongPass123' },
    });
    eq('رمز اشتباه برای ایمیل موجود => 409', res.status, 409);
  }

  // ---------------------------------------------------------------- ورود
  section('ورود');
  {
    const res = await api(runtime, 'POST', '/v1/auth/login', {
      headers: creds,
      json: { email, password: 'UserPass123' },
    });
    eq('ورود موفق => 200', res.status, 200);
    check('توکن صادر شد', typeof res.json?.data?.tokens?.access_token === 'string');
    accessToken = res.json?.data?.tokens?.access_token ?? accessToken;
    refreshToken = res.json?.data?.tokens?.refresh_token ?? refreshToken;
  }
  {
    const res = await api(runtime, 'POST', '/v1/auth/login', {
      headers: creds,
      json: { email, password: 'NopePass123' },
    });
    eq('رمز اشتباه => 401', res.status, 401);
    eq('پیام عمومی', res.json?.error?.code, 'invalid_credentials');
  }
  {
    const res = await api(runtime, 'POST', '/v1/auth/login', {
      headers: creds,
      json: { email: 'nobody@example.com', password: 'NopePass123' },
    });
    eq('کاربر ناموجود => 401', res.status, 401);
  }

  // ---------------------------------------------------------------- me
  section('کاربر جاری (me)');
  {
    const res = await api(runtime, 'GET', '/v1/me', {
      headers: { Authorization: 'Bearer ' + accessToken },
    });
    eq('me => 200', res.status, 200);
    eq('ایمیل', res.json?.data?.user?.email, email);
  }
  {
    const res = await api(runtime, 'GET', '/v1/me', {
      headers: { Authorization: 'Bearer invalid-token' },
    });
    eq('توکن نامعتبر => 401', res.status, 401);
  }
  {
    const res = await api(runtime, 'GET', '/v1/me');
    eq('بدون توکن => 401', res.status, 401);
  }
  {
    const res = await api(runtime, 'PATCH', '/v1/me', {
      headers: { Authorization: 'Bearer ' + accessToken },
      json: { full_name: 'کاربر یک ویرایش‌شده', metadata: { city: 'شیراز', age: 30 } },
    });
    eq('ویرایش پروفایل => 200', res.status, 200);
    eq('نام جدید', res.json?.data?.user?.full_name, 'کاربر یک ویرایش‌شده');
    eq('متادیتا جدید', res.json?.data?.user?.metadata?.city, 'شیراز');
  }
  {
    const res = await api(runtime, 'POST', '/v1/me/password', {
      headers: { Authorization: 'Bearer ' + accessToken },
      json: { current_password: 'WrongPass123', new_password: 'NewUserPass123' },
    });
    eq('رمز فعلی اشتباه => 422', res.status, 422);
  }

  // ---------------------------------------------------------------- تمدید
  section('تمدید توکن');
  {
    const res = await api(runtime, 'POST', '/v1/auth/refresh', {
      headers: creds,
      json: { refresh_token: refreshToken },
    });
    eq('تمدید => 200', res.status, 200);
    check('توکن جدید', typeof res.json?.data?.access_token === 'string');
    const newRefresh = res.json?.data?.refresh_token;

    const again = await api(runtime, 'POST', '/v1/auth/refresh', {
      headers: creds,
      json: { refresh_token: refreshToken },
    });
    eq('تمدید مجدد با توکن قدیمی => 401', again.status, 401);

    refreshToken = newRefresh;
    accessToken = res.json?.data?.access_token;
  }

  // ---------------------------------------------------------------- مدیریت کاربران
  section('مدیریت کاربران از طریق اپ');
  {
    const res = await api(runtime, 'GET', '/v1/users', { headers: creds, query: { per_page: 10 } });
    eq('فهرست => 200', res.status, 200);
    check('آیتم‌ها وجود دارد', Array.isArray(res.json?.data?.items));
    eq('حداقل یک کاربر', res.json?.data?.items?.length >= 1, true);
  }
  {
    const res = await api(runtime, 'GET', '/v1/users/' + userId, { headers: creds });
    eq('نمایش کاربر => 200', res.status, 200);
    eq('شناسه درست', res.json?.data?.user?.id, userId);
  }
  {
    const res = await api(runtime, 'GET', '/v1/users/999999', { headers: creds });
    eq('کاربر ناموجود => 404', res.status, 404);
  }
  {
    const res = await api(runtime, 'POST', '/v1/users/' + userId + '/role', {
      headers: creds,
      json: { role: 'admin' },
    });
    eq('تغییر نقش => 200', res.status, 200);
    eq('نقش جدید', res.json?.data?.user?.role, 'admin');

    const bad = await api(runtime, 'POST', '/v1/users/' + userId + '/role', {
      headers: creds,
      json: { role: 'superhero' },
    });
    eq('نقش نامعتبر => 422', bad.status, 422);
  }
  {
    const created = await api(runtime, 'POST', '/v1/users', {
      headers: creds,
      json: { email: 'managed@example.com', password: 'ManagedPass123', role: 'viewer' },
    });
    eq('ساخت کاربر توسط اپ => 201', created.status, 201);
    eq('نقش تعیین‌شده', created.json?.data?.user?.role, 'viewer');

    const dup = await api(runtime, 'POST', '/v1/users', {
      headers: creds,
      json: { email: 'managed@example.com', password: 'ManagedPass123' },
    });
    eq('ساخت تکراری بدون link_existing => 409', dup.status, 409);
  }
  {
    const res = await api(runtime, 'POST', '/v1/users/' + userId + '/suspend', { headers: creds });
    eq('مسدود کردن => 200', res.status, 200);
    eq('وضعیت عضویت', res.json?.data?.user?.membership_status, 'suspended');

    const login = await api(runtime, 'POST', '/v1/auth/login', {
      headers: creds,
      json: { email, password: 'UserPass123' },
    });
    eq('ورودِ کاربر مسدود => 403', login.status, 403);

    await api(runtime, 'POST', '/v1/users/' + userId + '/activate', { headers: creds });
  }

  // ---------------------------------------------------------------- بازیابی رمز
  section('بازیابی رمز عبور');
  {
    const forgot = await api(runtime, 'POST', '/v1/auth/password/forgot', {
      headers: creds,
      json: { email },
    });
    eq('درخواست بازیابی => 200', forgot.status, 200);
    const token = forgot.json?.data?.token;
    check('توکن صادر شد', typeof token === 'string');

    const reset = await api(runtime, 'POST', '/v1/auth/password/reset', {
      headers: creds,
      json: { token, password: 'ResetPass123' },
    });
    eq('بازیابی => 200', reset.status, 200);

    const login = await api(runtime, 'POST', '/v1/auth/login', {
      headers: creds,
      json: { email, password: 'ResetPass123' },
    });
    eq('ورود با رمز جدید => 200', login.status, 200);
    accessToken = login.json?.data?.tokens?.access_token;
  }
  {
    const res = await api(runtime, 'POST', '/v1/auth/password/forgot', {
      headers: creds,
      json: { email: 'unknown@example.com' },
    });
    eq('ایمیل ناموجود هم 200 (عدم افشا)', res.status, 200);
    eq('بدون توکن', res.json?.data?.token, undefined);
  }

  // ---------------------------------------------------------------- خروج
  section('خروج');
  {
    const res = await api(runtime, 'POST', '/v1/auth/logout', {
      headers: { Authorization: 'Bearer ' + accessToken },
    });
    eq('خروج => 200', res.status, 200);

    const after = await api(runtime, 'GET', '/v1/me', {
      headers: { Authorization: 'Bearer ' + accessToken },
    });
    eq('توکن بعد از خروج بی‌اعتبار است', after.status, 401);
  }

  // ---------------------------------------------------------------- محدودیت نرخ
  section('محدودیت نرخ');
  {
    const res = await api(runtime, 'GET', '/v1/users', { headers: creds });
    check('هدر X-RateLimit-Limit ارسال می‌شود', headerOf(res, 'X-RateLimit-Limit') !== null);
  }

  // ---------------------------------------------------------------- 404 / 405
  section('خطاهای مسیریابی');
  {
    const res = await api(runtime, 'GET', '/v1/nothing-here', { headers: creds });
    eq('مسیر ناموجود => 404', res.status, 404);

    const method = await api(runtime, 'DELETE', '/v1/health', { headers: creds });
    eq('متد نامجاز => 405', method.status, 405);
  }

  // ---------------------------------------------------------------- پنل مدیریت
  section('پنل مدیریت');
  let sessionId = '';
  {
    const loginPage = await admin(runtime, '/admin/login.php');
    eq('صفحه ورود => 200', loginPage.status, 200);
    check('فرم ورود نمایش داده شده', loginPage.body.includes('ورود به پنل مدیریت'));
    sessionId = loginPage.session_id;
  }
  {
    const res = await admin(runtime, '/admin/index.php', { sessionId });
    check('دسترسی بدون ورود هدایت می‌شود', headerOf(res, 'Location')?.includes('/admin/login.php') === true,
      JSON.stringify(res.headers));
  }
  {
    const loginPost = await admin(runtime, '/admin/login.php', {
      method: 'POST',
      sessionId,
      form: { email: installed.adminEmail, password: 'wrong-password', _token: await csrfToken(runtime, sessionId) },
    });
    check('ورود با رمز اشتباه هدایت نمی‌شود', headerOf(loginPost, 'Location') === null);
    check('پیام خطا نمایش داده شده', loginPost.body.includes('اشتباه'));
  }

  let authedSession = '';
  {
    const token = await csrfToken(runtime, sessionId);
    const loginPost = await admin(runtime, '/admin/login.php', {
      method: 'POST',
      sessionId,
      form: { email: installed.adminEmail, password: installed.adminPassword, _token: token },
    });
    const location = headerOf(loginPost, 'Location');
    check('ورود موفق هدایت می‌شود', location?.includes('/admin/index.php') === true, JSON.stringify(loginPost.headers));
    authedSession = loginPost.session_id;
  }

  for (const page of ['/admin/index.php', '/admin/apps.php', '/admin/users.php', '/admin/tokens.php', '/admin/audit.php', '/admin/account.php']) {
    const res = await admin(runtime, page, { sessionId: authedSession });
    eq('بارگذاری ' + page + ' => 200', res.status, 200);
    check('خروجی معتبر ' + page, res.body.includes('<!doctype html>'));
  }

  {
    const res = await admin(runtime, '/admin/user.php', { sessionId: authedSession, query: { id: userId } });
    eq('صفحه کاربر => 200', res.status, 200);
    check('ایمیل کاربر در صفحه هست', res.body.includes(email));
  }

  // ساخت اپلیکیشن از پنل
  {
    const token = await csrfToken(runtime, authedSession, '/admin/apps.php');
    const res = await admin(runtime, '/admin/apps.php', {
      method: 'POST',
      sessionId: authedSession,
      form: { action: 'create', name: 'اپ تستی', slug: 'test-app', _token: token },
    });
    const location = headerOf(res, 'Location');
    check('ساخت اپ و هدایت', location?.includes('/admin/app.php?id=') === true, JSON.stringify(res.headers));
  }


  // ---------------------------------------------------------------- تنظیمات اپ و کاربر از پنل
  section('عملیات پنل مدیریت');
  {
    // ویرایش مشخصات کاربر
    const token = await csrfToken(runtime, authedSession, '/admin/user.php?id=' + userId);
    const res = await admin(runtime, '/admin/user.php', {
      method: 'POST',
      sessionId: authedSession,
      query: { id: userId },
      form: {
        action: 'update',
        email,
        full_name: 'کاربر ویرایش‌شده توسط ادمین',
        phone: '09120000000',
        status: 'active',
        metadata: '{"plan":"gold"}',
        _token: token,
      },
    });
    check('ویرایش کاربر هدایت می‌شود', headerOf(res, 'Location')?.includes('/admin/user.php?id=') === true, JSON.stringify(res.headers));

    const after = await admin(runtime, '/admin/user.php', { sessionId: authedSession, query: { id: userId } });
    check('نام جدید ذخیره شد', after.body.includes('کاربر ویرایش‌شده توسط ادمین'));
    check('متادیتا ذخیره شد', after.body.includes('gold'));
  }
  {
    // ابطال توکن از پنل
    const listRes = await admin(runtime, '/admin/tokens.php', { sessionId: authedSession, query: { state: 'active' } });
    const tokenInput = /name="token_id" value="(\d+)"/.exec(listRes.body);
    check('حداقل یک توکن فعال در فهرست هست', tokenInput !== null);

    if (tokenInput) {
      const token = await csrfToken(runtime, authedSession, '/admin/tokens.php');
      const res = await admin(runtime, '/admin/tokens.php', {
        method: 'POST',
        sessionId: authedSession,
        form: { action: 'revoke', token_id: tokenInput[1], back_state: 'active', _token: token },
      });
      check('ابطال توکن هدایت می‌شود', headerOf(res, 'Location') !== null, JSON.stringify(res.headers));
    }
  }
  {
    // تغییر رمز عبور مدیر
    const token = await csrfToken(runtime, authedSession, '/admin/account.php');
    const res = await admin(runtime, '/admin/account.php', {
      method: 'POST',
      sessionId: authedSession,
      form: {
        current_password: installed.adminPassword,
        new_password: 'NewAdminPass123',
        new_password_confirmation: 'NewAdminPass123',
        _token: token,
      },
    });
    check('تغییر رمز مدیر => هدایت به ورود', headerOf(res, 'Location')?.includes('/admin/login.php') === true, JSON.stringify(res.headers));

    const relogin = await admin(runtime, '/admin/login.php');
    const csrf = /name="_token" value="([^"]+)"/.exec(relogin.body)?.[1] ?? '';
    const login = await admin(runtime, '/admin/login.php', {
      method: 'POST',
      sessionId: relogin.session_id,
      form: { email: installed.adminEmail, password: 'NewAdminPass123', _token: csrf },
    });
    check('ورود با رمز جدید موفق است', headerOf(login, 'Location')?.includes('/admin/index.php') === true, JSON.stringify(login.headers));
    authedSession = login.session_id;
  }

  // ---------------------------------------------------------------- CORS
  section('CORS');
  {
    const res = await api(runtime, 'OPTIONS', '/v1/users', { headers: { Origin: 'https://example.com' } });
    eq('پاسخ پیش‌پرواز => 204', res.status, 204);
    check('Allow-Methods ارسال شده', headerOf(res, 'Access-Control-Allow-Methods') !== null);
  }

  // ---------------------------------------------------------------- Basic auth
  section('احراز هویت با Basic');
  {
    const basic = Buffer.from(installed.apiKey + ':' + installed.apiSecret).toString('base64');
    const res = await api(runtime, 'GET', '/v1/apps/me', { headers: { Authorization: 'Basic ' + basic } });
    eq('Basic معتبر => 200', res.status, 200);
  }

  // ---------------------------------------------------------------- محدودیت نرخ واقعی
  section('محدودیت نرخ (429)');
  {
    let sawTooMany = false;
    for (let i = 0; i < 25 && !sawTooMany; i++) {
      const res = await api(runtime, 'POST', '/v1/auth/login', {
        headers: creds,
        json: { email: 'throttle@example.com', password: 'WrongPass123' },
        ip: '203.0.113.77',
      });
      if (res.status === 429) {
        sawTooMany = true;
        eq('کد خطای محدودیت', res.json?.error?.code, 'too_many_attempts');
      }
    }
    check('بعد از تلاش‌های زیاد، پاسخ 429 برمی‌گردد', sawTooMany);
  }


  // ---------------------------------------------------------------- نصب‌کننده وب
  section('نصب‌کننده تحت وب');
  {
    const setupRuntime = await createRuntime();

    const beforeInstall = await admin(setupRuntime, '/admin/index.php');
    check('پیش از نصب، پنل به setup.php هدایت می‌شود',
      headerOf(beforeInstall, 'Location')?.includes('/setup.php') === true,
      JSON.stringify(beforeInstall.headers));

    const step1 = await admin(setupRuntime, '/setup.php', { query: { step: '1' } });
    check('مرحله ۱: پیش‌نیازها', step1.body.includes('بررسی پیش‌نیازها'));

    const step2 = await admin(setupRuntime, '/setup.php', {
      method: 'POST',
      form: { step: '2', driver: 'sqlite', path: '/sso/storage/database/setup.sqlite' },
    });
    check('مرحله ۲: اتصال برقرار و به مرحله ۳ رفت', step2.body.includes('ساخت حساب مدیر'), step2.body.slice(0, 400));

    const step3 = await admin(setupRuntime, '/setup.php', {
      method: 'POST',
      form: {
        step: '3',
        driver: 'sqlite',
        path: '/sso/storage/database/setup.sqlite',
        email: 'webadmin@example.com',
        password: 'WebAdminPass123',
        password_confirmation: 'WebAdminPass123',
        app_name: 'نصب وب',
        base_url: 'http://localhost',
        demo_app: '1',
      },
    });
    check('مرحله ۳: نصب موفق', step3.body.includes('نصب با موفقیت'), step3.body.slice(0, 600));
    check('کلیدهای اپ نمایش داده شده', step3.body.includes('ak_live_'));

    const health = await api(setupRuntime, 'GET', '/v1/health');
    eq('بعد از نصب وب، API بالاست', health.status, 200);
  }


  // ---------------------------------------------------------------- قواعد معماری
  section('قواعد معماری (بررسی ایستا)');
  {
    const arch = await architectureCheck(runtime);
    check('فایل‌های بررسی‌شده کافی است', (arch.checked_files ?? 0) > 20, 'checked=' + arch.checked_files);
    check('رشته‌های SQL بررسی شد', (arch.checked_sql_strings ?? 0) > 20, 'sql=' + arch.checked_sql_strings);
    check(
      'هیچ تخطی از قواعد معماری وجود ندارد',
      (arch.violations ?? []).length === 0,
      JSON.stringify(arch.violations, null, 2)
    );
  }

  // ---------------------------------------------------------------- نگه‌داری داده‌ها
  section('نگه‌داری امن داده‌ها');
  {
    const row = await runtime.run(php(['<?php', 'require \'/sso/src/bootstrap.php\';', '\\Sso\\Core\\App::boot();', '$u = \\sso_db()->fetch(\'SELECT `password_hash` FROM `users` WHERE `email` = ?\', [\'user1@example.com\']);', '$t = \\sso_db()->fetch(\'SELECT `token_hash`, `token_prefix` FROM `api_tokens` WHERE `token_type` = ? LIMIT 1\', [\'access\']);', 'echo json_encode([\'hash\' => $u[\'password_hash\'] ?? null, \'token_hash\' => $t[\'token_hash\'] ?? null, \'prefix\' => $t[\'token_prefix\'] ?? null], JSON_UNESCAPED_UNICODE);']));
    const parsed = JSON.parse(row.trim().split('=====SSO_TEST_RESULT=====').pop());
    check('رمز عبور هش شده است', typeof parsed.hash === 'string' && /^\$(2y|argon2)/.test(parsed.hash), String(parsed.hash));
    check('رمز عبور به صورت متن ساده نیست', parsed.hash !== 'ResetPass123');
    check('توکن در دیتابیس هش شده است', typeof parsed.token_hash === 'string' && parsed.token_hash.length >= 40, String(parsed.token_hash));
    check('هش توکن با خود توکن برابر نیست', parsed.token_hash !== accessToken);
  }

  // ---------------------------------------------------------------- رفتار توکن
  section('رفتار توکن‌ها');
  {
    const fresh = await api(runtime, 'POST', '/v1/auth/login', {
      headers: creds,
      json: { email, password: 'ResetPass123' },
    });
    eq('ورود دوباره => 200', fresh.status, 200);
    const at = fresh.json?.data?.tokens?.access_token;
    const rt = fresh.json?.data?.tokens?.refresh_token;

    const intro = await api(runtime, 'POST', '/v1/auth/introspect', {
      headers: creds,
      json: { token: at },
    });
    eq('بررسی توکن معتبر => 200', intro.status, 200);
    eq('توکن فعال است', intro.json?.data?.active, true);
    eq('نوع توکن', intro.json?.data?.token_type, 'access');

    const bad = await api(runtime, 'POST', '/v1/auth/introspect', {
      headers: creds,
      json: { token: 'sat_invalid-token-value' },
    });
    eq('بررسی توکن نامعتبر => 200', bad.status, 200);
    eq('توکن غیرفعال گزارش می‌شود', bad.json?.data?.active, false);

    // تمدید فقط توکنِ تمدید را مصرف می‌کند؛ توکن دسترسیِ فعلی هنوز معتبر است
    const refreshed = await api(runtime, 'POST', '/v1/auth/refresh', {
      headers: creds,
      json: { refresh_token: rt },
    });
    eq('تمدید => 200', refreshed.status, 200);
    const stillOk = await api(runtime, 'GET', '/v1/me', { headers: { Authorization: 'Bearer ' + at } });
    eq('توکن دسترسیِ قبلی همچنان معتبر است', stillOk.status, 200);

    const reuse = await api(runtime, 'POST', '/v1/auth/refresh', {
      headers: creds,
      json: { refresh_token: rt },
    });
    eq('استفاده‌ی مجدد از توکن تمدید => 401', reuse.status, 401);

    // خروج از همه‌ی نشست‌ها
    const logoutAll = await api(runtime, 'POST', '/v1/me/logout', {
      headers: { Authorization: 'Bearer ' + at },
    });
    eq('خروج سراسری => 200', logoutAll.status, 200);
    check('تعداد توکن‌های باطل‌شده گزارش شد', typeof logoutAll.json?.data?.revoked === 'number');

    const afterAll = await api(runtime, 'GET', '/v1/me', { headers: { Authorization: 'Bearer ' + at } });
    eq('توکن بعد از خروج سراسری بی‌اعتبار است', afterAll.status, 401);

    const afterNew = await api(runtime, 'GET', '/v1/me', {
      headers: { Authorization: 'Bearer ' + refreshed.json?.data?.access_token },
    });
    eq('توکنِ صادرشده از تمدید هم باطل شده است', afterNew.status, 401);
  }

  // ---------------------------------------------------------------- نقش و عضویت
  section('نقش‌ها و عضویت');
  {
    // ساخت اپ دوم از طریق سرویس (کلید و راز فقط یک‌بار در خروجی برمی‌گردند)
    const keysRaw = await runtime.run(php(['<?php', 'require \'/sso/src/bootstrap.php\';', '\\Sso\\Core\\App::boot();', '$result = \\sso_app()->apps()->create(\'اپ دوم\', \'second-app\', null);', 'echo json_encode([\'api_key\' => $result[\'api_key\'], \'api_secret\' => $result[\'api_secret\']], JSON_UNESCAPED_UNICODE);']));
    const appKeys = JSON.parse(keysRaw.trim().split('=====SSO_TEST_RESULT=====').pop());
    check('اپ دوم ساخته شد', typeof appKeys.api_key === 'string' && appKeys.api_key.startsWith('ak_live_'),
      JSON.stringify(appKeys));
    const secondCreds = { 'X-Api-Key': appKeys.api_key, 'X-Api-Secret': appKeys.api_secret };

    // کلید و راز باید فقط به صورت هش در دیتابیس باشند
    const stored = await runtime.run(php(['<?php', 'require \'/sso/src/bootstrap.php\';', '\\Sso\\Core\\App::boot();', '$row = \\sso_db()->fetch(\'SELECT `api_key_hash`, `api_secret_hash`, `api_key_prefix` FROM `apps` WHERE `slug` = ?\', [\'second-app\']);', 'echo json_encode($row, JSON_UNESCAPED_UNICODE);']));
    const storedRow = JSON.parse(stored.trim().split('=====SSO_TEST_RESULT=====').pop());
    check('راز اپ به صورت متن ساده ذخیره نشده', storedRow.api_secret_hash !== appKeys.api_secret);
    check('هش راز با sha256 ساخته شده', /^[0-9a-f]{64}$/.test(String(storedRow.api_secret_hash ?? '')));
    check('کلید اپ هم هش شده است', storedRow.api_key_hash !== appKeys.api_key);
    check('پیشوند کلید ذخیره شده', String(appKeys.api_key).startsWith(String(storedRow.api_key_prefix ?? '#')));

    const attach = await api(runtime, 'POST', '/v1/users', {
      headers: secondCreds,
      json: { email, password: 'ResetPass123', link_existing: true, role: 'viewer' },
    });
    eq('اتصال کاربر موجود => 201', attach.status, 201);
    eq('نقش در اپ دوم', attach.json?.data?.user?.role, 'viewer');

    const reAttach = await api(runtime, 'POST', '/v1/users', {
      headers: secondCreds,
      json: { email, password: 'ResetPass123', link_existing: true },
    });
    eq('اتصال تکراری => 409', reAttach.status, 409);
    eq('کد خطا', reAttach.json?.error?.code, 'already_registered');

    // یک کاربر در دو اپ، نقش متفاوت
    const inFirst = await api(runtime, 'GET', '/v1/users/' + userId, { headers: creds });
    const inSecond = await api(runtime, 'GET', '/v1/users/' + userId, { headers: secondCreds });
    eq('نقش در اپ اول', inFirst.json?.data?.user?.role, 'admin');
    eq('نقش در اپ دوم', inSecond.json?.data?.user?.role, 'viewer');
    check('هر دو عضویت در پاسخ هست', (inFirst.json?.data?.user?.memberships ?? []).length >= 2);

    // فهرست اپ‌های کاربر
    const freshLogin = await api(runtime, 'POST', '/v1/auth/login', {
      headers: creds,
      json: { email, password: 'ResetPass123' },
    });
    const meApps = await api(runtime, 'GET', '/v1/me/apps', {
      headers: { Authorization: 'Bearer ' + freshLogin.json?.data?.tokens?.access_token },
    });
    eq('فهرست اپ‌های کاربر => 200', meApps.status, 200);
    check('حداقل دو اپ در فهرست هست', (meApps.json?.data?.apps ?? []).length >= 2);

    // تعلیق در یک اپ، فقط توکن‌های همان اپ را می‌بندد
    const loginSecond = await api(runtime, 'POST', '/v1/auth/login', {
      headers: secondCreds,
      json: { email, password: 'ResetPass123' },
    });
    eq('ورود به اپ دوم => 200', loginSecond.status, 200);
    const tokenSecond = loginSecond.json?.data?.tokens?.access_token;
    const tokenFirst = freshLogin.json?.data?.tokens?.access_token;

    await api(runtime, 'POST', '/v1/users/' + userId + '/suspend', { headers: secondCreds });

    const meAfterSuspend = await api(runtime, 'GET', '/v1/me', {
      headers: { Authorization: 'Bearer ' + tokenSecond },
    });
    eq('بعد از تعلیق، توکنِ همان اپ بی‌اعتبار است', meAfterSuspend.status, 401);

    const meOtherApp = await api(runtime, 'GET', '/v1/me', {
      headers: { Authorization: 'Bearer ' + tokenFirst },
    });
    eq('تعلیق در یک اپ، توکنِ اپ دیگر را باطل نمی‌کند', meOtherApp.status, 200);

    // بازگردانی و بررسیِ متادیتای عضویت
    await api(runtime, 'POST', '/v1/users/' + userId + '/activate', { headers: secondCreds });
    const meta = await api(runtime, 'PATCH', '/v1/users/' + userId, {
      headers: secondCreds,
      json: { membership_metadata: { plan: 'silver' } },
    });
    eq('به‌روزرسانی متادیتای عضویت => 200', meta.status, 200);

    // رگرسیون: تغییر متادیتا نباید عضویتِ مسدود را فعال کند
    await api(runtime, 'POST', '/v1/users/' + userId + '/suspend', { headers: secondCreds });
    await api(runtime, 'PATCH', '/v1/users/' + userId, {
      headers: secondCreds,
      json: { membership_metadata: { plan: 'gold' } },
    });
    const afterMeta = await api(runtime, 'GET', '/v1/users/' + userId, { headers: secondCreds });
    eq('تغییر متادیتا عضویتِ مسدود را فعال نمی‌کند',
      afterMeta.json?.data?.user?.membership_status, 'suspended');
    await api(runtime, 'POST', '/v1/users/' + userId + '/activate', { headers: secondCreds });

    // قطع دسترسی: حساب سراسری می‌ماند، عضویت حذف می‌شود
    const detach = await api(runtime, 'DELETE', '/v1/users/' + userId, { headers: secondCreds });
    eq('قطع دسترسی => 200', detach.status, 200);
    const stillGlobal = await api(runtime, 'GET', '/v1/users/' + userId, { headers: creds });
    eq('کاربر هنوز در سامانه هست', stillGlobal.status, 200);
    const goneFromSecond = await api(runtime, 'GET', '/v1/users/' + userId, { headers: secondCreds });
    eq('کاربر در اپ دوم یافت نمی‌شود', goneFromSecond.status, 404);
  }

  // ---------------------------------------------------------------- فیلتر و صفحه‌بندی
  section('فیلتر و صفحه‌بندی');
  {
    for (let i = 1; i <= 3; i++) {
      await api(runtime, 'POST', '/v1/users', {
        headers: creds,
        json: { email: `page-user-${i}@example.com`, password: 'PageUser123', full_name: 'کاربر صفحه ' + i },
      });
    }

    const all = await api(runtime, 'GET', '/v1/users', { headers: creds, query: { per_page: 5 } });
    eq('فهرست => 200', all.status, 200);
    eq('تعداد آیتم‌ها با per_page مطابقت دارد', all.json?.data?.items?.length <= 5, true);
    eq('per_page در پاسخ هست', all.json?.data?.per_page, 5);
    check('تعداد کل گزارش شد', typeof all.json?.data?.total === 'number');
    check('تعداد صفحات محاسبه شد', typeof all.json?.data?.pages === 'number');

    const clamped = await api(runtime, 'GET', '/v1/users', { headers: creds, query: { per_page: 5000 } });
    eq('per_page در سقف ۱۰۰ محدود می‌شود', clamped.json?.data?.per_page, 100);

    const filtered = await api(runtime, 'GET', '/v1/users', {
      headers: creds,
      query: { q: 'page-user-2' },
    });
    const emails = (filtered.json?.data?.items ?? []).map((u) => u.email);
    check('جست‌وجو نتیجه را محدود می‌کند', emails.every((e) => String(e).includes('page-user-2')), JSON.stringify(emails));
    check('جست‌وجو حداقل یک نتیجه دارد', emails.length >= 1);

    const byRole = await api(runtime, 'GET', '/v1/users', { headers: creds, query: { role: 'member' } });
    eq('فیلتر بر اساس نقش => 200', byRole.status, 200);
    check(
      'همه‌ی نتایج نقش member دارند',
      (byRole.json?.data?.items ?? []).every((u) => u.role === 'member'),
      JSON.stringify((byRole.json?.data?.items ?? []).map((u) => u.role))
    );

    const sorted = await api(runtime, 'GET', '/v1/users', {
      headers: creds,
      query: { sort: 'email', direction: 'asc', per_page: 100 },
    });
    const sortedEmails = (sorted.json?.data?.items ?? []).map((u) => String(u.email));
    const expected = [...sortedEmails].sort();
    check('مرتب‌سازی صعودی درست است', JSON.stringify(sortedEmails) === JSON.stringify(expected));
  }

  // ---------------------------------------------------------------- مسیرهای تکمیلی
  section('مسیرهای تکمیلی API');
  {
    // ساخت کاربر بدون رمز: سامانه رمز می‌سازد
    const generated = await api(runtime, 'POST', '/v1/users', {
      headers: creds,
      json: { email: 'generated@example.com', full_name: 'کاربر خودکار' },
    });
    eq('ساخت کاربر بدون رمز => 201', generated.status, 201);
    const generatedPassword = generated.json?.data?.generated_password;
    check('رمز تصادفی برگشت داده شد', typeof generatedPassword === 'string' && generatedPassword.length >= 8,
      String(generatedPassword));

    const loginGenerated = await api(runtime, 'POST', '/v1/auth/login', {
      headers: creds,
      json: { email: 'generated@example.com', password: generatedPassword },
    });
    eq('ورود با رمز تولیدشده => 200', loginGenerated.status, 200);

    // تنظیم مستقیم رمز توسط اپ
    const setPass = await api(runtime, 'POST', '/v1/users/' + generated.json?.data?.user?.id + '/password', {
      headers: creds,
      json: { password: 'DirectPass123' },
    });
    eq('تنظیم مستقیم رمز => 200', setPass.status, 200);
    const loginDirect = await api(runtime, 'POST', '/v1/auth/login', {
      headers: creds,
      json: { email: 'generated@example.com', password: 'DirectPass123' },
    });
    eq('ورود با رمزِ تنظیم‌شده => 200', loginDirect.status, 200);

    // تغییر رمز توسط خود کاربر
    const changeOwn = await api(runtime, 'POST', '/v1/me/password', {
      headers: { Authorization: 'Bearer ' + loginDirect.json?.data?.tokens?.access_token },
      json: { current_password: 'DirectPass123', new_password: 'OwnChanged123' },
    });
    eq('تغییر رمز توسط کاربر => 200', changeOwn.status, 200);
    const afterChange = await api(runtime, 'GET', '/v1/me', {
      headers: { Authorization: 'Bearer ' + loginDirect.json?.data?.tokens?.access_token },
    });
    eq('توکن‌های قبلی بعد از تغییر رمز باطل شدند', afterChange.status, 401);

    // تأیید ایمیل
    const verify = await api(runtime, 'POST', '/v1/auth/verify-email', {
      headers: creds,
      json: { user_id: String(generated.json?.data?.user?.id) },
    });
    eq('تأیید ایمیل => 200', verify.status, 200);
    eq('ایمیل تأیید شد', verify.json?.data?.user?.email_verified, true);

    // توکن بازیابی: یک‌بارمصرف
    const pr = await api(runtime, 'POST', '/v1/users/' + generated.json?.data?.user?.id + '/password-reset', {
      headers: creds,
    });
    eq('صدور توکن بازیابی => 200', pr.status, 200);
    const resetToken = pr.json?.data?.token;

    const firstUse = await api(runtime, 'POST', '/v1/auth/password/reset', {
      headers: creds,
      json: { token: resetToken, password: 'OnceOnly123' },
    });
    eq('استفاده‌ی اول از توکن بازیابی => 200', firstUse.status, 200);

    const secondUse = await api(runtime, 'POST', '/v1/auth/password/reset', {
      headers: creds,
      json: { token: resetToken, password: 'TwiceOnly123' },
    });
    eq('استفاده‌ی مجدد از توکن بازیابی => 400', secondUse.status, 400);
    eq('کد خطا', secondUse.json?.error?.code, 'invalid_reset_token');

    // تنظیمات و آمار اپلیکیشن
    const patchApp = await api(runtime, 'PATCH', '/v1/apps/me', {
      headers: creds,
      json: { name: 'اپ نمونه (ویرایش‌شده)', settings: { default_role: 'viewer', access_token_ttl: 1800 } },
    });
    eq('ویرایش اپ => 200', patchApp.status, 200);
    eq('نام جدید اپ', patchApp.json?.data?.app?.name, 'اپ نمونه (ویرایش‌شده)');
    eq('تنظیمات ذخیره شد', patchApp.json?.data?.app?.settings?.default_role, 'viewer');

    const stats = await api(runtime, 'GET', '/v1/apps/me/stats', { headers: creds });
    eq('آمار اپ => 200', stats.status, 200);
    check('تعداد کاربران عدد است', typeof stats.json?.data?.users === 'number');
    check('تعداد توکن‌های فعال عدد است', typeof stats.json?.data?.active_tokens === 'number');

    // نقشِ پیش‌فرضِ جدید باید اعمال شود
    const defaultRole = await api(runtime, 'POST', '/v1/users', {
      headers: creds,
      json: { email: 'defaulted@example.com', password: 'DefaultPass123' },
    });
    eq('نقش پیش‌فرض اعمال شد', defaultRole.json?.data?.user?.role, 'viewer');

    await api(runtime, 'PATCH', '/v1/apps/me', {
      headers: creds,
      json: { settings: { default_role: 'member' } },
    });
  }

  // ---------------------------------------------------------------- رد درخواست بدون CSRF
  section('امنیت فرم‌های پنل');
  {
    // درخواست بدون توکن CSRF باید رد شود (با پیام فلش و بدون اعمال تغییر)
    const res = await admin(runtime, '/admin/apps.php', {
      method: 'POST',
      sessionId: authedSession,
      form: { action: 'create', name: 'اپ مخرب', slug: 'evil-app' },
    });
    check('درخواست بدون CSRF پذیرفته نمی‌شود (هدایت به فرم)',
      headerOf(res, 'Location')?.includes('/admin/apps.php') === true, JSON.stringify(res.headers));
    check('بدنه‌ی پاسخِ هدایت، خروجیِ فرم را چاپ نمی‌کند', res.body.trim() === '', res.body.slice(0, 200));

    const followUp = await admin(runtime, '/admin/apps.php', { sessionId: authedSession });
    check('پیام خطای امنیتی نمایش داده شد', followUp.body.includes('توکن امنیتی'), followUp.body.slice(0, 300));
    check('اپ مخرب ساخته نشد', !followUp.body.includes('evil-app'));

    // در مقابل، درخواستِ دارای توکن CSRF باید انجام شود
    const validToken = await csrfToken(runtime, authedSession, '/admin/apps.php');
    const good = await admin(runtime, '/admin/apps.php', {
      method: 'POST',
      sessionId: authedSession,
      form: { action: 'create', name: 'اپ مجاز', slug: 'allowed-app', _token: validToken },
    });
    check('درخواست با CSRF معتبر انجام شد',
      headerOf(good, 'Location')?.includes('/admin/app.php?id=') === true, JSON.stringify(good.headers));
  }

  // ---------------------------------------------------------------- فهرست سفید IP
  section('فهرست سفید IP برای پنل مدیریت');
  {
    const lockedRuntime = await createRuntime();
    await installProject(lockedRuntime, {
      extraConfig: { 'security.admin_ip_whitelist': ['10.0.0.1'] },
    });

    const blocked = await admin(lockedRuntime, '/admin/login.php', { ip: '203.0.113.9' });
    check('IP خارج از فهرست مسدود می‌شود', blocked.body.includes('دسترسی محدود شده'), blocked.body.slice(0, 300));

    const allowed = await admin(lockedRuntime, '/admin/login.php', { ip: '10.0.0.1' });
    check('IP داخل فهرست اجازه دارد', allowed.body.includes('ورود به پنل مدیریت'), allowed.body.slice(0, 300));
  }

  // ---------------------------------------------------------------- نصب با خط فرمان
  section('نصب‌کننده و ابزارهای خط فرمان');
  {
    // ---- نصب با CLI ----
    const cliInstallRuntime = await createRuntime();
    const installOut = await cliInstallRuntime.run(php(['<?php', '$GLOBALS[\'argv\'] = [', '  \'install.php\',', '  \'--driver=sqlite\',', '  \'--path=/sso/storage/database/cli.sqlite\',', '  \'--admin-email=cli@example.com\',', '  \'--admin-password=CliPass123\',', '  \'--base-url=http://localhost\',', '  \'--no-demo-app\',', '];', 'require \'/sso/tools/install.php\';']));

    check('نصب CLI با موفقیت پایان یافت', installOut.includes('نصب با موفقیت انجام شد'),
      JSON.stringify(installOut.slice(-500)));
    check('حساب مدیر ساخته شد', installOut.includes('cli@example.com'), JSON.stringify(installOut.slice(-500)));
    check('با --no-demo-app پیام اپ نمونه چاپ نشد', !installOut.includes('اپلیکیشن نمونه ساخته شد'));

    const health = await api(cliInstallRuntime, 'GET', '/v1/health');
    eq('بعد از نصب CLI، API بالاست', health.status, 200);

    const stateOut = await cliInstallRuntime.run(php(['<?php', 'require \'/sso/src/bootstrap.php\';', '\\Sso\\Core\\App::boot();', 'echo json_encode([', '  \'admin\' => (int) \\sso_db()->value(\'SELECT COUNT(*) FROM `users` WHERE `is_super_admin` = ?\', [1]),', '  \'apps\' => (int) \\sso_db()->value(\'SELECT COUNT(*) FROM `apps`\', []),', '  \'locked\' => is_file(SSO_INSTALL_LOCK) ? 1 : 0,', '], JSON_UNESCAPED_UNICODE);']));
    const state = JSON.parse(stateOut.trim().split('=====SSO_TEST_RESULT=====').pop());
    eq('دقیقاً یک مدیر ساخته شد', state.admin, 1);
    eq('با --no-demo-app هیچ اپی ساخته نشد', state.apps, 0);
    eq('قفل نصب ایجاد شد', state.locked, 1);

    // ---- ابزار create-app ----
    const appOut = await cliInstallRuntime.run(php(['<?php', '$GLOBALS[\'argv\'] = [\'create-app.php\', \'--name=اپ CLI\', \'--slug=cli-app\'];', 'require \'/sso/tools/create-app.php\';']));
    check('create-app نام اپ را چاپ می‌کند', appOut.includes('اپلیکیشن ساخته شد'), JSON.stringify(appOut.slice(0, 300)));
    const cliKey = /API Key:\s*(ak_live_[A-Za-z0-9]+)/.exec(appOut)?.[1] ?? '';
    const cliSecret = /API Secret:\s*(\S+)/.exec(appOut)?.[1] ?? '';
    check('create-app کلید چاپ می‌کند', cliKey.length > 10, JSON.stringify(appOut.slice(0, 400)));
    check('create-app راز چاپ می‌کند', cliSecret.length > 10, JSON.stringify(appOut.slice(0, 400)));

    const createdApp = await api(cliInstallRuntime, 'GET', '/v1/apps/me', {
      headers: { 'X-Api-Key': cliKey, 'X-Api-Secret': cliSecret },
    });
    eq('اپ ساخته‌شده با CLI قابل استفاده است', createdApp.status, 200);
    eq('شناسه‌ی اپ درست است', createdApp.json?.data?.app?.slug, 'cli-app');

    // ---- ابزار create-admin ----
    const adminOut = await cliInstallRuntime.run(php(['<?php', '$GLOBALS[\'argv\'] = [\'create-admin.php\', \'--email=root2@example.com\', \'--password=RootPass123\', \'--name=مدیر دوم\'];', 'require \'/sso/tools/create-admin.php\';']));
    check('create-admin مدیر می‌سازد', adminOut.includes('ادمین آماده است'), JSON.stringify(adminOut.slice(0, 300)));
    check('ایمیل مدیر در خروجی هست', adminOut.includes('root2@example.com'), JSON.stringify(adminOut.slice(0, 300)));

    const adminState = await cliInstallRuntime.run(php(['<?php', 'require \'/sso/src/bootstrap.php\';', '\\Sso\\Core\\App::boot();', 'echo json_encode([\'admins\' => (int) \\sso_db()->value(\'SELECT COUNT(*) FROM `users` WHERE `is_super_admin` = ?\', [1])], JSON_UNESCAPED_UNICODE);']));
    const admins = JSON.parse(adminState.trim().split('=====SSO_TEST_RESULT=====').pop());
    eq('تعداد مدیرها به دو رسید', admins.admins, 2);

    // مدیر جدید باید بتواند وارد پنل شود
    const loginPage = await admin(cliInstallRuntime, '/admin/login.php');
    const csrf = /name="_token" value="([^"]+)"/.exec(loginPage.body)?.[1] ?? '';
    const login = await admin(cliInstallRuntime, '/admin/login.php', {
      method: 'POST',
      sessionId: loginPage.session_id,
      form: { email: 'root2@example.com', password: 'RootPass123', _token: csrf },
    });
    check('مدیر جدید می‌تواند وارد پنل شود',
      headerOf(login, 'Location')?.includes('/admin/index.php') === true, JSON.stringify(login.headers));

  }


  console.log('\n' + '─'.repeat(60));
  console.log(`نتیجه: ${passed} موفق، ${failed} ناموفق`);
  if (failures.length > 0) {
    console.log('\nموارد ناموفق:');
    for (const item of failures) {
      console.log('  - ' + item);
    }
  }
  process.exit(failed > 0 ? 1 : 0);
}

/**
 * خواندن توکن CSRF از نشست جاری (ابتدا از تگ meta قالب، بعد از فیلد فرم).
 */
async function csrfToken(runtime, sessionId, page = '/admin/login.php') {
  const [file, queryString] = page.split('?');
  const query = {};
  if (queryString) {
    for (const [key, value] of new URLSearchParams(queryString)) {
      query[key] = value;
    }
  }
  const res = await admin(runtime, file, { sessionId, query });
  const meta = res.body.match(/name="csrf-token" content="([^"]+)"/);
  if (meta) {
    return meta[1];
  }
  const input = res.body.match(/name="_token" value="([^"]+)"/);
  return input ? input[1] : '';
}

main().catch((error) => {
  console.error('\nخطای کلی در اجرای تست‌ها:');
  console.error(error);
  process.exit(1);
});
