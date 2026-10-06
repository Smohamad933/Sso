#!/usr/bin/env node
/**
 * سرور توسعه/پیش‌نمایش (اختیاری)
 *
 * این فایل فقط برای دیدن سریع پروژه بدون IIS است و هیچ نقشی در production ندارد.
 * روی سرور واقعی از IIS + PHP + MySQL استفاده کنید.
 *
 * پیش‌نیاز: نصب وابستگی‌ها
 *   cd tests && npm install
 *
 * اجرا:
 *   node tools/dev-server.mjs
 *   سپس مرورگر را روی http://localhost:8000 باز کنید.
 *
 * نکته: اگر فایل config/config.php وجود نداشته باشد، به /setup.php هدایت می‌شوید.
 */

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRuntime } from '../tests/harness.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = path.resolve(__dirname, '..');
const PORT = Number(process.env.PORT ?? 8000);
const WASM_ROOT = '/sso';

const MIME = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
};

/** فایل‌هایی که پس از تغییر باید دوباره در حافظه‌ی PHP کپی شوند */
const synced = new Map();

function collectFiles(dir, base = '') {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['.git', 'node_modules', '.idea', '.vscode'].includes(entry.name)) continue;
    const rel = base ? `${base}/${entry.name}` : entry.name;
    const abs = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...collectFiles(abs, rel));
    } else if (entry.isFile()) {
      out.push({ rel, abs, mtime: fs.statSync(abs).mtimeMs });
    }
  }
  return out;
}

async function sync(php) {
  for (const file of collectFiles(PROJECT_ROOT)) {
    const previous = synced.get(file.rel);
    if (previous === file.mtime) continue;
    const data = fs.readFileSync(file.abs);
    const wasmPath = `${WASM_ROOT}/${file.rel}`;
    const dir = path.posix.dirname(wasmPath);
    if (dir !== WASM_ROOT) {
      await php.mkdir(dir).catch(() => {});
    }
    await php.writeFile(wasmPath, data);
    synced.set(file.rel, file.mtime);
  }
}

const runtime = await createRuntime();
const { php } = runtime;
await sync(php);

// اگر تنظیمات وجود ندارد، یک بار با SQLite نصب خودکار انجام می‌شود
const ADMIN_EMAIL = 'admin@example.com';
const ADMIN_PASSWORD = 'AdminPass123';
let autoInstalled = false;

if (!fs.existsSync(path.join(PROJECT_ROOT, 'config', 'config.php'))) {
  const installed = await runtime.run(`<?php
$GLOBALS['__TEST_REQUEST'] = json_decode(${JSON.stringify(JSON.stringify({ admin_email: ADMIN_EMAIL, admin_password: ADMIN_PASSWORD, base_url: '' }))}, true);
require '/sso/tests/php/install.php';
`);
  const marker = installed.indexOf('=====SSO_TEST_RESULT=====');
  const payload = marker === -1 ? {} : JSON.parse(installed.slice(marker + 25).trim());
  autoInstalled = payload.ok === true;

  console.log('  نصب خودکار با SQLite انجام شد.');
  console.log('  کلید اپ نمونه: ' + (payload.api_key ?? '—'));
}

console.log(`\n  سرور توسعه روی http://localhost:${PORT} آماده است.`);
console.log('  پنل مدیریت: http://localhost:' + PORT + '/admin/');
console.log('  ایمیل مدیر: ' + ADMIN_EMAIL);
console.log('  رمز عبور:   ' + ADMIN_PASSWORD);
console.log('  (داده‌ها در حافظه‌اند و با ری‌استارت پاک می‌شوند)\n');

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' || req.method === 'HEAD') {
    const url = new URL(req.url, 'http://localhost');
    const candidate = path.join(PROJECT_ROOT, 'public', decodeURIComponent(url.pathname));

    // فقط فایل‌های استاتیک مستقیماً سرو می‌شوند (فایل‌های php باید اجرا شوند)
    if (fs.existsSync(candidate) && fs.statSync(candidate).isFile() && path.extname(candidate) !== '.php') {
      res.writeHead(200, { 'Content-Type': MIME[path.extname(candidate)] ?? 'application/octet-stream' });
      res.end(fs.readFileSync(candidate));
      return;
    }
  }

  try {
    await sync(php);

    const url = new URL(req.url, 'http://localhost');
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    const rawBody = Buffer.concat(chunks).toString('utf8');

    const headers = {};
    for (const [name, value] of Object.entries(req.headers)) {
      headers[name] = Array.isArray(value) ? value.join(', ') : value;
    }

    const cookies = {};
    const cookieHeader = req.headers.cookie ?? '';
    for (const pair of cookieHeader.split(';')) {
      const [key, ...rest] = pair.trim().split('=');
      if (key) cookies[decodeURIComponent(key)] = decodeURIComponent(rest.join('='));
    }

    // نشست PHP: چون در این محیط کوکیِ نشست وجود ندارد، شناسه را خودمان نگه می‌داریم
    const incomingSession = cookies['XSSOSESS'] ?? '';

    let target = url.pathname;
    // public/index.php از exit استفاده می‌کند و با این runtime سازگار نیست؛
    // هدایت ریشه را خودمان انجام می‌دهیم.
    if (target === '/' || target === '' || target === '/index.php') {
      const installed = autoInstalled || fs.existsSync(path.join(PROJECT_ROOT, 'config', 'config.php'));
      res.writeHead(302, { Location: installed ? '/admin/' : '/setup.php' });
      res.end();
      return;
    }
    if (!target.startsWith('/api')) {
      const publicRoot = path.join(PROJECT_ROOT, 'public');
      let filePath = path.join(publicRoot, target);

      // فهرست راهنما: /admin/ -> /admin/index.php
      if (fs.existsSync(filePath) && fs.statSync(filePath).isDirectory()) {
        filePath = path.join(filePath, 'index.php');
      }

      if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
        target = filePath.slice(publicRoot.length);
      } else {
        res.writeHead(404).end('Not found');
        return;
      }
    }

    const isApi = target.startsWith('/api');
    const spec = {
      method: req.method,
      uri: target + (url.search ?? ''),
      path: target.replace(/^\/api/, ''),
      file: target,
      script_name: isApi ? '/api/index.php' : target,
      query: Object.fromEntries(url.searchParams),
      form: {},
      json: null,
      body: rawBody,
      headers,
      cookies,
      ip: '127.0.0.1',
      https: false,
      session_id: incomingSession,
    };

    if ((headers['content-type'] ?? '').includes('application/json') && rawBody !== '') {
      spec.json = JSON.parse(rawBody);
    } else if ((headers['content-type'] ?? '').includes('form-urlencoded') && rawBody !== '') {
      spec.form = Object.fromEntries(new URLSearchParams(rawBody));
    }

    const phpCode = `<?php
$GLOBALS['__TEST_REQUEST'] = json_decode(${JSON.stringify(JSON.stringify(spec))}, true);
require '${WASM_ROOT}/tests/php/${isApi ? 'dispatch_api' : 'dispatch_admin'}.php';
`;

    let output = '';
    const listener = (event) => { output += String(event.detail ?? ''); };
    php.addEventListener('output', listener);
    await php.run(phpCode);
    php.removeEventListener('output', listener);

    const markerIndex = output.indexOf('=====SSO_TEST_RESULT=====');
    if (markerIndex === -1) {
      res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('خطا در اجرای PHP:\n' + output.slice(-2000));
      return;
    }

    const payload = JSON.parse(output.slice(markerIndex + '=====SSO_TEST_RESULT====='.length).trim());

    const outHeaders = {};
    for (const line of payload.headers ?? []) {
      if (line.startsWith('HTTP/1.1 ')) {
        res.statusCode = Number(line.slice(9));
        continue;
      }
      const [name, ...rest] = line.split(':');
      const value = rest.join(':').trim();
      if (name.toLowerCase() === 'set-cookie') {
        const existing = outHeaders['Set-Cookie'];
        outHeaders['Set-Cookie'] = existing ? [].concat(existing, value) : [value];
      } else {
        outHeaders[name] = value;
      }
    }

    if (typeof payload.session_id === 'string' && payload.session_id !== '' && payload.session_id !== incomingSession) {
      const cookie = `XSSOSESS=${payload.session_id}; Path=/; HttpOnly; SameSite=Lax`;
      const existing = outHeaders['Set-Cookie'];
      outHeaders['Set-Cookie'] = existing ? [].concat(existing, cookie) : [cookie];
    }

    // مسیر Location را نسبی می‌کنیم تا پشت پروکسی/پیش‌نمایش هم درست کار کند
    if (typeof outHeaders['Location'] === 'string') {
      outHeaders['Location'] = outHeaders['Location'].replace(/^https?:\/\/[^/]+/i, '');
    }

    if (!outHeaders['Content-Type']) {
      outHeaders['Content-Type'] = 'text/html; charset=utf-8';
    }

    res.writeHead(res.statusCode ?? 200, outHeaders);
    res.end(payload.body ?? '');
  } catch (error) {
    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('خطا: ' + error.message);
  }
});

server.listen(PORT, '0.0.0.0');
