#!/usr/bin/env node
// Screenshot sweep (dark-factory page-coverage, spec §4): logs into the demo
// tenant once, walks every route in the committed manifest, and captures
// full-page screenshots + an index.html gallery for design-regression review.
//
// Usage:
//   node scripts/factory/screenshot-sweep.mjs \
//     --base http://localhost:5173 --email owner@pharmabio.tn --password password \
//     --out docs/sessions/screenshots/sweep-2026-07-05 \
//     [--routes-filter '^/(login|dashboard)$'] [--app web]
//
// Ops script (no test framework — its test is the smoke run, board T-0011).
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { join, dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseArgs } from 'node:util'
import { load as yamlLoad } from 'js-yaml'
import { chromium } from 'playwright-core'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')

const { values } = parseArgs({
  args: process.argv.slice(2),
  options: {
    base: { type: 'string', default: 'http://localhost:5173' },
    email: { type: 'string' },
    password: { type: 'string' },
    out: { type: 'string' },
    'routes-filter': { type: 'string' },
    app: { type: 'string', default: 'web' },
  },
})
if (!values.email || !values.password) {
  console.error('usage: screenshot-sweep.mjs --base <url> --email <e> --password <p> [--out dir] [--routes-filter re] [--app web|pos]')
  process.exit(1)
}
const outDir = resolve(values.out ?? join(repoRoot, 'docs/sessions/screenshots', `sweep-${new Date().toISOString().slice(0, 10)}`))
mkdirSync(outDir, { recursive: true })

/** @type {{routes: Array<{path: string, component: string|null}>}} */
const manifest = yamlLoad(readFileSync(join(repoRoot, 'scripts/factory/manifests', `routes-${values.app}.yaml`), 'utf8'))
const filter = values['routes-filter'] ? new RegExp(values['routes-filter']) : null
const wanted = manifest.routes.map((r) => r.path).filter((p) => filter === null || filter.test(p))
const dynamic = wanted.filter((p) => p.includes(':'))
const routes = wanted.filter((p) => !p.includes(':'))
if (dynamic.length > 0) {
  writeFileSync(join(outDir, 'skipped-dynamic.txt'), `${dynamic.join('\n')}\n`)
  console.log(`skipped ${dynamic.length} parameterized route(s) → skipped-dynamic.txt`)
}

/** @param {string} p route path → screenshot basename */
const sanitize = (p) => (p === '/' ? 'root' : p.replace(/^\//, '').replace(/[^a-zA-Z0-9]+/g, '-'))
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

/** System Chrome first, bundled chromium as fallback. */
async function launch() {
  try {
    return await chromium.launch({ channel: 'chrome' })
  } catch {
    return await chromium.launch()
  }
}

const browser = await launch()
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } })

// --- login once via the UI -------------------------------------------------
try {
  await page.goto(`${values.base}/login`, { waitUntil: 'domcontentloaded', timeout: 15_000 })
} catch (e) {
  console.error(`server unreachable at ${values.base} — is the stack running? (${e.message.split('\n')[0]})`)
  await browser.close()
  process.exit(1)
}
try {
  await page.fill('input[type="email"], input[name="email"]', values.email)
  await page.fill('input[type="password"], input[name="password"]', values.password)
  await page.click('button[type="submit"]')
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 })
  await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {})
  console.log(`logged in as ${values.email} → ${page.url()}`)
} catch (e) {
  console.error(`login failed at ${values.base}/login: ${e.message.split('\n')[0]}`)
  await browser.close()
  process.exit(1)
}

// --- walk every route ------------------------------------------------------
/** @type {Array<{path: string, file: string, ok: boolean, error?: string}>} */
const results = []
for (const routePath of routes) {
  const file = `${sanitize(routePath)}.png`
  try {
    await page.goto(`${values.base}${routePath}`, { waitUntil: 'domcontentloaded', timeout: 15_000 })
    await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {})
    await page.screenshot({ path: join(outDir, file), fullPage: true })
    results.push({ path: routePath, file, ok: true })
    console.log(`✓ ${routePath}`)
  } catch (e) {
    results.push({ path: routePath, file, ok: false, error: e.message.split('\n')[0] })
    console.warn(`✗ ${routePath}: ${e.message.split('\n')[0]}`)
  }
}
await browser.close()

// --- gallery ---------------------------------------------------------------
const figures = results.map((r) => (r.ok
  ? `    <figure><a href="${esc(r.file)}"><img src="${esc(r.file)}" loading="lazy" alt="${esc(r.path)}"></a><figcaption>${esc(r.path)}</figcaption></figure>`
  : `    <figure><figcaption>${esc(r.path)} — FAILED: ${esc(r.error ?? '')}</figcaption></figure>`)).join('\n')
writeFileSync(join(outDir, 'index.html'), `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Screenshot sweep — ${esc(values.app)}</title>
<style>
  body{font-family:system-ui,sans-serif;margin:1rem;background:#111;color:#eee}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem}
  figure{margin:0;border:1px solid #333;padding:.5rem;border-radius:6px;background:#1a1a1a}
  img{width:100%;height:220px;object-fit:cover;object-position:top;border-radius:4px}
  figcaption{margin-top:.4rem;font-size:.8rem;word-break:break-all}
</style></head><body>
<h1>Screenshot sweep — app: ${esc(values.app)} — ${new Date().toISOString()}</h1>
<p>${results.filter((r) => r.ok).length}/${results.length} captured; ${dynamic.length} dynamic route(s) skipped.</p>
<div class="grid">
${figures}
</div></body></html>
`)
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} screenshots → ${outDir}/index.html`)
process.exit(results.some((r) => !r.ok) ? 2 : 0)
