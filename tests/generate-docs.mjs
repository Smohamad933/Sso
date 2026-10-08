/**
 * اجرای tools/export-docs.php در محیطِ php-wasm و بیرون کشیدنِ فایل‌ها.
 *
 * این اسکریپت فقط برای تولیدِ فایل‌های قابلِ تحویل در همین محیط است؛
 * روی سرور واقعی، خودِ ابزار با دستورِ `php tools/export-docs.php` اجرا می‌شود.
 *
 * استفاده: node generate-docs.mjs [پوشه‌ی خروجیِ میزبان]
 */
import fs from 'node:fs';
import path from 'node:path';
import { createRuntime, installProject } from './harness.mjs';

const hostOut = process.argv[2] || path.resolve(process.cwd(), '../storage/export');
fs.mkdirSync(hostOut, { recursive: true });

const rt = await createRuntime();
await installProject(rt);

const wasmOut = '/tmp/sso-docs-export';
const out = await rt.run(`<?php
putenv('SSO_HTTP_TEST=1');
$GLOBALS['argv'] = ['export-docs.php', '--out=${wasmOut}'];
require '/sso/tools/export-docs.php';
`);
console.log(out.trim());

const files = [
  'sso-docs.html',
  'sso-api.postman_collection.json',
  'sso-api.openapi.json',
];

for (const name of files) {
  const data = await rt.php.readFile(`${wasmOut}/${name}`);
  const target = path.join(hostOut, name);
  fs.writeFileSync(target, Buffer.from(data));
  console.log('ذخیره شد:', target, `(${fs.statSync(target).size} بایت)`);
}
