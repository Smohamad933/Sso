/**
 * هارنس تست: اجرای PHP واقعی (از طریق php-wasm) روی پروژه.
 *
 * پیش‌نیاز: npm install در پوشه‌ی tests (package.json همین‌جاست).
 *
 *   node tests/run.mjs
 */

import { PhpNode } from 'php-wasm/PhpNode';
import sqlite from 'php-wasm-sqlite';
import openssl from 'php-wasm-openssl';
import mbstring from 'php-wasm-mbstring';

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
export const PROJECT_ROOT = path.resolve(__dirname, '..');
const WASM_ROOT = '/sso';
const MARKER = '=====SSO_TEST_RESULT=====';

const SKIP_DIRS = new Set(['.git', 'node_modules', '.idea', '.vscode']);

/**
 * کپی کردن پروژه داخل فایل‌سیستم مجازیِ PHP.
 */
async function copyIntoVfs(php, hostDir, wasmDir) {
  await php.mkdir(wasmDir).catch(() => {});
  const entries = fs.readdirSync(hostDir, { withFileTypes: true });

  for (const entry of entries) {
    if (SKIP_DIRS.has(entry.name)) continue;
    const hostPath = path.join(hostDir, entry.name);
    const wasmPath = wasmDir + '/' + entry.name;

    if (entry.isDirectory()) {
      await copyIntoVfs(php, hostPath, wasmPath);
    } else if (entry.isFile()) {
      const data = fs.readFileSync(hostPath);
      await php.writeFile(wasmPath, data);
    }
  }
}

export async function createRuntime() {
  const php = new PhpNode({
    version: '8.3',
    sharedLibs: [sqlite, openssl, mbstring],
    ini: [
      'memory_limit=256M',
      'error_reporting=E_ALL',
      'display_errors=0',
      'log_errors=0',
      'date.timezone=UTC',
      'session.save_handler=files',
    ].join('\n'),
  });

  let buffer = '';
  php.addEventListener('output', (event) => {
    buffer += String(event.detail ?? '');
  });

  await copyIntoVfs(php, PROJECT_ROOT, WASM_ROOT);

  const run = async (code) => {
    buffer = '';
    await php.run(code);
    return buffer;
  };

  return { php, run };
}

/**
 * اجرای یک اسکریپت PHP با یک متغیر مشخص به عنوان درخواست.
 */
async function dispatch(runtime, script, spec) {
  const payload = JSON.stringify(spec);
  const code = `<?php
// در حالت تست، استثناهای کنترل‌نشده نباید باعث exit شوند (runtime را از بین می‌برد)
putenv('SSO_HTTP_TEST=1');
$GLOBALS['__TEST_REQUEST'] = json_decode(${JSON.stringify(payload)}, true);
require '${WASM_ROOT}/${script}';
`;
  const output = await runtime.run(code);
  const index = output.indexOf(MARKER);
  if (index === -1) {
    throw new Error('پاسخ تست نامعتبر بود.\n--- خروجی ---\n' + output.slice(-4000));
  }
  const json = output.slice(index + MARKER.length).trim();
  return { raw: output.slice(0, index), ...JSON.parse(json) };
}

export function parseJsonBody(result) {
  return JSON.parse(result.body || '{}');
}

export async function installProject(runtime, options = {}) {
  const adminEmail = options.adminEmail ?? 'admin@example.com';
  const adminPassword = options.adminPassword ?? 'AdminPass123';

  const result = await dispatch(runtime, 'tests/php/install.php', {
    admin_email: adminEmail,
    admin_password: adminPassword,
    base_url: options.baseUrl ?? 'http://localhost',
    extra_config: options.extraConfig ?? null,
  });

  const payload = result;
  if (!payload.ok) {
    throw new Error('نصب ناموفق بود: ' + JSON.stringify(payload, null, 2));
  }

  return {
    adminEmail,
    adminPassword,
    apiKey: payload.api_key,
    apiSecret: payload.api_secret,
    output: result.raw,
  };
}

export async function lintProject(runtime) {
  return await dispatch(runtime, 'tests/php/lint.php', {});
}

/**
 * بررسی قواعد معماری (نبودِ توابع زمانِ SQL، نبودِ upsert، نبودِ exit در مسیر وب و ...).
 */
export async function architectureCheck(runtime) {
  return await dispatch(runtime, 'tests/php/architecture.php', {});
}

/**
 * درخواست به API.
 */
export async function api(runtime, method, path, options = {}) {
  const result = await dispatch(runtime, 'tests/php/dispatch_api.php', {
    method,
    path,
    query: options.query ?? {},
    form: options.form ?? {},
    json: options.json ?? null,
    body: options.body ?? '',
    headers: options.headers ?? {},
    cookies: options.cookies ?? {},
    ip: options.ip ?? '127.0.0.1',
    https: options.https ?? false,
  });
  return { ...result, json: safeJson(result.body) };
}

/**
 * درخواست به پنل مدیریت.
 */
export async function admin(runtime, file, options = {}) {
  const result = await dispatch(runtime, 'tests/php/dispatch_admin.php', {
    file,
    method: options.method ?? 'GET',
    uri: options.uri ?? file,
    script_name: file,
    query: options.query ?? {},
    form: options.form ?? {},
    cookies: options.cookies ?? {},
    headers: options.headers ?? {},
    session_id: options.sessionId ?? '',
    ip: options.ip ?? '127.0.0.1',
  });
  return result;
}

function safeJson(body) {
  try {
    return JSON.parse(body || '{}');
  } catch {
    return null;
  }
}

/**
 * استخراج یک هدر از پاسخ.
 */
export function headerOf(result, name) {
  const prefix = name.toLowerCase() + ':';
  for (const line of result.headers ?? []) {
    if (line.toLowerCase().startsWith(prefix)) {
      return line.slice(prefix.length).trim();
    }
  }
  return null;
}

/**
 * استخراج مقدار Set-Cookie.
 */
export function cookieOf(result, name) {
  for (const line of result.headers ?? []) {
    if (!line.toLowerCase().startsWith('set-cookie:')) continue;
    const value = line.slice('set-cookie:'.length).trim();
    const [pair] = value.split(';');
    const [key, ...rest] = pair.split('=');
    if (decodeURIComponent(key) === name) {
      return decodeURIComponent(rest.join('='));
    }
  }
  return null;
}
