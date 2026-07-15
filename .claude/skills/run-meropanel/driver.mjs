/**
 * Meropanel smoke driver
 *
 * Usage:
 *   node .claude/skills/run-meropanel/driver.mjs [--screenshots /path/to/dir]
 *
 * Requirements:
 *   npm install playwright          (from /tmp or any dir with write access)
 *   PHP built-in server running:   cd src && php -S localhost:9000 router.php &
 *   MySQL merovps DB accessible:   mysql -u merovps -pmerovps merovps
 *
 * Admin API: HTTP Basic, username=admin, password=<api_token from admin table>
 * Set MEROPANEL_ADMIN_TOKEN env var to the token from the admin table before running.
 */

import { chromium } from 'playwright';
import { mkdirSync } from 'fs';

const BASE = process.env.MEROPANEL_URL || 'http://localhost:9000';
const ADMIN_TOKEN = process.env.MEROPANEL_ADMIN_TOKEN;
if (!ADMIN_TOKEN) {
  console.error('Error: MEROPANEL_ADMIN_TOKEN environment variable is required.');
  console.error('  export MEROPANEL_ADMIN_TOKEN=<token from admin table>');
  process.exit(1);
}
const ssIdx = process.argv.indexOf('--screenshots');
const SCREENSHOT_DIR = ssIdx !== -1 ? process.argv[ssIdx + 1] : '/tmp/meropanel-shots';

mkdirSync(SCREENSHOT_DIR, { recursive: true });

let passed = 0, failed = 0;
const ok = (msg) => { console.log('✓', msg); passed++; };
const fail = (msg) => { console.error('✗', msg); failed++; };

async function apiSmoke() {
  const adminHeaders = { Authorization: 'Basic ' + Buffer.from(`admin:${ADMIN_TOKEN}`).toString('base64') };
  const tests = [
    ['guest', 'product/get_list', null],
    ['guest', 'invoice/gateways', null],
    ['guest', 'cart/get', null],
    ['admin', 'product/get_list', adminHeaders],
    ['admin', 'client/get_list', adminHeaders],
  ];
  for (const [role, ep, headers] of tests) {
    try {
      const r = await fetch(`${BASE}/api/${role}/${ep}`, { headers: headers ?? {} });
      const d = await r.json();
      if (d.result !== null && d.result !== undefined) ok(`API ${role}/${ep}`);
      else fail(`API ${role}/${ep}: ${JSON.stringify(d.error)}`);
    } catch (e) {
      fail(`API ${role}/${ep}: ${e.message}`);
    }
  }
}

async function browserSmoke() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  page.setDefaultTimeout(15000);

  const pages = [
    ['/', 'homepage'],
    ['/order', 'order-list'],
    ['/order/starter', 'starter-plan'],
    ['/order/checkout', 'checkout'],
    ['/client/login', 'client-login'],
  ];

  for (const [path, name] of pages) {
    try {
      const resp = await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
      if (!resp || resp.status() === 404) {
        fail(`Page ${path} → 404`);
      } else {
        await page.screenshot({ path: `${SCREENSHOT_DIR}/${name}.png` });
        ok(`Page ${path} → ${SCREENSHOT_DIR}/${name}.png`);
      }
    } catch (e) {
      fail(`Page ${path}: ${e.message}`);
    }
  }

  // Verify merotheme is active (check for Alpine.js marker in head)
  await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
  const hasAlpine = await page.evaluate(() =>
    !!document.querySelector('script[src*="merotheme"]') ||
    !!document.querySelector('[x-data]')
  );
  if (hasAlpine) ok('merotheme Alpine.js active');
  else fail('merotheme not detected — check active theme in admin');

  await browser.close();
}

console.log(`Smoke testing ${BASE}\n`);
await apiSmoke();
await browserSmoke();

console.log(`\n${passed} passed, ${failed} failed  — screenshots: ${SCREENSHOT_DIR}/`);
if (failed > 0) process.exit(1);
