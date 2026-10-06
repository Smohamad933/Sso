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
    if (process.env.DEBUG_ADMIN) {
      const dbg = await runtime.run(`<?php
require '/sso/src/bootstrap.php';
\\Sso\\Core\\App::boot();
echo json_encode(\\sso_db()->fetch('SELECT id,email,full_name,metadata FROM users WHERE id = ?', [${userId}]), JSON_UNESCAPED_UNICODE);
`);
      console.log('      [debug] db row:', dbg.trim());
    }

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

  // ---------------------------------------------------------------- CLI
  section('ابزارهای خط فرمان (در محیط جدا)');
  {
    const cliRuntime = await createRuntime();
    await installProject(cliRuntime);
    const out = await cliRuntime.run(`<?php
$GLOBALS['argv'] = ['create-app.php', '--name=اپ CLI', '--slug=cli-app'];
require '/sso/tools/create-app.php';
`);
    check('create-app.php خروجی چاپ می‌کند', out.includes('API Key:'), JSON.stringify(out.slice(0, 300)));

    // create-admin.php در انتها exit می‌کند، پس در یک runtime تازه اجرا می‌شود
    const cliRuntime2 = await createRuntime();
    await installProject(cliRuntime2);
    const out2 = await cliRuntime2.run(`<?php
$GLOBALS['argv'] = ['create-admin.php', '--email=root@example.com', '--password=RootPass123'];
require '/sso/tools/create-admin.php';
`);
    check('create-admin.php ادمین می‌سازد', out2.includes('ادمین آماده است'), JSON.stringify(out2.slice(0, 300)));
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
