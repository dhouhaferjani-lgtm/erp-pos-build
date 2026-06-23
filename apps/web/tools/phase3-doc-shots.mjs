// Focused before/after screenshot harness for Phase 3 documents canonicalization.
// Launches its own headless chromium (independent of the Playwright MCP profile)
// so it does not collide with a parallel interactive session.
// Usage: LABEL=before node tools/phase3-doc-shots.mjs
import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';

const BASE = process.env.AUDIT_BASE || 'http://localhost:8089';
const EMAIL = process.env.AUDIT_EMAIL || 'owner@cafe-tunis.tn';
const PASSWORD = process.env.AUDIT_PASSWORD;
if (!PASSWORD) {
  throw new Error('AUDIT_PASSWORD must be set (no hardcoded fallback).');
}
const LABEL = process.env.LABEL || 'shot';
const OUT =
  process.env.AUDIT_OUT ||
  '/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-consistency/docs/superpowers/audits/ui-audit-2026-06-14/screens/phase3';

const ROUTES = [
  [`documentform-${LABEL}`, '/sales/quotes/new'],
  [`documentlist-${LABEL}`, '/sales/quotes'],
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
  return !page.url().includes('/login');
}

(async () => {
  await mkdir(OUT, { recursive: true });
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const log = [];

  const authed = await tryLogin(page);
  log.push(`login authed=${authed} as ${EMAIL}`);
  if (!authed) {
    console.log(log.join('\n'));
    await browser.close();
    process.exit(1);
  }

  for (const [name, route] of ROUTES) {
    try {
      await page.goto(`${BASE}${route}`, { waitUntil: 'networkidle', timeout: 30000 });
      await page.waitForTimeout(1500);
      await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
      log.push(`${name} OK (${route})`);
    } catch (e) {
      log.push(`${name} FAIL ${e.message}`);
    }
  }

  console.log(log.join('\n'));
  await browser.close();
})();
