// Read-only UI-audit screenshot harness. Drives the running dev stack at :8089.
// Usage: node tools/ui-audit-shots.mjs
import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';

const BASE = process.env.AUDIT_BASE || 'http://localhost:8089';
const EMAIL = process.env.AUDIT_EMAIL || 'admin@demo.local';
const PASSWORD = process.env.AUDIT_PASSWORD || 'password';
const OUT = process.env.AUDIT_OUT || '/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/audits/ui-audit-2026-06-14/screens';

// Representative routes per cluster (one or two per feature area).
const ROUTES = [
  ['00-login', '/login', { noAuth: true }],
  ['01-dashboard', '/dashboard'],
  ['02-sales-customers', '/sales/customers'],
  ['03-sales-customer-new', '/sales/customers/new'],
  ['04-sales-quotes', '/sales/quotes'],
  ['05-sales-quote-new', '/sales/quotes/new'],
  ['06-sales-orders', '/sales/orders'],
  ['07-sales-invoices', '/sales/invoices'],
  ['08-sales-credit-notes', '/sales/credit-notes'],
  ['09-purchases-suppliers', '/purchases/suppliers'],
  ['10-purchases-orders', '/purchases/orders'],
  ['11-purchases-receipts', '/purchases/receipts'],
  ['12-inventory-products', '/inventory/products'],
  ['13-inventory-product-new', '/inventory/products/new'],
  ['14-inventory-stock', '/inventory/stock'],
  ['15-inventory-movements', '/inventory/movements'],
  ['16-inventory-categories', '/inventory/categories'],
  ['17-inventory-batches', '/inventory/batches'],
  ['18-inventory-counting', '/inventory/counting'],
  ['19-inventory-transfers', '/inventory/stock-transfers'],
  ['20-reports', '/reports'],
  ['21-settings', '/settings'],
  ['22-treasury', '/treasury'],
  ['23-expenses', '/expenses'],
  ['24-finance', '/finance'],
  ['25-vat-reporting', '/vat-reporting'],
  ['26-loyalty', '/loyalty'],
  ['27-promotions', '/promotions'],
  ['28-coupons', '/coupons'],
  ['29-scheduling', '/scheduling'],
  ['30-users', '/settings/users'],
  ['31-company', '/settings/company'],
  ['32-pricing', '/pricing'],
  ['33-pos', '/pos'],
];

async function tryLogin(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.fill('input[name="email"]', EMAIL).catch(() => {});
  await page.fill('input[name="password"]', PASSWORD).catch(() => {});
  await Promise.all([
    page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {}),
    page.click('button[type="submit"]').catch(() => {}),
  ]);
  await page.waitForTimeout(2500);
  const url = page.url();
  console.log('  after login url:', url);
  return !url.includes('/login');
}

(async () => {
  await mkdir(OUT, { recursive: true });
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const log = [];

  // capture login page first (unauthenticated)
  try {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle', timeout: 30000 });
    await page.screenshot({ path: `${OUT}/00-login.png`, fullPage: true });
    log.push('00-login OK');
  } catch (e) { log.push(`00-login FAIL ${e.message}`); }

  const authed = await tryLogin(page);
  log.push(`login authed=${authed}`);
  if (!authed) {
    console.log(log.join('\n'));
    await browser.close();
    process.exit(0);
  }

  for (const [name, path, opts] of ROUTES) {
    if (opts?.noAuth) continue;
    try {
      await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 25000 });
      await page.waitForTimeout(1500);
      await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
      log.push(`${name} OK (${page.url()})`);
    } catch (e) {
      try { await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false }); } catch {}
      log.push(`${name} PARTIAL/FAIL ${e.message.split('\n')[0]}`);
    }
  }

  await browser.close();
  console.log('\n=== CAPTURE LOG ===\n' + log.join('\n'));
})();
