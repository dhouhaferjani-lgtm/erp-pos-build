/**
 * Request-hygiene Phase A — browser evidence.
 *
 * Fourteen lanes were merged to local `dev` without their browser legs (no local
 * stack existed). This spec supplies the empirical evidence each lane's
 * "Promotion-owed" column asks for. It is EVIDENCE, not a regression suite: it
 * runs against the orchestrator's live stack (API :8011, vite :5174) and it must
 * never restart or reconfigure it.
 *
 * Owner rule (memory: focused waves / empirical evidence): EVERY leg registers a
 * 5xx response guard and a console-error guard, reports the MEASURED counts, and
 * asserts both are zero. A leg that deliberately forces a failure fulfils it
 * through `guards.forceFail(route, …)`, which tolerates that ONE `Request` and a
 * budget of one console line for it — never a whole URL path (gate r1 MAJ-5).
 * The only other tolerances are the four named, disclosed classes in
 * `support.ts:namedTolerance` (K-10 /auth/me 401, the Echo socket with no Reverb,
 * third-party font origins, and a 4xx on a path the harness itself probed).
 *
 * Run:
 *   cd apps/web && CAMPAIGN_API_URL=http://localhost:5174 CAMPAIGN_WEB_URL=http://localhost:5174 \
 *     ./node_modules/.bin/playwright test --config e2e/request-hygiene/pw.config.ts
 */
import { expect, test, type Page } from '@playwright/test'

import { dismissCookieConsent, journeyState, loginAs, runId } from '../campaign/journey'
import { apiRoutes } from '../campaign/selectors'
import {
  apiJson,
  bodyField,
  captureGuards,
  dataOf,
  flushLedger,
  loadState,
  saveState,
  quoted,
  record,
  recordNetwork,
  rowsOf,
  shot,
  writeArtefact,
  sql,
  stamp,
  str,
  tenantDb,
} from './support'

test.describe.configure({ mode: 'serial' })

const API_URL = process.env['CAMPAIGN_API_URL'] ?? 'http://localhost:5174'
const PASSWORD = 'Campaign!2026Safe'
const RUN = runId.replace(/[^a-z0-9]/gi, '').slice(-10).toUpperCase()
const TODAY = new Date().toISOString().slice(0, 10)

interface Fixtures {
  companyId: string
  companyName: string
  mainLocationId: string
  customerId: string
  customerName: string
  supplierId: string
  productIds: Map<string, string>
  pieceUnitId: string
  tax19: string
  paymentMethodId: string
  paymentMethodName: string
  repositoryId: string
  email: string
  warehouseLocationId: string
  poId: string
  poNumber: string
  /** The RUN suffix the fixtures were CREATED with — `RUN` changes on every re-run. */
  fixtureRun: string
  volumeSeeded: boolean
  lotSeeded: boolean
  company2Id: string
  company2Name: string
}

const fx: Partial<Fixtures> = { productIds: new Map() }

function need<K extends keyof Fixtures>(key: K): NonNullable<Fixtures[K]> {
  const value = fx[key]
  if (value === undefined) throw new Error(`fixture ${String(key)} missing — RH-SETUP did not complete`)
  return value as NonNullable<Fixtures[K]>
}

/** Full SKU / name of a fixture product (they carry the RUN of the run that created them). */
function fixtureSku(sku: string): string { return `${sku}-${need('fixtureRun')}` }
function fixtureName(sku: string): string { return `${sku} ${need('fixtureRun')}` }

async function seedSession(page: Page): Promise<void> {
  await dismissCookieConsent(page)
}

/**
 * Re-establish the RH-SETUP session in a fresh browser context WITHOUT hitting
 * /auth/login again: the login route is `throttle:login` (5/min) and this spec
 * opens well over five contexts. Mirrors `journey.ensureSession`.
 */
async function restoreSession(page: Page): Promise<void> {
  const auth = journeyState.persistedAuth ?? null
  const companyId = need('companyId')
  await page.addInitScript(({ auth: persisted, selectedCompanyId }) => {
    window.localStorage.setItem('autoerp-cookie-consent', 'accepted')
    window.localStorage.setItem('autoerp-company-selection', selectedCompanyId)
    if (persisted !== null) window.localStorage.setItem('autoerp-auth', persisted)
  }, { auth, selectedCompanyId: companyId })
}

test.afterAll(() => { flushLedger() })

/* ================================================================== */
/* SETUP                                                               */
/* ================================================================== */

/** Persist / restore the whole fixture set: `throttle:register` is 5 per 15 minutes per IP. */
function persistFixtures(): void {
  saveState({
    ...fx,
    productIds: [...(fx.productIds ?? new Map())],
    tenantId: journeyState.tenantId ?? null,
    persistedAuth: journeyState.persistedAuth ?? null,
  })
}

function restoreFixtures(): boolean {
  const state = loadState()
  if (state === null) return false
  const tenantId = state['tenantId']
  const persistedAuth = state['persistedAuth']
  if (typeof tenantId !== 'string' || typeof persistedAuth !== 'string') return false
  for (const [key, value] of Object.entries(state)) {
    if (key === 'productIds' || key === 'tenantId' || key === 'persistedAuth') continue
    ;(fx as Record<string, unknown>)[key] = value
  }
  const products = state['productIds']
  fx.productIds = new Map(Array.isArray(products) ? (products as [string, string][]) : [])
  journeyState.tenantId = tenantId
  journeyState.persistedAuth = persistedAuth
  journeyState.companyId = fx.companyId
  return true
}

test('RH-SETUP fresh parapharmacy tenant + fixtures', async ({ page }) => {
  test.setTimeout(600_000)
  if (restoreFixtures()) {
    // Re-runs reuse the tenant from `state.json` (delete it, or set RH_FRESH=1, for a
    // brand-new one). The guard is installed FIRST (gate r1 BLK-2): a reuse branch
    // reported 0/0 as constants before, so a real 5xx here was invisible.
    const reuseGuards = captureGuards(page)
    await restoreSession(page)
    await page.goto('/dashboard')
    const me = await apiJson(page, 'GET', '/auth/me', undefined, need('companyId'))
    expect(me.status, `restored session is dead: ${JSON.stringify(me.body)}`).toBe(200)
    // MIN-5: prove the reused fixtures still EXIST rather than trusting state.json.
    const company = await apiJson(page, 'GET', `/companies/${need('companyId')}`, undefined, need('companyId'))
    expect(company.status, `reused company is gone: ${JSON.stringify(company.body)}`).toBe(200)
    const reuseCounts = reuseGuards.assertClean()
    record({
      id: 'RH-SETUP', lane: '—', scenario: 'REUSED tenant from state.json (register is throttled 5/15min per IP)',
      result: 'PASS',
      evidence: `tenant=${String(journeyState.tenantId)} db=${tenantDb()} company=${need('companyId')}; GET /auth/me = ${String(me.status)}; GET /companies/{id} = ${String(company.status)}; ${stamp()}`,
      fivexx: reuseCounts.fivexx, consoleErrors: reuseCounts.consoleErrors,
    })
    return
  }
  const guards = captureGuards(page)
  const email = `rh-evidence-${RUN.toLowerCase()}@test.otospex.dev`
  fx.email = email
  fx.fixtureRun = RUN

  // Register through the API so the company currency is pinned to TND (3-decimal
  // money — leg RH-T12-d needs a third decimal place to be representable). The
  // registration UI has no currency step; the API accepts `currency`.
  const registerResponse = await fetch(`${API_URL}/api/v1/auth/register`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({
      name: `RH Evidence ${RUN}`,
      email,
      password: PASSWORD,
      password_confirmation: PASSWORD,
      company_name: `RH Evidence ${RUN}`,
      country_code: 'TN',
      currency: 'TND',
      vertical: 'parapharmacy',
    }),
  })
  expect(registerResponse.status, await registerResponse.text()).toBe(201)

  await seedSession(page)
  await loginAs(page, { email, name: `RH Evidence ${RUN}`, password: PASSWORD })

  const session = await page.evaluate(() => {
    const raw = localStorage.getItem('autoerp-auth')
    if (raw === null) throw new Error('autoerp-auth not persisted after login')
    const parsed = JSON.parse(raw) as { state?: { user?: { id?: string; tenant_id?: string } } }
    return { tenantId: parsed.state?.user?.tenant_id ?? '', userId: parsed.state?.user?.id ?? '' }
  })
  expect(session.tenantId).not.toBe('')
  journeyState.tenantId = session.tenantId
  journeyState.credentials = { email, name: `RH Evidence ${RUN}`, password: PASSWORD }
  journeyState.persistedAuth = (await page.evaluate(() => localStorage.getItem('autoerp-auth'))) ?? undefined

  const companies = rowsOf((await apiJson(page, 'GET', apiRoutes.companies)).body, 'companies')
  expect(companies).toHaveLength(1)
  const company = companies[0]
  if (company === undefined) throw new Error('company missing')
  fx.companyId = str(company, 'id')
  fx.companyName = str(company, 'name')
  journeyState.companyId = fx.companyId

  const locations = rowsOf((await apiJson(page, 'GET', apiRoutes.locations, undefined, fx.companyId)).body, 'locations')
  const main = locations.find((l) => l['code'] === 'MAIN')
  if (main === undefined) throw new Error('MAIN location missing')
  fx.mainLocationId = str(main, 'id')

  const units = rowsOf((await apiJson(page, 'GET', apiRoutes.units, undefined, fx.companyId)).body, 'units')
  const piece = units.find((u) => u['code'] === 'pc' || u['symbol'] === 'pc')
  if (piece === undefined) throw new Error('pc unit missing')
  fx.pieceUnitId = str(piece, 'id')

  const taxes = rowsOf((await apiJson(page, 'GET', '/taxation/configurations', undefined, fx.companyId)).body, 'taxes')
  const tax19 = taxes.find((t) => String(t['percentage_rate']) === '19.00')
  if (tax19 === undefined) throw new Error('VAT 19% missing')
  fx.tax19 = str(tax19, 'id')

  const methods = rowsOf((await apiJson(page, 'GET', apiRoutes.paymentMethods, undefined, fx.companyId)).body, 'payment methods')
  const cash = methods.find((m) => m['is_active'] !== false && (m['code'] === 'CASH' || /cash|esp/i.test(String(m['name']))))
    ?? methods.find((m) => m['is_active'] !== false)
  if (cash === undefined) throw new Error('active payment method missing')
  fx.paymentMethodId = str(cash, 'id')
  fx.paymentMethodName = str(cash, 'name')

  const repositories = rowsOf((await apiJson(page, 'GET', apiRoutes.paymentRepositories, undefined, fx.companyId)).body, 'repositories')
  const repository = repositories.find((r) => r['is_active'] !== false && r['gl_account_id'] !== null)
    ?? repositories.find((r) => r['is_active'] !== false)
  if (repository === undefined) throw new Error('active payment repository missing')
  fx.repositoryId = str(repository, 'id')

  // Partners: one customer (payments + document flows), one supplier.
  const customer = await apiJson(page, 'POST', '/partners', { name: `RH Customer ${RUN}`, type: 'customer' }, fx.companyId)
  expect(customer.status, JSON.stringify(customer.body)).toBe(201)
  fx.customerId = str(asRec(dataOf(customer.body, 'customer')), 'id')
  fx.customerName = `RH Customer ${RUN}`

  const supplier = await apiJson(page, 'POST', '/partners', { name: `RH Supplier ${RUN}`, type: 'supplier' }, fx.companyId)
  expect(supplier.status, JSON.stringify(supplier.body)).toBe(201)
  fx.supplierId = str(asRec(dataOf(supplier.body, 'supplier')), 'id')

  persistFixtures()
  const counts = guards.assertClean()
  record({
    id: 'RH-SETUP', lane: '—', scenario: 'fresh parapharmacy TN/TND tenant + company/location/tax/method/repository/partners',
    result: 'PASS',
    evidence: `tenant=${session.tenantId} db=${tenantDb()} company=${fx.companyId} location=${fx.mainLocationId} customer=${fx.customerId} method=${fx.paymentMethodName} repository=${fx.repositoryId} at ${stamp()}`,
    fivexx: counts.fivexx, consoleErrors: counts.consoleErrors,
  })
})

function asRec(value: unknown): Record<string, unknown> {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(`expected object, got ${JSON.stringify(value)}`)
  }
  return value as Record<string, unknown>
}

/* ================================================================== */
/* T12 — payment idempotency (lane rh-t12 / t12b / t12c)               */
/* ================================================================== */

/** Fill the standalone payment form at /treasury/payments/new. */
async function selectWhenLoaded(page: Page, selectId: string, value: string): Promise<void> {
  // The option lists arrive from their own queries; selecting before they land
  // times out with a bare "selectOption" error that says nothing useful.
  await expect(page.locator(`${selectId} option[value="${value}"]`)).toHaveCount(1, { timeout: 60_000 })
  await page.locator(selectId).selectOption(value)
}

async function fillPaymentForm(page: Page, amount: string): Promise<void> {
  await selectWhenLoaded(page, '#payment_method_id', need('paymentMethodId'))
  await selectWhenLoaded(page, '#repository_id', need('repositoryId'))
  await selectWhenLoaded(page, '#partner_id', need('customerId'))
  await page.locator('#payment_date').fill(TODAY)
  await page.locator('#amount').fill('')
  await page.locator('#amount').pressSequentially(amount, { delay: 30 })
}

function paymentCount(reference: string): number {
  const rows = sql(tenantDb(), `SELECT COUNT(*) FROM payments WHERE company_id=${quoted(need('companyId'))} AND reference=${quoted(reference)}`)
  return Number(rows[0] ?? '0')
}

test('RH-T12-a throttled double-click on the payment form books exactly one payment with one idempotency key', async ({ page }) => {
  test.setTimeout(180_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  const reference = `RH-T12-A-${RUN}`

  // Throttle the create so the second click lands while the first is in flight.
  await page.route('**/api/v1/payments', async (route) => {
    if (route.request().method() !== 'POST') { await route.fallback(); return }
    await new Promise((resolve) => setTimeout(resolve, 2500))
    await route.continue()
  })

  await page.goto('/treasury/payments/new')
  await expect(page.locator('#amount')).toBeVisible()
  await fillPaymentForm(page, '11.500')
  await page.locator('#reference').fill(reference)

  // A true double-click: two click events dispatched synchronously, before React
  // can re-render the button into its disabled/pending state. This is what the
  // synchronous `submitLockRef` latch exists for.
  await page.evaluate(() => {
    const button = document.querySelector('form button[type="submit"]')
    if (!(button instanceof HTMLElement)) throw new Error('submit button not found')
    button.click()
    button.click()
  })

  await page.waitForURL((url) => url.pathname === '/treasury/payments', { timeout: 60_000 })
  await page.unroute('**/api/v1/payments')

  const posts = net.matching('/api/v1/payments', 'POST')
  const keys = [...new Set(posts.map((w) => bodyField(w, 'idempotency_key') ?? 'MISSING'))]
  const dbCount = paymentCount(reference)
  const screenshot = await shot(page, 'RH-T12-a')
  const counts = guards.stop()
  net.stop()

  const evidence = `POST /api/v1/payments ×${String(posts.length)} (statuses ${posts.map((w) => String(w.status)).join(',')}), distinct body idempotency_key = ${String(keys.length)} [${keys.join(' | ')}]; psql SELECT COUNT(*) FROM payments WHERE reference='${reference}' = ${String(dbCount)}; screenshot ${screenshot}; ${stamp()}`
  const ok = dbCount === 1 && keys.length === 1 && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T12-a', lane: 'T12', scenario: 'throttled double-click on PaymentForm submit', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(posts, 'the synchronous latch must let exactly ONE POST out').toHaveLength(1)
  expect(dbCount, 'exactly one payment row').toBe(1)
  expect(keys.length, 'all POSTs carry the SAME idempotency key').toBe(1)
})

test('RH-T12-b forced 500 then an UNCHANGED retry replays the SAME idempotency key', async ({ page }) => {
  test.setTimeout(180_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  guards.tolerateConsole('Payment recording failed', "PaymentForm's own console.error for the failure this leg forced")
  const net = recordNetwork(page)

  const reference = `RH-T12-B-${RUN}`
  let failedOnce = false
  await page.route('**/api/v1/payments', async (route) => {
    if (route.request().method() !== 'POST') { await route.fallback(); return }
    if (!failedOnce) {
      failedOnce = true
      await guards.forceFail(route, 500, { message: 'forced failure (RH-T12-b)' })
      return
    }
    await route.continue()
  })

  await page.goto('/treasury/payments/new')
  await expect(page.locator('#amount')).toBeVisible()
  await fillPaymentForm(page, '7.250')
  await page.locator('#reference').fill(reference)

  await page.locator('form button[type="submit"]').click()
  await expect.poll(() => net.matching('/api/v1/payments', 'POST').length, { timeout: 30_000 }).toBe(1)

  // The operator changes NOTHING and presses Save again.
  await page.locator('form button[type="submit"]').click()
  await page.waitForURL((url) => url.pathname === '/treasury/payments', { timeout: 60_000 })
  await page.unroute('**/api/v1/payments')

  const posts = net.matching('/api/v1/payments', 'POST')
  const keys = posts.map((w) => bodyField(w, 'idempotency_key') ?? 'MISSING')
  const dbCount = paymentCount(reference)
  const screenshot = await shot(page, 'RH-T12-b')
  const counts = guards.stop()
  net.stop()

  const same = keys.length === 2 && keys[0] === keys[1]
  const evidence = `POST ×${String(posts.length)} (statuses ${posts.map((w) => String(w.status)).join(',')} — the first 500 is forced by page.route); keys = [${keys.join(' | ')}]; identical=${String(same)}; psql COUNT(payments WHERE reference='${reference}') = ${String(dbCount)}; screenshot ${screenshot}; ${stamp()}`
  const ok = same && dbCount === 1 && counts.consoleErrors === 0
  record({ id: 'RH-T12-b', lane: 'T12', scenario: 'forced 500 → unchanged retry keeps the key', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(posts).toHaveLength(2)
  expect(keys[0], 'unchanged retry must replay the SAME key').toBe(keys[1])
  expect(dbCount).toBe(1)
})

test('RH-T12-c forced 500 then an operator EDIT rotates the idempotency key', async ({ page }) => {
  test.setTimeout(180_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  guards.tolerateConsole('Payment recording failed', "PaymentForm's own console.error for the failure this leg forced")
  const net = recordNetwork(page)

  const reference = `RH-T12-C-${RUN}`
  let failedOnce = false
  await page.route('**/api/v1/payments', async (route) => {
    if (route.request().method() !== 'POST') { await route.fallback(); return }
    if (!failedOnce) {
      failedOnce = true
      await guards.forceFail(route, 500, { message: 'forced failure (RH-T12-c)' })
      return
    }
    await route.continue()
  })

  await page.goto('/treasury/payments/new')
  await expect(page.locator('#amount')).toBeVisible()
  await fillPaymentForm(page, '5.000')
  await page.locator('#reference').fill(reference)

  await page.locator('form button[type="submit"]').click()
  await expect.poll(() => net.matching('/api/v1/payments', 'POST').length, { timeout: 30_000 }).toBe(1)

  // The operator edits the amount — a NEW submit intent.
  await page.locator('#amount').fill('')
  await page.locator('#amount').pressSequentially('6.000', { delay: 30 })
  await page.locator('form button[type="submit"]').click()
  await page.waitForURL((url) => url.pathname === '/treasury/payments', { timeout: 60_000 })
  await page.unroute('**/api/v1/payments')

  const posts = net.matching('/api/v1/payments', 'POST')
  const keys = posts.map((w) => bodyField(w, 'idempotency_key') ?? 'MISSING')
  const amounts = posts.map((w) => bodyField(w, 'amount') ?? 'MISSING')
  const dbCount = paymentCount(reference)
  const screenshot = await shot(page, 'RH-T12-c')
  const counts = guards.stop()
  net.stop()

  const rotated = keys.length === 2 && keys[0] !== keys[1]
  const evidence = `POST ×${String(posts.length)} amounts=[${amounts.join(' | ')}]; keys=[${keys.join(' | ')}]; rotated=${String(rotated)}; psql COUNT(payments WHERE reference='${reference}') = ${String(dbCount)}; screenshot ${screenshot}; ${stamp()}`
  const ok = rotated && dbCount === 1 && counts.consoleErrors === 0
  record({ id: 'RH-T12-c', lane: 'T12', scenario: 'forced 500 → operator edits amount → NEW key', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(posts).toHaveLength(2)
  expect(amounts[0], 'the premise of the leg: the operator really changed the amount').not.toBe(amounts[1])
  expect(keys[0], 'an operator edit must rotate the key').not.toBe(keys[1])
  expect(dbCount).toBe(1)
})

test('RH-T12-d keyboard entry of a three-decimal amount posts and stores the exact string', async ({ page }) => {
  test.setTimeout(180_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  const reference = `RH-T12-D-${RUN}`
  await page.goto('/treasury/payments/new')
  await expect(page.locator('#amount')).toBeVisible()
  await fillPaymentForm(page, '12.345')
  await page.locator('#reference').fill(reference)
  await page.locator('form button[type="submit"]').click()
  await page.waitForURL((url) => url.pathname === '/treasury/payments', { timeout: 60_000 })

  const posts = net.matching('/api/v1/payments', 'POST')
  const posted = posts.map((w) => bodyField(w, 'amount') ?? 'MISSING')
  const stored = sql(tenantDb(), `SELECT amount FROM payments WHERE company_id=${quoted(need('companyId'))} AND reference=${quoted(reference)}`)
  const screenshot = await shot(page, 'RH-T12-d')
  const counts = guards.stop()
  net.stop()

  const evidence = `typed "12.345" into #amount (MoneyInput, TND step=0.001); POST body amount=[${posted.join(' | ')}]; psql payments.amount=[${stored.join(' | ')}]; screenshot ${screenshot}; ${stamp()}`
  const ok = posted.length === 1 && posted[0] === '12.345' && stored[0] === '12.345' && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T12-d', lane: 'T12', scenario: 'three-decimal keyboard amount survives to the wire and to the DB', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail).toBe('[]')
  expect(posted[0], 'exact decimal string on the wire (no float drift)').toBe('12.345')
  expect(stored[0], 'exact decimal string at rest').toBe('12.345')
})

/* ================================================================== */
/* SETUP 2 — stock, a second location, a payable document              */
/* ================================================================== */

const SKUS = ['RH-P1', 'RH-P2', 'RH-P3'] as const

test('RH-SETUP-2 products, warehouse, received purchase order (stock + a payable document)', async ({ page }) => {
  test.setTimeout(600_000)
  if (fx.poId !== undefined) {
    // Gate r1 BLK-2 + MIN-5: install the guard and RE-READ the reused PO and the
    // reused warehouse instead of re-printing ids out of state.json.
    const reuseGuards = captureGuards(page)
    await restoreSession(page)
    await page.goto('/dashboard')
    const po = await apiJson(page, 'GET', `/purchase-orders/${String(fx.poId)}`, undefined, need('companyId'))
    expect(po.status, `reused PO is gone: ${JSON.stringify(po.body)}`).toBe(200)
    const poStatus = String(asRec(dataOf(po.body, 'reused PO'))['status'])
    const locations = rowsOf((await apiJson(page, 'GET', apiRoutes.locations, undefined, need('companyId'))).body, 'locations')
    expect(locations.map((l) => l['id'])).toContain(need('warehouseLocationId'))
    const reuseCounts = reuseGuards.assertClean()
    record({
      id: 'RH-SETUP-2', lane: '—', scenario: 'REUSED products/warehouse/PO from state.json (re-read, not trusted)',
      result: 'PASS',
      evidence: `GET /purchase-orders/${String(fx.poId)} = 200 status="${poStatus}" (${String(fx.poNumber)}); warehouse ${String(fx.warehouseLocationId)} present in GET /locations; products=${String(need('productIds').size)}; ${stamp()}`,
      fivexx: reuseCounts.fivexx, consoleErrors: reuseCounts.consoleErrors,
    })
    return
  }
  await restoreSession(page)
  await page.goto('/dashboard')
  const guards = captureGuards(page)

  const companyId = need('companyId')

  for (const sku of SKUS) {
    const created = await apiJson(page, 'POST', '/products', {
      name: fixtureName(sku), sku: fixtureSku(sku), type: 'part', is_physical: true,
      unit_id: need('pieceUnitId'), purchase_price: '10.000', sale_price: '15.000',
      default_tax_configuration_id: need('tax19'), tax_rate: '19.00',
      requires_batch_tracking: false, is_active: true,
    }, companyId)
    expect([200, 201], JSON.stringify(created.body)).toContain(created.status)
    need('productIds').set(sku, str(asRec(dataOf(created.body, sku)), 'id'))
  }

  const warehouse = await apiJson(page, 'POST', '/locations', {
    name: `RH Warehouse ${RUN}`, code: `RHW${RUN}`.slice(0, 20), type: 'warehouse', is_active: true, is_default: false,
  }, companyId)
  expect(warehouse.status, JSON.stringify(warehouse.body)).toBe(201)
  fx.warehouseLocationId = str(asRec(dataOf(warehouse.body, 'warehouse')), 'id')

  const po = await apiJson(page, 'POST', '/purchase-orders', {
    partner_id: need('supplierId'), location_id: need('mainLocationId'),
    document_date: TODAY, currency: 'TND', notes: `RH evidence PO ${RUN}`,
    lines: SKUS.map((sku) => ({
      product_id: need('productIds').get(sku), description: sku,
      quantity: '100', unit_price: '10.000', tax_configuration_id: need('tax19'),
    })),
  }, companyId)
  expect(po.status, JSON.stringify(po.body)).toBe(201)
  const poRow = asRec(dataOf(po.body, 'po'))
  fx.poId = str(poRow, 'id')

  const confirmed = await apiJson(page, 'POST', `/purchase-orders/${fx.poId}/confirm`, {}, companyId)
  expect(confirmed.status, JSON.stringify(confirmed.body)).toBe(200)
  const confirmedRow = asRec(dataOf(confirmed.body, 'confirmed po'))
  fx.poNumber = String(confirmedRow['document_number'] ?? confirmedRow['number'] ?? fx.poId)
  const lines = confirmedRow['lines']
  if (!Array.isArray(lines)) throw new Error('confirmed PO has no lines')
  const quantities: Record<string, string> = {}
  for (const line of lines) quantities[str(asRec(line), 'id')] = '100'

  const received = await apiJson(page, 'POST', `/purchase-orders/${fx.poId}/receive`, {
    location_id: need('mainLocationId'), quantities,
  }, companyId)
  expect(received.status, JSON.stringify(received.body)).toBe(200)

  const movementCount = sql(tenantDb(), `SELECT COUNT(*) FROM stock_movements WHERE company_id=${quoted(companyId)}`)[0] ?? '0'
  const outstanding = sql(tenantDb(), `SELECT payload->>'outstanding_amount' FROM documents WHERE id=${quoted(fx.poId)}`)[0] ?? 'n/a'

  persistFixtures()
  const counts = guards.assertClean()
  record({
    id: 'RH-SETUP-2', lane: '—', scenario: '3 products, a second location, a confirmed+received PO',
    result: 'PASS',
    evidence: `po=${fx.poNumber} (${fx.poId}) received 3×100 at MAIN; warehouse=${String(fx.warehouseLocationId)}; psql stock_movements=${movementCount}; documents.payload.outstanding_amount=${outstanding}; ${stamp()}`,
    fivexx: counts.fivexx, consoleErrors: counts.consoleErrors,
  })
})

/* ------------------------------------------------------------------ */
/* T12-e — reopening the modal rotates the key                         */
/* ------------------------------------------------------------------ */

/** Open the RecordPaymentModal on the PO detail page and confirm one payment line. */
async function armRecordPaymentModal(page: Page, amount: string): Promise<void> {
  await page.getByRole('button', { name: /record payment|enregistrer.*paiement/i }).first().click()
  await expect(page.locator('#payment-date')).toBeVisible()
  await expect(page.locator(`[id^="method-"] option[value="${need('paymentMethodId')}"]`).first()).toHaveCount(1, { timeout: 60_000 })
  await page.locator('[id^="method-"]').first().selectOption(need('paymentMethodId'))
  await page.locator('[id^="amount-"]').first().fill(amount)
  await expect(page.locator(`[id^="repository-"] option[value="${need('repositoryId')}"]`).first()).toHaveCount(1, { timeout: 60_000 })
  await page.locator('[id^="repository-"]').first().selectOption(need('repositoryId'))
  await page.getByRole('button', { name: /^(confirm|confirmer)$/i }).first().click()
}

test('RH-T12-e reopening the payment modal on the document detail page rotates the idempotency key', async ({ page }) => {
  test.setTimeout(240_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  guards.tolerateConsole('Payment recording failed', "RecordPaymentModal's own console.error for the failures this leg forced")
  const net = recordNetwork(page)

  // Both submits are forced to fail so the ONLY thing that can rotate the key is
  // the modal's closed → open transition (a success also rotates it).
  await page.route('**/api/v1/payments', async (route) => {
    if (route.request().method() !== 'POST') { await route.fallback(); return }
    await guards.forceFail(route, 500, { message: 'forced failure (RH-T12-e)' })
  })

  const paymentsBefore = Number(sql(tenantDb(), `SELECT COUNT(*) FROM payments WHERE company_id=${quoted(need('companyId'))} AND partner_id=${quoted(need('supplierId'))}`)[0] ?? '0')
  await page.goto(`/purchases/orders/${String(fx.poId)}`)
  await expect(page.getByRole('button', { name: /record payment|enregistrer.*paiement/i }).first()).toBeVisible({ timeout: 60_000 })

  await armRecordPaymentModal(page, '10.000')
  await page.getByRole('button', { name: /^record payment \(\d+\)$|^enregistrer le paiement \(\d+\)$/i }).last().click()
  await expect.poll(() => net.matching('/api/v1/payments', 'POST').length, { timeout: 30_000 }).toBe(1)

  // Close and reopen — a NEW payment intent.
  await page.getByRole('button', { name: /^(cancel|annuler)$/i }).last().click()
  await expect(page.locator('#payment-date')).toBeHidden()
  await armRecordPaymentModal(page, '10.000')
  await page.getByRole('button', { name: /^record payment \(\d+\)$|^enregistrer le paiement \(\d+\)$/i }).last().click()
  await expect.poll(() => net.matching('/api/v1/payments', 'POST').length, { timeout: 30_000 }).toBe(2)

  await page.unroute('**/api/v1/payments')
  const posts = net.matching('/api/v1/payments', 'POST')
  const keys = posts.map((w) => bodyField(w, 'idempotency_key') ?? 'MISSING')
  const dbCount = Number(sql(tenantDb(), `SELECT COUNT(*) FROM payments WHERE company_id=${quoted(need('companyId'))} AND partner_id=${quoted(need('supplierId'))}`)[0] ?? '0') - paymentsBefore
  const screenshot = await shot(page, 'RH-T12-e')
  const counts = guards.stop()
  net.stop()

  const rotated = keys.length === 2 && keys[0] !== keys[1]
  const evidence = `${counts.detail === '[]' ? '' : `GUARD ${counts.detail}; `}PO ${String(fx.poNumber)} detail page; modal opened twice, both submits forced 500 (page.route) so success cannot rotate; keys=[${keys.join(' | ')}]; rotated=${String(rotated)}; psql COUNT(payments for supplier)=${String(dbCount)}; screenshot ${screenshot}; ${stamp()}`
  record({ id: 'RH-T12-e', lane: 'T12/T12b', scenario: 'modal open transition rotates the key (two opens, two forced failures)', result: rotated && counts.consoleErrors === 0 ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(posts).toHaveLength(2)
  expect(keys[0], 'a second modal open must mint a NEW key').not.toBe(keys[1])
  expect(dbCount, 'both submits were forced to fail, so nothing may be booked').toBe(0)
})

/* ------------------------------------------------------------------ */
/* F-RH-6 probe — the PO "Record Payment" affordance is a dead end      */
/* ------------------------------------------------------------------ */

/**
 * Gate r1 MAJ-6: the finding quoted a verbatim 422 body that no artefact held.
 * This leg probes it once, asserts the refusal code, and writes the response to
 * `<evidence>/F-RH-6-422.json` so the finding carries its own evidence.
 */
test('RH-F6-a the PO detail page offers Record Payment against an endpoint that always refuses it', async ({ page }) => {
  test.setTimeout(180_000)
  await restoreSession(page)
  const guards = captureGuards(page)

  await page.goto(`/purchases/orders/${String(fx.poId)}`)
  const buttonVisible = await page.getByRole('button', { name: /record payment/i }).first()
    .waitFor({ state: 'visible', timeout: 60_000 }).then(() => true, () => false)

  async function probePayment(documentId: string, label: string): Promise<{ status: number; body: unknown; label: string }> {
    const result = await apiJson(page, 'POST', '/payments', {
      idempotency_key: crypto.randomUUID(),
      partner_id: need('supplierId'),
      document_id: documentId,
      currency: 'TND',
      payment_date: TODAY,
      payments: [{ payment_method_id: need('paymentMethodId'), repository_id: need('repositoryId'), amount: '1.000' }],
    }, need('companyId'))
    return { ...result, label }
  }

  // The refusal REASON depends on the PO's state, so both are captured: the shared
  // fixture PO (status `received`) and a freshly `confirmed` one — the state in
  // which `canRecordPayment` first turns the button on.
  const refusal = await probePayment(String(fx.poId), 'shared fixture PO (received)')
  const freshPo = await apiJson(page, 'POST', '/purchase-orders', {
    partner_id: need('supplierId'), location_id: need('mainLocationId'),
    document_date: TODAY, currency: 'TND', notes: `RH F6 probe ${String(Date.now())}`,
    lines: [{ product_id: need('productIds').get('RH-P1'), description: 'RH-P1', quantity: '1', unit_price: '10.000', tax_configuration_id: need('tax19') }],
  }, need('companyId'))
  expect(freshPo.status, JSON.stringify(freshPo.body)).toBe(201)
  const freshPoId = str(asRec(dataOf(freshPo.body, 'F6 fresh PO')), 'id')
  expect((await apiJson(page, 'POST', `/purchase-orders/${freshPoId}/confirm`, {}, need('companyId'))).status).toBe(200)
  const confirmedRefusal = await probePayment(freshPoId, 'freshly confirmed PO')

  const artefact = writeArtefact('F-RH-6-422.json', JSON.stringify(
    [{ probe: refusal.label, status: refusal.status, body: refusal.body },
     { probe: confirmedRefusal.label, status: confirmedRefusal.status, body: confirmedRefusal.body }], null, 2))
  const envelope = refusal.body === null ? {} : asRec(refusal.body)
  const error = envelope['error'] === undefined ? {} : asRec(envelope['error'])
  const confirmedEnvelope = confirmedRefusal.body === null ? {} : asRec(confirmedRefusal.body)
  const confirmedError = confirmedEnvelope['error'] === undefined ? {} : asRec(confirmedEnvelope['error'])
  const screenshot = await shot(page, 'RH-F6-a')
  const counts = guards.assertClean()

  const evidence = `PO ${String(fx.poNumber)} detail page renders "Record Payment" = ${String(buttonVisible)} (PurchaseOrderDetailPage.tsx:325 canRecordPayment, modal at :709); POST /api/v1/payments with that document_id → HTTP ${String(refusal.status)} code="${String(error['code'])}" message="${String(error['message'])}"; the same probe against a freshly CONFIRMED PO → HTTP ${String(confirmedRefusal.status)} code="${String(confirmedError['code'])}" message="${String(confirmedError['message'])}"; both verbatim bodies saved to ${artefact}; screenshot ${screenshot}; ${stamp()}`
  const ok = buttonVisible && refusal.status === 422 && error['code'] === 'DOCUMENT_NOT_ALLOCATABLE'
    && confirmedRefusal.status === 422 && confirmedError['code'] === 'DOCUMENT_NOT_ALLOCATABLE'
  record({ id: 'RH-F6-a', lane: '(finding F-RH-6)', scenario: 'PO host offers a payment the backend always refuses', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(refusal.status, 'the endpoint refuses a purchase-order allocation').toBe(422)
  expect(error['code']).toBe('DOCUMENT_NOT_ALLOCATABLE')
  expect(confirmedRefusal.status, 'a freshly confirmed PO is refused too').toBe(422)
  expect(confirmedError['code']).toBe('DOCUMENT_NOT_ALLOCATABLE')
  expect(buttonVisible, 'the UI nevertheless offers the action').toBe(true)
})

/* ================================================================== */
/* T13 — transfer / adjustment idempotency (lane rh-t13)               */
/* ================================================================== */

/** Two click events dispatched synchronously — before React can disable the button. */
async function doubleClickSync(page: Page, selector: string): Promise<void> {
  await page.locator(selector).first().evaluate((element: HTMLElement) => {
    element.click()
    element.click()
  })
}

async function addTransferLine(page: Page, sku: string, quantity: string): Promise<void> {
  const picker = page.getByRole('combobox', { name: /search or scan a product|rechercher ou scanner un produit/i })
  await picker.fill(fixtureSku(sku))
  const suggestion = page.getByRole('option', { name: new RegExp(fixtureSku(sku), 'i') }).first()
  await expect(suggestion).toBeVisible({ timeout: 60_000 })
  await suggestion.click()
  const row = page.getByRole('row').filter({ hasText: fixtureName(sku) }).first()
  await row.getByLabel(/^quantity$/i).fill(quantity)
}

test('RH-T13-a throttled double-click on the stock-transfer form creates exactly one transfer', async ({ page }) => {
  test.setTimeout(240_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.route('**/api/v1/stock-transfers', async (route) => {
    if (route.request().method() !== 'POST') { await route.fallback(); return }
    await new Promise((resolve) => setTimeout(resolve, 2500))
    await route.continue()
  })

  const before = Number(sql(tenantDb(), `SELECT COUNT(*) FROM stock_transfers WHERE company_id=${quoted(need('companyId'))}`)[0] ?? '0')
  await page.goto('/inventory/stock-transfers/new')
  await expect(page.locator('#source')).toBeVisible({ timeout: 60_000 })
  await selectWhenLoaded(page, '#source', need('mainLocationId'))
  await selectWhenLoaded(page, '#destination', need('warehouseLocationId'))
  await addTransferLine(page, 'RH-P1', '4')

  await doubleClickSync(page, 'form button[type="submit"]')
  await page.waitForURL(/\/inventory\/stock-transfers\/[0-9a-f-]{36}/, { timeout: 90_000 })
  await page.unroute('**/api/v1/stock-transfers')

  const posts = net.matching('/api/v1/stock-transfers', 'POST')
  const keys = [...new Set(posts.map((w) => bodyField(w, 'idempotency_key') ?? 'MISSING'))]
  const dbCount = Number(sql(tenantDb(), `SELECT COUNT(*) FROM stock_transfers WHERE company_id=${quoted(need('companyId'))}`)[0] ?? '0') - before
  const screenshot = await shot(page, 'RH-T13-a')
  const counts = guards.stop()
  net.stop()

  const evidence = `POST /api/v1/stock-transfers ×${String(posts.length)} (statuses ${posts.map((w) => String(w.status)).join(',')}), distinct idempotency_key=${String(keys.length)} [${keys.join(' | ')}]; psql COUNT(stock_transfers)=${String(dbCount)}; screenshot ${screenshot}; ${stamp()}`
  const ok = dbCount === 1 && keys.length === 1 && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T13-a', lane: 'T13', scenario: 'throttled double-click on CreateStockTransferPage', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(posts, 'the synchronous latch must let exactly ONE POST out').toHaveLength(1)
  expect(dbCount, 'exactly one stock_transfers row').toBe(1)
  expect(keys.length).toBe(1)
})

async function armStockAdjustment(page: Page, sku: string, reasonValue: string, quantity: string): Promise<void> {
  await expect(page.getByLabel(/^location$/i)).toBeVisible({ timeout: 60_000 })
  await page.getByLabel(/^location$/i).selectOption(need('mainLocationId'))
  const picker = page.getByTestId('stock-adjustment-product-picker').getByRole('combobox')
  await picker.fill(fixtureSku(sku))
  const adjustmentOption = page.getByRole('option', { name: new RegExp(fixtureSku(sku), 'i') }).first()
  await expect(adjustmentOption).toBeVisible({ timeout: 60_000 })
  await adjustmentOption.click()
  await page.getByRole('button', { name: /^add line$/i }).click()
  await page.getByLabel(/^reason$/i).first().selectOption(reasonValue)
  await page.getByLabel(/^quantity$/i).first().fill(quantity)
}

test('RH-T13-b throttled double-click on the stock-adjustment form creates exactly one adjustment', async ({ page }) => {
  test.setTimeout(240_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.route('**/api/v1/stock-adjustments', async (route) => {
    if (route.request().method() !== 'POST') { await route.fallback(); return }
    await new Promise((resolve) => setTimeout(resolve, 2500))
    await route.continue()
  })

  const before = Number(sql(tenantDb(), `SELECT COUNT(*) FROM stock_adjustments WHERE company_id=${quoted(need('companyId'))}`)[0] ?? '0')
  await page.goto('/inventory/stock-adjustments/new')
  // A write-off draws stock DOWN and produces the `issue`/`write_off` movement the
  // T2 "Reverse" leg needs.
  await armStockAdjustment(page, 'RH-P2', 'write_off', '5')

  await doubleClickSync(page, 'button:text-is("Post")')
  await page.waitForURL(/\/inventory\/stock-adjustments\/[0-9a-f-]{36}/, { timeout: 90_000 })
  await page.unroute('**/api/v1/stock-adjustments')

  const posts = net.matching('/api/v1/stock-adjustments', 'POST')
  const keys = [...new Set(posts.map((w) => bodyField(w, 'idempotency_key') ?? 'MISSING'))]
  const dbCount = Number(sql(tenantDb(), `SELECT COUNT(*) FROM stock_adjustments WHERE company_id=${quoted(need('companyId'))}`)[0] ?? '0') - before
  const screenshot = await shot(page, 'RH-T13-b')
  const counts = guards.stop()
  net.stop()

  const evidence = `POST /api/v1/stock-adjustments ×${String(posts.length)} (statuses ${posts.map((w) => String(w.status)).join(',')}), distinct idempotency_key=${String(keys.length)} [${keys.join(' | ')}]; psql COUNT(stock_adjustments)=${String(dbCount)}; screenshot ${screenshot}; ${stamp()}`
  const ok = dbCount === 1 && keys.length === 1 && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T13-b', lane: 'T13', scenario: 'throttled double-click on CreateStockAdjustmentPage (Post)', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(posts, 'the synchronous latch must let exactly ONE POST out').toHaveLength(1)
  expect(dbCount, 'exactly one stock_adjustments row').toBe(1)
  expect(keys.length).toBe(1)
})

test('RH-T13-c a forced failure on the adjustment page shows the generic error surface, not a raw exception', async ({ page }) => {
  test.setTimeout(240_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  const rawException = 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "stock_adjustments_idem"'
  await page.route('**/api/v1/stock-adjustments', async (route) => {
    if (route.request().method() !== 'POST') { await route.fallback(); return }
    await guards.forceFail(route, 500, { message: rawException, exception: 'Illuminate\\Database\\QueryException', file: '/var/www/app/Support/Db.php', line: 42 })
  })

  await page.goto('/inventory/stock-adjustments/new')
  await armStockAdjustment(page, 'RH-P3', 'adjustment_positive', '3')
  await page.getByRole('button', { name: /^post$/i }).click()
  await expect.poll(() => net.matching('/api/v1/stock-adjustments', 'POST').length, { timeout: 30_000 }).toBe(1)

  const toast = page.getByText(/could not save the adjustment/i).first()
  await expect(toast).toBeVisible({ timeout: 15_000 })
  const bodyText = await page.locator('body').innerText()
  const leaksRawException = bodyText.includes('SQLSTATE') || bodyText.includes('QueryException') || bodyText.includes('/var/www/app')
  const stillOnForm = new URL(page.url()).pathname === '/inventory/stock-adjustments/new'
  const screenshot = await shot(page, 'RH-T13-c')
  const counts = guards.stop()
  net.stop()
  await page.unroute('**/api/v1/stock-adjustments')

  const evidence = `forced 500 body carried "${rawException.slice(0, 40)}…"; rendered surface = "Could not save the adjustment. Check whether it was created before trying again." (stock-adjustments:create.error toast); page still at ${new URL(page.url()).pathname}; raw exception text present in the DOM = ${String(leaksRawException)}; screenshot ${screenshot}; ${stamp()}`
  const ok = !leaksRawException && stillOnForm && counts.consoleErrors === 0
  record({ id: 'RH-T13-c', lane: 'T13', scenario: 'forced failure → generic error surface (MAJOR-4 follow-up)', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(leaksRawException, 'the raw server exception must never reach the operator').toBe(false)
  expect(stillOnForm, 'a failed post must not navigate away').toBe(true)
})

/* ================================================================== */
/* SETUP 3 — volume for the paginated list legs + LIKE-wildcard bait   */
/* ================================================================== */

/**
 * Three product names that differ ONLY by the character a naive LIKE would treat
 * as a wildcard. A search for the literal `%` name must return ONE row; if the
 * backend interpolates the term raw, all three come back.
 */
const WILDCARD_SKUS = ['RH%PCT', 'RH_UND', 'RHXOTH'] as const

test('RH-SETUP-3 volume: 30+ payments, 30+ stock movements, wildcard-bait products', async ({ page }) => {
  test.setTimeout(900_000)
  await restoreSession(page)
  await page.goto('/dashboard')
  const guards = captureGuards(page)
  const companyId = need('companyId')

  if (fx.volumeSeeded !== true) {
    for (const sku of WILDCARD_SKUS) {
      const created = await apiJson(page, 'POST', '/products', {
        name: `${sku} ${need('fixtureRun')}`, sku: `${sku}-${need('fixtureRun')}`, type: 'part', is_physical: true,
        unit_id: need('pieceUnitId'), purchase_price: '10.000', sale_price: '15.000',
        default_tax_configuration_id: need('tax19'), tax_rate: '19.00',
        requires_batch_tracking: false, is_active: true,
      }, companyId)
      expect([200, 201], JSON.stringify(created.body)).toContain(created.status)
      need('productIds').set(sku, str(asRec(dataOf(created.body, sku)), 'id'))
    }

    // Receipts through the real purchase path (create → confirm → receive), so the
    // movements carry genuine references, products and reasons.
    const batches: readonly (readonly string[])[] = [
      [...WILDCARD_SKUS],
      [...SKUS], [...SKUS], [...SKUS], [...SKUS],
      [...SKUS], [...SKUS], [...SKUS], [...SKUS], [...SKUS],
    ]
    for (const [index, skus] of batches.entries()) {
      const po = await apiJson(page, 'POST', '/purchase-orders', {
        partner_id: need('supplierId'), location_id: need('mainLocationId'),
        document_date: TODAY, currency: 'TND', notes: `RH volume PO ${String(index)}`,
        lines: skus.map((sku) => ({
          product_id: need('productIds').get(sku), description: sku,
          quantity: '10', unit_price: '10.000', tax_configuration_id: need('tax19'),
        })),
      }, companyId)
      expect(po.status, JSON.stringify(po.body)).toBe(201)
      const poId = str(asRec(dataOf(po.body, 'po')), 'id')
      const confirmed = await apiJson(page, 'POST', `/purchase-orders/${poId}/confirm`, {}, companyId)
      expect(confirmed.status, JSON.stringify(confirmed.body)).toBe(200)
      const lines = asRec(dataOf(confirmed.body, 'po'))['lines']
      if (!Array.isArray(lines)) throw new Error('confirmed PO has no lines')
      const quantities: Record<string, string> = {}
      for (const line of lines) quantities[str(asRec(line), 'id')] = '10'
      const received = await apiJson(page, 'POST', `/purchase-orders/${poId}/receive`, { location_id: need('mainLocationId'), quantities }, companyId)
      expect(received.status, JSON.stringify(received.body)).toBe(200)
    }

    // Payments: 30 ordinary + the three wildcard-bait references.
    const references = [
      ...Array.from({ length: 30 }, (_, index) => `RH-PAY-${String(index + 1).padStart(3, '0')}`),
      'RH%PCT-REF', 'RH_UND-REF', 'RHXOTH-REF',
    ]
    for (const reference of references) {
      const created = await apiJson(page, 'POST', '/payments', {
        idempotency_key: crypto.randomUUID(),
        amount: '1.000', payment_method_id: need('paymentMethodId'), repository_id: need('repositoryId'),
        partner_id: need('customerId'), payment_date: TODAY, reference,
      }, companyId)
      expect([200, 201], `create payment ${reference}: ${JSON.stringify(created.body)}`).toContain(created.status)
    }
    fx.volumeSeeded = true
    persistFixtures()
  }

  if (fx.lotSeeded !== true) {
    // A batch-tracked product + a grouped batch write-off: the ONLY path that
    // produces a reversible movement (`movement_type='issue'` + reason in
    // {write_off, expiry, damage} + no `reverses_movement_id`), which the T2
    // "Reverse" leg needs a positive case for. A stock-adjustment write-off is
    // authored as `movement_type='adjustment'` and is deliberately NOT reversible.
    const lot = await apiJson(page, 'POST', '/products', {
      name: `RH-LOT ${need('fixtureRun')}`, sku: `RH-LOT-${need('fixtureRun')}`, type: 'part', is_physical: true,
      unit_id: need('pieceUnitId'), purchase_price: '10.000', sale_price: '15.000',
      default_tax_configuration_id: need('tax19'), tax_rate: '19.00', is_active: true,
    }, companyId)
    expect([200, 201], JSON.stringify(lot.body)).toContain(lot.status)
    const lotProductId = str(asRec(dataOf(lot.body, 'RH-LOT')), 'id')
    need('productIds').set('RH-LOT', lotProductId)

    const lotPo = await apiJson(page, 'POST', '/purchase-orders', {
      partner_id: need('supplierId'), location_id: need('mainLocationId'),
      document_date: TODAY, currency: 'TND', notes: 'RH lot PO',
      lines: [{ product_id: lotProductId, description: 'RH-LOT', quantity: '20', unit_price: '10.000', tax_configuration_id: need('tax19') }],
    }, companyId)
    expect(lotPo.status, JSON.stringify(lotPo.body)).toBe(201)
    const lotPoId = str(asRec(dataOf(lotPo.body, 'lot po')), 'id')
    const lotConfirmed = await apiJson(page, 'POST', `/purchase-orders/${lotPoId}/confirm`, {}, companyId)
    expect(lotConfirmed.status, JSON.stringify(lotConfirmed.body)).toBe(200)
    const lotLines = asRec(dataOf(lotConfirmed.body, 'lot po'))['lines']
    if (!Array.isArray(lotLines) || lotLines[0] === undefined) throw new Error('lot PO has no lines')
    const lotLineId = str(asRec(lotLines[0]), 'id')
    const lotReceived = await apiJson(page, 'POST', `/purchase-orders/${lotPoId}/receive`, {
      location_id: need('mainLocationId'), quantities: { [lotLineId]: '20' },
      batches: { [lotLineId]: { batch_number: `RH-LOT-${need('fixtureRun')}`, expiry_date: '2027-12-31' } },
    }, companyId)
    expect(lotReceived.status, JSON.stringify(lotReceived.body)).toBe(200)

    const batchStock = await apiJson(page, 'GET', `/products/${lotProductId}/batch-stock`, undefined, companyId)
    expect(batchStock.status, JSON.stringify(batchStock.body)).toBe(200)
    const batches = dataOf(batchStock.body, 'batch stock')
    if (!Array.isArray(batches) || batches[0] === undefined) throw new Error('no batch created on receipt')
    const batchUuid = str(asRec(batches[0]), 'uuid')

    const writeOff = await apiJson(page, 'POST', '/batches/write-off-grouped', {
      location_id: need('mainLocationId'),
      lines: [{ batch_id: batchUuid, quantity: '2' }],
      reason: 'damage',
      idempotency_key: crypto.randomUUID(),
    }, companyId)
    expect([200, 201], `grouped write-off: ${JSON.stringify(writeOff.body)}`).toContain(writeOff.status)

    fx.lotSeeded = true
    persistFixtures()
  }

  const payments = sql(tenantDb(), `SELECT COUNT(*) FROM payments WHERE company_id=${quoted(companyId)}`)[0] ?? '0'
  const movements = sql(tenantDb(), `SELECT COUNT(*) FROM stock_movements WHERE company_id=${quoted(companyId)}`)[0] ?? '0'
  const counts = guards.assertClean()
  record({
    id: 'RH-SETUP-3', lane: '—', scenario: 'volume for the paginated list legs',
    result: 'PASS',
    evidence: `psql COUNT(payments)=${payments}, COUNT(stock_movements)=${movements}; wildcard-bait products ${WILDCARD_SKUS.join(', ')} and payment references RH%PCT-REF / RH_UND-REF / RHXOTH-REF exist; ${stamp()}`,
    fivexx: counts.fivexx, consoleErrors: counts.consoleErrors,
  })
})

/* ================================================================== */
/* T3 — payments list + dashboard (lane rh-t3)                         */
/* ================================================================== */

function params(wire: { search: string }): URLSearchParams {
  return new URLSearchParams(wire.search)
}

test('RH-T3-a the payments list sends page/per_page and page 2 matches meta', async ({ page }) => {
  test.setTimeout(240_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.goto('/treasury/payments')
  await expect(page.getByText(/showing \d+ to \d+ of \d+ results/i)).toBeVisible({ timeout: 60_000 })
  const firstWire = net.matching('/api/v1/payments', 'GET')[0]
  if (firstWire === undefined) throw new Error('no /payments GET observed')
  const firstParams = params(firstWire)

  const page1Range = await page.getByText(/showing \d+ to \d+ of \d+ results/i).innerText()

  const [page2Response] = await Promise.all([
    page.waitForResponse((response) => response.url().includes('/api/v1/payments?') && response.url().includes('page=2')),
    page.getByRole('button', { name: 'Next' }).click(),
  ])
  const meta = asRec(asRec(await page2Response.json() as unknown)['meta'] ?? {})
  await expect(page.getByText(`Page 2 of ${String(meta['last_page'])}`)).toBeVisible({ timeout: 30_000 })
  const page2Range = await page.getByText(/showing \d+ to \d+ of \d+ results/i).innerText()
  const rowCount = await page.locator('tbody tr').count()

  const wires = net.matching('/api/v1/payments', 'GET')
  const screenshot = await shot(page, 'RH-T3-a')
  const counts = guards.stop()
  net.stop()

  const evidence = `GET ${wires.map((w) => w.path + w.search).join(' , ')}; page-1 label "${page1Range}"; page-2 label "${page2Range}" + "Page 2 of ${String(meta['last_page'])}"; meta={current_page:${String(meta['current_page'])}, last_page:${String(meta['last_page'])}, per_page:${String(meta['per_page'])}, total:${String(meta['total'])}}; rows on page 2 = ${String(rowCount)}; screenshot ${screenshot}; ${stamp()}`
  const ok = firstParams.get('page') === '1' && firstParams.get('per_page') === '25' && meta['current_page'] === 2 && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T3-a', lane: 'T3', scenario: 'list carries page/per_page; page 2 renders and the pager label matches meta', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail).toBe('[]')
  expect(firstParams.get('page')).toBe('1')
  expect(firstParams.get('per_page')).toBe('25')
  expect(meta['current_page']).toBe(2)
})

test('RH-T3-b the dashboard asks for /payments?page=1&per_page=5 and renders five', async ({ page }) => {
  test.setTimeout(240_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.goto('/dashboard')
  await expect(page.getByRole('heading', { name: /recent payments/i })).toBeVisible({ timeout: 60_000 })
  const wire = net.matching('/api/v1/payments', 'GET')[0]
  if (wire === undefined) throw new Error('the dashboard issued no /payments GET')
  const search = params(wire)
  // Each row is an EntityLink to /treasury/payments/{uuid}; the widget's own
  // "View all" points at the bare list route, so it is excluded by the pattern.
  const rows = await page.locator('a[href^="/treasury/payments/"]').count()
  const screenshot = await shot(page, 'RH-T3-b')
  const counts = guards.stop()
  net.stop()

  const paymentRows = rows
  const evidence = `GET ${wire.path}${wire.search} (page=${String(search.get('page'))}, per_page=${String(search.get('per_page'))}, no legacy limit/sort: limit=${String(search.get('limit'))} sort=${String(search.get('sort'))}); "Recent Payments" widget rendered ${String(paymentRows)} payment links (+1 "View all"); screenshot ${screenshot}; ${stamp()}`
  const ok = search.get('page') === '1' && search.get('per_page') === '5' && paymentRows === 5 && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T3-b', lane: 'T3/T4', scenario: 'Dashboard payments widget bounded to 5', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail).toBe('[]')
  expect(search.get('per_page')).toBe('5')
  expect(paymentRows).toBe(5)
})

test('RH-T3-c payment search: literal % and _ are NOT escaped (Task 3 follow-up, measured)', async ({ page }) => {
  test.setTimeout(240_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.goto('/treasury/payments')
  await expect(page.getByText(/showing \d+ to \d+ of \d+ results/i)).toBeVisible({ timeout: 60_000 })

  const search = page.getByPlaceholder(/^search$/i)
  // The fixture holds RH-PAY-001 … RH-PAY-030. With `%` and `_` escaped, neither
  // pattern term matches ANY reference (0 rows); interpolated raw they behave as
  // SQL wildcards and sweep the whole series. `RH-PAY-001` is the control.
  const terms = ['RH-PAY-00_', 'RH-PAY-0%1', 'RH-PAY-001'] as const
  const totals: number[] = []
  const measured: string[] = []
  for (const term of terms) {
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/v1/payments?') && r.url().includes('search=')),
      (async () => { await search.fill(''); await search.fill(term) })(),
    ])
    const meta = asRec(asRec(await response.json() as unknown)['meta'] ?? {})
    const total = Number(meta['total'] ?? -1)
    totals.push(total)
    measured.push(`search="${term}" → ${new URL(response.url()).search} → meta.total=${String(total)}`)
  }

  const screenshot = await shot(page, 'RH-T3-c')
  const counts = guards.stop()
  net.stop()

  const escaped = totals[0] === 0 && totals[1] === 0 && totals[2] === 1
  const evidence = `${measured.join(' ; ')}; a wildcard-escaped search would measure 0 / 0 / 1; screenshot ${screenshot}; ${stamp()}`
  record({
    id: 'RH-T3-c', lane: 'T3', scenario: 'literal % and _ in the payment search (LIKE escaping)',
    result: escaped ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors,
  })

  expect(counts.detail).toBe('[]')
  expect(totals[2], 'the control term must match exactly its own payment').toBe(1)
  // Deliberately NOT a hard failure of the run: the plan lists LIKE escaping in the
  // payment search as a Task 3 follow-up (Task 2 shipped `ESCAPE '!'`, Task 3 did
  // not). The numbers above are the finding's evidence either way.
})

/* ================================================================== */
/* T2 — stock movements (lane rh-t2)                                   */
/* ================================================================== */

test('RH-T2-a stock-movement searches and filters are SERVER-side; totals stay global on page 2', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.goto('/inventory/movements')
  await expect(page.getByText(/showing \d+ to \d+ of \d+ results/i)).toBeVisible({ timeout: 60_000 })
  const first = net.matching('/api/v1/stock-movements', 'GET')[0]
  if (first === undefined) throw new Error('no /stock-movements GET observed')
  const globalTotal = Number(/of (\d+) results/.exec(await page.getByText(/showing \d+ to \d+ of \d+ results/i).innerText())?.[1] ?? '-1')
  const headerCount = await page.getByText(/\d+ movements? recorded/i).innerText()

  const searchBox = page.getByPlaceholder(/search movements/i)
  const probes: string[] = []

  /** Type a term, wait for the server request it must produce, and report what came back. */
  async function serverSearch(term: string, label: string): Promise<{ total: number; rows: number; search: string }> {
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/v1/stock-movements?') && r.url().includes('search=')),
      (async () => { await searchBox.fill(''); await searchBox.fill(term) })(),
    ])
    const body = asRec(await response.json() as unknown)
    const meta = asRec(body['meta'] ?? {})
    const data = body['data']
    const total = Number(meta['total'] ?? -1)
    await expect(page.getByText(/showing \d+ to \d+ of \d+ results|no results found/i).first()).toBeVisible({ timeout: 30_000 })
    const rows = Array.isArray(data) ? data.length : -1
    const search = new URL(response.url()).search
    probes.push(`${label}: "${term}" → ${search} → meta.total=${String(total)} data.length=${String(rows)}`)
    return { total, rows, search }
  }

  const byName = await serverSearch('RH-P1', 'product name')
  const bySku = await serverSearch(fixtureSku('RH-P1'), 'SKU')
  const byReference = await serverSearch('PO-2026', 'reference')
  const literalPercent = await serverSearch('RH!%PCT'.replace('!', ''), 'literal %')
  const literalUnderscore = await serverSearch('RH_UND', 'literal _')
  const wildcardBait = await serverSearch('RH%UND', 'wildcard bait (% where the fixture has _)')

  // Clearing the box must go back to the unfiltered list without a 422. The page
  // OMITS `search` rather than sending `search=`, and the unfiltered key is already
  // in the TanStack cache (staleTime 5 min), so there is deliberately NO new
  // request to wait on — the rendered global total is the observable.
  await searchBox.fill('')
  await expect(page.getByText(`Showing 1 to 25 of ${String(globalTotal)} results`)).toBeVisible({ timeout: 30_000 })
  // The blank-parameter contract itself (Task 2 as-shipped note 1: every optional
  // filter is `nullable`, so a cleared web filter sent as '' is a 200, not a 422)
  // is proven directly against the endpoint.
  const blankProbe = await apiJson(page, 'GET', '/stock-movements?search=&movement_type=&reason=&page=1&per_page=25', undefined, need('companyId'))
  probes.push(`cleared box → rendered "${await page.getByText(/showing \d+ to \d+ of \d+ results/i).innerText()}" with no new request (cached unfiltered key); GET /stock-movements?search=&movement_type=&reason= → HTTP ${String(blankProbe.status)}`)

  // Filter tabs must move the filter to the server, not filter the current page.
  const [transferResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/v1/stock-movements?') && r.url().includes('movement_type=transfer')),
    page.getByRole('button', { name: 'Transfers' }).click(),
  ])
  const transferMeta = asRec(asRec(await transferResponse.json() as unknown)['meta'] ?? {})
  probes.push(`tab Transfers → ${new URL(transferResponse.url()).search} → meta.total=${String(transferMeta['total'])}`)

  const [writeOffResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/v1/stock-movements?') && r.url().includes('reason=write_off')),
    page.getByRole('button', { name: 'Write-Offs' }).click(),
  ])
  const writeOffMeta = asRec(asRec(await writeOffResponse.json() as unknown)['meta'] ?? {})
  probes.push(`tab Write-Offs → ${new URL(writeOffResponse.url()).search} → meta.total=${String(writeOffMeta['total'])}`)

  // Back to All, then page 2: the header total must stay GLOBAL. (No waitForResponse
  // here either — the unfiltered page-1 key is cached.)
  await page.getByRole('button', { name: 'All' }).click()
  await expect(page.getByText(`Showing 1 to 25 of ${String(globalTotal)} results`)).toBeVisible({ timeout: 30_000 })
  const [page2Response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/v1/stock-movements?') && r.url().includes('page=2')),
    page.getByRole('button', { name: 'Next' }).click(),
  ])
  const page2Meta = asRec(asRec(await page2Response.json() as unknown)['meta'] ?? {})
  await expect(page.getByText(`Page 2 of ${String(page2Meta['last_page'])}`)).toBeVisible({ timeout: 30_000 })
  const headerCountPage2 = await page.getByText(/\d+ movements? recorded/i).innerText()
  probes.push(`page 2 → ${new URL(page2Response.url()).search} → meta.total=${String(page2Meta['total'])}; header "${headerCountPage2}" (page 1 was "${headerCount}")`)

  const screenshot = await shot(page, 'RH-T2-a')
  const counts = guards.stop()
  net.stop()

  const escapingHolds = literalPercent.total === 1 && literalUnderscore.total >= 1 && wildcardBait.total === 0
  const serverSide = byName.total >= 1 && bySku.total >= 1 && byReference.total >= 1
  const totalsGlobal = headerCountPage2 === headerCount && Number(page2Meta['total']) === globalTotal
  const evidence = `${probes.join(' ; ')}; screenshot ${screenshot}; ${stamp()}`
  const ok = serverSide && escapingHolds && totalsGlobal && blankProbe.status === 200 && counts.fivexx === 0 && counts.consoleErrors === 0
  record({
    id: 'RH-T2-a', lane: 'T2',
    scenario: 'server-side search (name/SKU/reference/literal %/literal _), server-side type filters, global totals on page 2, cleared filter = 200',
    result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors,
  })

  expect(counts.detail).toBe('[]')
  expect(blankProbe.status, 'a blank filter must be a 200, never a 422').toBe(200)
  expect(wildcardBait.total, "`%` must be escaped: RH%UND must not match the product named RH_UND").toBe(0)
  expect(totalsGlobal, 'the header count must stay global on page 2').toBe(true)
})

test('RH-T2-b Reverse appears on exactly the reversible write-off movements', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)

  const reversibleInDb = Number(sql(tenantDb(), `SELECT COUNT(*) FROM stock_movements WHERE company_id=${quoted(need('companyId'))} AND movement_type='issue' AND reason IN ('write_off','expiry','damage') AND reverses_movement_id IS NULL`)[0] ?? '-1')
  const nonIssueWriteOffs = Number(sql(tenantDb(), `SELECT COUNT(*) FROM stock_movements WHERE company_id=${quoted(need('companyId'))} AND reason IN ('write_off','expiry','damage') AND movement_type <> 'issue'`)[0] ?? '-1')

  await page.goto('/inventory/movements')
  await expect(page.getByText(/showing \d+ to \d+ of \d+ results/i)).toBeVisible({ timeout: 60_000 })
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/v1/stock-movements?') && r.url().includes('reason=write_off')),
    page.getByRole('button', { name: 'Write-Offs' }).click(),
  ])
  await expect(page.getByText(/showing \d+ to \d+ of \d+ results|no results found/i).first()).toBeVisible({ timeout: 30_000 })

  const reverseButtons = await page.getByRole('button', { name: 'Reverse' }).count()
  const writeOffRows = await page.locator('tbody tr').count()
  const screenshot = await shot(page, 'RH-T2-b')
  const counts = guards.stop()

  const evidence = `Write-Offs tab: ${String(writeOffRows)} rows rendered, ${String(reverseButtons)} "Reverse" buttons; psql reversible (movement_type='issue' AND reason IN (write_off,expiry,damage) AND reverses_movement_id IS NULL) = ${String(reversibleInDb)}; psql write-off-reason movements that are NOT type=issue = ${String(nonIssueWriteOffs)}; screenshot ${screenshot}; ${stamp()}`
  const ok = reverseButtons === reversibleInDb && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T2-b', lane: 'T2', scenario: 'Reverse is offered on issue write-offs only', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail).toBe('[]')
  expect(reverseButtons, 'one Reverse per reversible movement, none elsewhere').toBe(reversibleInDb)
})

/* ================================================================== */
/* SETUP 4 — a second company (company-switch legs)                    */
/* ================================================================== */

test('RH-SETUP-4 second company with its own product', async ({ page }) => {
  test.setTimeout(600_000)
  await restoreSession(page)
  await page.goto('/dashboard')
  const guards = captureGuards(page)

  if (fx.company2Id === undefined) {
    const created = await apiJson(page, 'POST', '/companies', {
      name: `RH Company 2 ${need('fixtureRun')}`, legal_name: `RH Company 2 ${need('fixtureRun')}`,
      country_code: 'TN', currency: 'TND', locale: 'en', timezone: 'UTC',
    }, need('companyId'))
    expect(created.status, JSON.stringify(created.body)).toBe(201)
    fx.company2Id = str(asRec(dataOf(created.body, 'company 2')), 'id')
    fx.company2Name = `RH Company 2 ${need('fixtureRun')}`

    const units = rowsOf((await apiJson(page, 'GET', apiRoutes.units, undefined, fx.company2Id)).body, 'c2 units')
    const piece = units.find((u) => u['code'] === 'pc' || u['symbol'] === 'pc') ?? units[0]
    if (piece === undefined) throw new Error('company 2 has no units')
    const product = await apiJson(page, 'POST', '/products', {
      name: `RH-C2ONLY ${need('fixtureRun')}`, sku: `RH-C2ONLY-${need('fixtureRun')}`, type: 'part',
      is_physical: true, unit_id: str(piece, 'id'), purchase_price: '10.000', sale_price: '15.000',
      requires_batch_tracking: false, is_active: true,
    }, fx.company2Id)
    expect([200, 201], JSON.stringify(product.body)).toContain(product.status)
    persistFixtures()
  }

  // MIN-6: prove the "company 2 ONLY" half rather than asserting it in prose.
  const underCompany1 = rowsOf(
    (await apiJson(page, 'GET', `/products?search=RH-C2ONLY-${need('fixtureRun')}&per_page=50`, undefined, need('companyId'))).body,
    'company-1 search for the company-2 product',
  )
  const underCompany2 = rowsOf(
    (await apiJson(page, 'GET', `/products?search=RH-C2ONLY-${need('fixtureRun')}&per_page=50`, undefined, need('company2Id'))).body,
    'company-2 search for the company-2 product',
  )
  expect(underCompany1, 'the company-2 product must not be visible under company 1').toHaveLength(0)
  expect(underCompany2.length, 'the company-2 product must be visible under company 2').toBeGreaterThan(0)

  const counts = guards.assertClean()
  record({
    id: 'RH-SETUP-4', lane: '—', scenario: 'second company + a company-2-only product',
    result: 'PASS',
    evidence: `company2=${String(fx.company2Name)} (${String(fx.company2Id)}); GET /products?search=RH-C2ONLY-${need('fixtureRun')} returns ${String(underCompany1.length)} row(s) under company 1 and ${String(underCompany2.length)} under company 2 (negative probe, MIN-6); ${stamp()}`,
    fivexx: counts.fivexx, consoleErrors: counts.consoleErrors,
  })
})

/* ================================================================== */
/* T14 — strictly serialized draft autosave (lane rh-t14)              */
/* ================================================================== */

/** Add one product line through the house LineItemEntryBar on a DocumentForm/editor. */
async function addDocumentLine(page: Page, sku: string): Promise<void> {
  const picker = page.getByRole('combobox', { name: /search or scan a product|rechercher ou scanner un produit/i })
  await expect(picker).toBeVisible({ timeout: 60_000 })
  await picker.fill(fixtureSku(sku))
  const option = page.getByRole('option', { name: new RegExp(fixtureSku(sku), 'i') }).first()
  await expect(option).toBeVisible({ timeout: 60_000 })
  await option.click()
  await expect(page.getByRole('row').filter({ hasText: fixtureName(sku) }).first()).toBeVisible({ timeout: 60_000 })
}

test('RH-T14-a throttled typing never overlaps two autosaves and the last body wins', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  // Each autosave is held on the wire for 4 s while the debounce is 3 s, so a
  // second edit lands mid-flight — exactly the race Task 14 serializes.
  await page.route('**/api/v1/documents/auto-save', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 4000))
    await route.continue()
  })

  await page.goto('/sales/invoices/new')
  // Autosave is gated on at least one line (useDraftAutoSave.ts:407), so the
  // document must carry one before any typing can be observed on the wire.
  await addDocumentLine(page, 'RH-P1')
  const notes = page.locator('#notes')
  await expect(notes).toBeVisible({ timeout: 60_000 })

  const typed: string[] = []
  for (let index = 1; index <= 5; index += 1) {
    const value = `RH-T14 body ${String(index)}`
    typed.push(value)
    await notes.fill(value)
    // > the 3 s debounce but < the 4 s throttled flight: every edit after the first
    // arrives while the previous save is still physically on the wire.
    await page.waitForTimeout(3400)
  }
  // Let the tail drain: the trailing slot must still issue the LAST body.
  await expect.poll(
    () => {
      const saves = net.matching('/documents/auto-save', 'POST')
      return saves.length > 0 && saves.every((w) => w.finishedAt !== null)
    },
    { timeout: 60_000 },
  ).toBe(true)
  await page.waitForTimeout(9000)

  const saves = net.matching('/documents/auto-save', 'POST').slice().sort((a, b) => a.startedAt - b.startedAt)
  const overlaps: string[] = []
  for (let index = 1; index < saves.length; index += 1) {
    const previous = saves[index - 1]
    const current = saves[index]
    if (previous === undefined || current === undefined) continue
    if (previous.finishedAt === null || current.startedAt < previous.finishedAt) {
      overlaps.push(`#${String(index)} started ${String(current.startedAt - (previous.finishedAt ?? current.startedAt))}ms before #${String(index - 1)} settled`)
    }
  }
  const bodies = saves.map((w) => {
    try { return String((JSON.parse(w.postData ?? '{}') as Record<string, unknown>)['notes'] ?? '') } catch { return '?' }
  })
  const lastTyped = typed[typed.length - 1] ?? ''
  const persisted = sql(tenantDb(), `SELECT notes FROM documents WHERE company_id=${quoted(need('companyId'))} AND notes LIKE 'RH-T14 %' ORDER BY created_at DESC LIMIT 1`)[0] ?? '(none)'
  const screenshot = await shot(page, 'RH-T14-a')
  const counts = guards.stop()
  net.stop()
  await page.unroute('**/api/v1/documents/auto-save')

  const evidence = `POST /api/v1/documents/auto-save ×${String(saves.length)} (throttled 4 s each, debounce 3 s, 5 edits 3.4 s apart); request windows ${saves.map((w) => `[${String(w.startedAt)}→${String(w.finishedAt)}]`).join(' ')}; overlaps=${overlaps.length === 0 ? 'none' : overlaps.join(', ')}; bodies.notes=[${bodies.join(' | ')}]; last typed="${lastTyped}"; psql documents.notes = "${persisted}"; screenshot ${screenshot}; ${stamp()}`
  // NOTE (finding F-RH-2): `documents.notes` stays at the FIRST autosaved value —
  // `DraftPersistenceService::saveDraft()` updates "lines only, header is
  // immutable for now", so no later header edit is ever persisted by autosave.
  // Task 14 is about serialization, so the leg's verdict is the wire behaviour;
  // the persisted value is reported as evidence for that separate finding.
  const ok = overlaps.length === 0 && bodies[bodies.length - 1] === lastTyped && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T14-a', lane: 'T14', scenario: 'throttled typing: at most one autosave in flight, and the last body is the one that reaches the WIRE (persistence: see finding F-RH-2)', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail).toBe('[]')
  expect(saves.length, 'the serialization claim is vacuous with fewer than two saves').toBeGreaterThanOrEqual(2)
  expect(overlaps, 'two autosaves must never be on the wire at once').toEqual([])
  expect(bodies[bodies.length - 1], 'the final autosave must carry the last typed value').toBe(lastTyped)
})

test('RH-T14-b a failed autosave is retried with the LATEST body, not the one that failed', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  guards.tolerateConsole('Auto-save failed', "useDraftAutoSave's own console.error for the failure this leg forced")
  const net = recordNetwork(page)

  let failedOnce = false
  await page.route('**/api/v1/documents/auto-save', async (route) => {
    if (!failedOnce) {
      failedOnce = true
      await new Promise((resolve) => setTimeout(resolve, 3000))
      await guards.forceFail(route, 500, { message: 'forced failure (RH-T14-b)' })
      return
    }
    await route.continue()
  })

  await page.goto('/sales/invoices/new')
  await addDocumentLine(page, 'RH-P1')
  const notes = page.locator('#notes')
  await expect(notes).toBeVisible({ timeout: 60_000 })

  await notes.fill('RH-T14b first body')
  await page.waitForTimeout(3400)          // debounce fires, the save is on the wire and will 500
  await notes.fill('RH-T14b LATEST body')  // the operator keeps typing during the failing flight
  await page.waitForTimeout(12_000)

  const saves = net.matching('/documents/auto-save', 'POST').slice().sort((a, b) => a.startedAt - b.startedAt)
  const bodies = saves.map((w) => {
    try { return String((JSON.parse(w.postData ?? '{}') as Record<string, unknown>)['notes'] ?? '') } catch { return '?' }
  })
  const persisted = sql(tenantDb(), `SELECT notes FROM documents WHERE company_id=${quoted(need('companyId'))} AND notes LIKE 'RH-T14b%' ORDER BY created_at DESC LIMIT 1`)[0] ?? '(none)'
  const screenshot = await shot(page, 'RH-T14-b')
  const counts = guards.stop()
  net.stop()
  await page.unroute('**/api/v1/documents/auto-save')

  const retried = saves.length >= 2 && bodies[bodies.length - 1] === 'RH-T14b LATEST body'
  const evidence = `POST ×${String(saves.length)} statuses=[${saves.map((w) => String(w.status)).join(',')}] (the first 500 is forced by page.route); bodies.notes=[${bodies.join(' | ')}]; psql documents.notes = "${persisted}"; screenshot ${screenshot}; ${stamp()}`
  record({ id: 'RH-T14-b', lane: 'T14', scenario: 'forced 500 on one autosave → the retry carries the LATEST body', result: retried ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(saves.length, 'the trailing slot must issue a follow-up save after the failure').toBeGreaterThanOrEqual(2)
  expect(bodies[bodies.length - 1], 'the retry must carry the latest body, not the failed one').toBe('RH-T14b LATEST body')
})

/* ================================================================== */
/* T5 — LineItemEntryBar product-search debounce (lane rh-t5)          */
/* ================================================================== */

/** Routes that mount the debounced `LineItemEntryBar` (Task 5's four consumers). */
const ENTRY_BAR_ROUTES: ReadonlyArray<{ consumer: string; route: string; arm?: (page: Page) => Promise<void> }> = [
  { consumer: 'DocumentLineEditor (PO editor)', route: '/purchases/orders/new' },
  { consumer: 'CreateStockTransferPage', route: '/inventory/stock-transfers/new' },
  { consumer: 'ReplenishmentCapturePage', route: '/inventory/replenishment/new' },
  { consumer: 'CreateCountingPage', route: '/inventory/counting/create', arm: async (page: Page) => {
    // The entry bar only renders once the wizard's scope is Product (or
    // Product + Location) and the wizard has advanced to the selection step.
    // The scope tile's accessible name is title + description — "Product Count
    // specific products across all locations" (CreateCountingPage.tsx:303-316 with
    // inventory.json counting.scopeTypes.product + counting.scopeDescriptions.product),
    // so an anchored /^Product$/ can never match it (gate r1 MAJ-1).
    await page.getByRole('button', { name: /^Product\b/ }).first().click({ timeout: 15_000 })
    await page.getByRole('button', { name: /^(next|continue|suivant)$/i }).first().click({ timeout: 15_000 })
  } },
]

test('RH-T5-a a six-character burst issues at most two product searches on every consumer', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const measured: string[] = []
  const failures: string[] = []
  const blocked: string[] = []

  for (const { consumer, route, arm } of ENTRY_BAR_ROUTES) {
    const net = recordNetwork(page)
    await page.goto(route)
    if (arm !== undefined) {
      await page.waitForTimeout(1500)
      await Promise.race([
        arm(page).catch(() => undefined),
        new Promise((resolve) => setTimeout(resolve, 30_000)),
      ])
    }
    const picker = page.getByRole('combobox', { name: /search or scan a product|rechercher ou scanner un produit/i })
    const reachable = await picker.first().waitFor({ state: 'visible', timeout: 20_000 }).then(() => true, () => false)
    if (!reachable) {
      measured.push(`${consumer} (${route}): BLOCKED — the entry bar did not render within the leg budget (it sits behind the counting wizard's scope step)`)
      blocked.push(consumer)
      net.stop()
      continue
    }
    await picker.first().click()
    net.reset()
    await picker.first().pressSequentially('RH-P1X', { delay: 25 })   // 6 characters in ~150 ms
    await page.waitForTimeout(1500)
    const searches = net.matching('/api/v1/products', 'GET').filter((w) => w.search.includes('search='))
    measured.push(`${consumer} (${route}): 6 keystrokes in ~150 ms → ${String(searches.length)} search request(s) [${searches.map((w) => new URLSearchParams(w.search).get('search') ?? '?').join(' | ')}]`)
    if (searches.length > 2) failures.push(`${consumer} issued ${String(searches.length)}`)
    net.stop()
  }

  const screenshot = await shot(page, 'RH-T5-a')
  const counts = guards.stop()
  const evidence = `${measured.join(' ; ')}; debounce = 250 ms (LineItemEntryBar PRODUCT_SEARCH_DEBOUNCE_MS); screenshot ${screenshot}; ${stamp()}`
  const ok = failures.length === 0 && counts.fivexx === 0 && counts.consoleErrors === 0
  record({
    id: 'RH-T5-a', lane: 'T5', scenario: 'debounced product search across the four consumers',
    result: ok ? (blocked.length === 0 ? 'PASS' : 'BLOCKED') : 'FAIL',
    evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors,
  })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  // Gate r2-5: `blocked` drives the BLOCKED ledger verdict, so it must be asserted
  // too — otherwise selector rot would silently downgrade a leg on a green test.
  expect(blocked, 'every T5 consumer must actually be measured, not downgraded to BLOCKED').toEqual([])
  expect(failures, 'every consumer must debounce a 6-character burst into ≤2 searches').toEqual([])
})

test('RH-T5-b three characters + Enter inside the debounce window resolves as a SCAN within 250 ms', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.goto('/purchases/orders/new')
  const picker = page.getByRole('combobox', { name: /search or scan a product|rechercher ou scanner un produit/i }).first()
  await expect(picker).toBeVisible({ timeout: 60_000 })
  await picker.click()
  net.reset()

  await picker.pressSequentially('RHX', { delay: 20 })
  const pressedAt = Date.now()
  const [resolveResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/v1/line-entry/resolve-code'), { timeout: 10_000 }),
    picker.press('Enter'),
  ])
  const resolveWire = net.matching('/api/v1/line-entry/resolve-code')[0]
  // MIN-3: never synthesise a ~0 ms latency from a missing wire.
  expect(resolveWire, 'Enter must have issued a resolve-code request').toBeDefined()
  const latency = (resolveWire === undefined ? Number.NaN : resolveWire.startedAt) - pressedAt

  const screenshot = await shot(page, 'RH-T5-b')
  const counts = guards.stop()
  net.stop()

  const evidence = `typed "RHX" (3 chars, 20 ms apart) then Enter; GET ${String(resolveWire?.path)}${String(resolveWire?.search)} issued ${String(latency)} ms after the last keystroke (HTTP ${String(resolveResponse.status())}); the suggestion list is untrusted inside the debounce window (suggestionsSettled), so Enter takes the scan fork; screenshot ${screenshot}; ${stamp()}`
  const ok = latency < 250 && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T5-b', lane: 'T5', scenario: '3 chars + Enter inside the 250 ms window → undebounced scan resolve', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail).toBe('[]')
  expect(latency, 'the scan fork must not wait for the search debounce').toBeLessThan(250)
})

test('RH-T5-c a suggestion from company A is not applied after switching to company B', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.goto('/purchases/orders/new')
  const picker = page.getByRole('combobox', { name: /search or scan a product|rechercher ou scanner un produit/i }).first()
  await expect(picker).toBeVisible({ timeout: 60_000 })
  await picker.fill(fixtureSku('RH-P1'))
  const companyAOption = page.getByRole('option', { name: new RegExp(fixtureSku('RH-P1'), 'i') }).first()
  await expect(companyAOption).toBeVisible({ timeout: 30_000 })

  // Switch company with the dropdown open — the page is NOT unmounted.
  await page.getByRole('button', { name: 'Select company' }).click()
  await page.getByRole('button', { name: need('company2Name'), exact: true }).click()
  await expect(page.getByRole('button', { name: 'Select company' })).toContainText(need('company2Name'), { timeout: 30_000 })
  await page.waitForTimeout(1500)

  const stillOffered = await page.getByRole('option', { name: new RegExp(fixtureSku('RH-P1'), 'i') }).count()
  const searchesAfterSwitch = net.matching('/api/v1/products', 'GET').filter((w) => w.search.includes('search='))
  const screenshot = await shot(page, 'RH-T5-c')
  const counts = guards.stop()
  net.stop()

  const evidence = `company A suggestion "${fixtureSku('RH-P1')}" was visible, then switched to "${need('company2Name')}" with the dropdown open; company-A option still offered = ${String(stillOffered)}; product searches observed = ${String(searchesAfterSwitch.length)}; screenshot ${screenshot}; ${stamp()}`
  const ok = stillOffered === 0 && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T5-c', lane: 'T5/placeholder', scenario: 'company switch with the suggestion dropdown open', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  // Put the session back on company 1 for the following legs.
  await page.getByRole('button', { name: 'Select company' }).click()
  await page.getByRole('button', { name: need('companyName'), exact: true }).click()

  expect(counts.detail).toBe('[]')
  expect(stillOffered, "company A's product must not remain selectable under company B").toBe(0)
})

/* ================================================================== */
/* T7 — stock-level request dedupe (lane rh-t7)                        */
/* ================================================================== */

test('RH-T7-a the transfer page reads stock levels once per DISTINCT product, not once per component', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.goto('/inventory/stock-transfers/new')
  await expect(page.locator('#source')).toBeVisible({ timeout: 60_000 })
  await selectWhenLoaded(page, '#source', need('mainLocationId'))
  await selectWhenLoaded(page, '#destination', need('warehouseLocationId'))
  net.reset()

  // Two lines of the SAME product, then one of a different product. Each line
  // mounts BOTH AvailabilityCell and TransferSourceSuggestion.
  await addTransferLine(page, 'RH-P1', '1')
  await page.waitForTimeout(1200)
  const afterFirst = net.matching('/stock-levels', 'GET').length
  await addTransferLine(page, 'RH-P1', '1')
  await page.waitForTimeout(1200)
  const afterDuplicate = net.matching('/stock-levels', 'GET').length
  await addTransferLine(page, 'RH-P2', '1')
  await page.waitForTimeout(1500)
  const afterSecondProduct = net.matching('/stock-levels', 'GET').length

  const wires = net.matching('/stock-levels', 'GET')
  const screenshot = await shot(page, 'RH-T7-a')
  const counts = guards.stop()
  net.stop()

  const evidence = `GET /api/v1/products/{id}/stock-levels — after 1 line of RH-P1 (2 consuming components): ${String(afterFirst)}; after a SECOND line of the same product (4 components): ${String(afterDuplicate)}; after a line of RH-P2 (6 components, 2 distinct products): ${String(afterSecondProduct)}; urls=[${wires.map((w) => w.path).join(' | ')}]; screenshot ${screenshot}; ${stamp()}`
  const ok = afterFirst === 1 && afterDuplicate === 1 && afterSecondProduct === 2 && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T7-a', lane: 'T7', scenario: 'one stock-level read per distinct (product, variant), not per component', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail).toBe('[]')
  expect(afterFirst, 'two components on one line share ONE request').toBe(1)
  expect(afterDuplicate, 'a second line of the same product adds NO request').toBe(1)
  expect(afterSecondProduct, 'a distinct product adds exactly one request').toBe(2)
})

/* ================================================================== */
/* T6 — bulk pricing-context debounce (lane rh-t6)                     */
/* ================================================================== */

/**
 * The pricing query is armed by FOCUSING a price cell (`focusedPriceLineId`) and
 * keyed on the line's `unit_price` — the quantity column is deliberately not part
 * of the signature, so quantity edits issue nothing. (Scope correction to the
 * brief's "rapid qty edits": DocumentLineEditor.tsx pricingContextLines.)
 */
async function pricingBurst(
  page: Page,
  route: string,
  net: ReturnType<typeof recordNetwork>,
  arm?: (page: Page) => Promise<void>,
): Promise<{ requests: number; lastUnitPrice: string | null }> {
  await page.goto(route)
  if (arm !== undefined) await arm(page)
  await addDocumentLine(page, 'RH-P1')
  const priceCell = page.getByLabel(/^unit price$/i).first()
  await expect(priceCell).toBeVisible({ timeout: 30_000 })
  await priceCell.click()
  net.reset()
  for (const value of ['11', '12', '13', '14', '15', '16']) {
    await priceCell.fill(value)
    await page.waitForTimeout(40)
  }
  await page.waitForTimeout(2000)
  const wires = net.matching('/line-entry/pricing-context/bulk', 'POST')
  const last = wires[wires.length - 1]
  let lastUnitPrice: string | null = null
  if (last?.postData != null) {
    try {
      const parsed = JSON.parse(last.postData) as { lines?: { unit_price?: string }[] }
      lastUnitPrice = parsed.lines?.[0]?.unit_price ?? null
    } catch { lastUnitPrice = null }
  }
  return { requests: wires.length, lastUnitPrice }
}

test('RH-T6-a rapid unit-price edits are debounced and the LAST value wins (PO + credit-note editors)', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  const po = await pricingBurst(page, '/purchases/orders/new', net)
  // The credit-note editor only mounts in "From Customer" mode with a partner
  // chosen (CreateCreditNotePage.tsx:626).
  const creditNote = await pricingBurst(page, '/sales/credit-notes/create', net, async (target) => {
    await target.getByText('From Customer', { exact: true }).click({ timeout: 20_000 })
    const partnerPicker = target.getByRole('combobox').filter({ hasNot: target.getByRole('option') }).first()
    await partnerPicker.fill(need('customerName'))
    await target.getByRole('option', { name: new RegExp(need('customerName').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i') }).first().click({ timeout: 20_000 })
  })

  const screenshot = await shot(page, 'RH-T6-a')
  const counts = guards.stop()
  net.stop()

  const evidence = `POST /api/v1/line-entry/pricing-context/bulk — PO editor (/purchases/orders/new): 6 price edits 40 ms apart → ${String(po.requests)} request(s), last body lines[0].unit_price="${String(po.lastUnitPrice)}"; credit-note editor (/sales/credit-notes/create): → ${String(creditNote.requests)} request(s), last body lines[0].unit_price="${String(creditNote.lastUnitPrice)}"; debounce = 250 ms (DocumentLineEditor useDebouncedValue); screenshot ${screenshot}; ${stamp()}`
  const bounded = po.requests <= 2 && creditNote.requests <= 2
  const lastWins = po.lastUnitPrice === '16' && creditNote.lastUnitPrice === '16'
  const ok = bounded && lastWins && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-T6-a', lane: 'T6', scenario: 'debounced bulk pricing on the PO and credit-note editors; last value wins', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(po.requests, 'a 6-edit burst must not issue 6 pricing requests').toBeLessThanOrEqual(2)
  expect(creditNote.requests).toBeLessThanOrEqual(2)
  expect(po.lastUnitPrice, 'the surviving request carries the LAST typed price').toBe('16')
  expect(creditNote.lastUnitPrice, 'same on the credit-note editor').toBe('16')
})

/**
 * Gate r1 BLK-1 ruling: the console errors this leg emits are NOT tolerated. One of
 * them is the F-RH-3 defect itself (the mounted editor replays company-1
 * `product_id`s under company 2 and gets a 422), so the leg is recorded **FAIL**
 * with that 422 as its measured evidence. The two contract assertions the T6 lane
 * actually owns — no request under the OLD scope, no 5xx — are still enforced, so
 * the run stays serial-green while the ledger tells the truth.
 */
test('RH-T6-b a company switch mid-edit does not apply stale pricing (records the F-RH-3 replay as a FAIL)', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.goto('/purchases/orders/new')
  await addDocumentLine(page, 'RH-P1')
  const priceCell = page.getByLabel(/^unit price$/i).first()
  await priceCell.click()
  await priceCell.fill('21')
  await page.waitForTimeout(1200)
  const beforeSwitch = net.matching('/line-entry/pricing-context/bulk', 'POST')

  await page.getByRole('button', { name: 'Select company' }).click()
  await page.getByRole('button', { name: need('company2Name'), exact: true }).click()
  await expect(page.getByRole('button', { name: 'Select company' })).toContainText(need('company2Name'), { timeout: 30_000 })
  await page.waitForTimeout(2500)

  const afterSwitch = net.matching('/line-entry/pricing-context/bulk', 'POST').slice(beforeSwitch.length)
  const wrongScope = afterSwitch.filter((w) => w.companyHeader !== need('company2Id'))
  const screenshot = await shot(page, 'RH-T6-b')
  const counts = guards.stop()
  net.stop()

  const statuses = afterSwitch.map((w) => String(w.status)).join(',')
  const replay422 = afterSwitch.filter((w) => w.status === 422)
  const evidence = `before switch: ${String(beforeSwitch.length)} pricing POST(s) with X-Company-Id=${String(beforeSwitch[0]?.companyHeader)}; after switching to "${need('company2Name')}": ${String(afterSwitch.length)} pricing POST(s) statuses=[${statuses}], X-Company-Id=[${afterSwitch.map((w) => String(w.companyHeader)).join(' | ')}]; requests still carrying the OLD company = ${String(wrongScope.length)}; 422 replays of company-1 product ids under company 2 = ${String(replay422.length)}; MEASURED guard findings (untolerated) = ${counts.detail}; tolerated = ${counts.tolerated}; screenshot ${screenshot}; ${stamp()}`
  // The T6 contract (request SCOPE) holds. The leg is nevertheless FAIL: the
  // still-mounted editor replays company-1 product ids under company 2 and the
  // resulting 422 reaches the console. That is finding F-RH-3, measured here —
  // not a footnote, and deliberately not tolerated away.
  const ok = wrongScope.length === 0 && counts.fivexx === 0 && counts.consoleErrors === 0 && replay422.length === 0
  record({ id: 'RH-T6-b', lane: 'T6', scenario: 'company switch mid-edit — no pricing read under the old scope (F-RH-3 replay measured)', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  await page.getByRole('button', { name: 'Select company' }).click()
  await page.getByRole('button', { name: need('companyName'), exact: true }).click()

  // Asserted: the T6 lane's own contract. NOT asserted: zero console errors — the
  // measured 422 is finding F-RH-3 and is reported as the leg's FAIL verdict above
  // rather than being made green by a tolerance (gate r1 BLK-1).
  expect(counts.fivexx, 'no 5xx during a company switch').toBe(0)
  expect(wrongScope, 'no pricing request may be issued under the previous company after a switch').toEqual([])
})

/* ================================================================== */
/* T4 — audit bounds + documents limit clamp (lane rh-t4)              */
/* ================================================================== */

test('RH-T4-a the audit endpoint is bounded (default 50, per_page ≤ 100, page 2, 92-day span)', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  await page.goto('/dashboard')
  const guards = captureGuards(page)
  const companyId = need('companyId')
  const lines: string[] = []

  async function probe(query: string): Promise<{ status: number; meta: Record<string, unknown>; message: string }> {
    const result = await apiJson(page, 'GET', `/audit/events${query}`, undefined, companyId)
    const body = result.body === null ? {} : asRec(result.body)
    const meta = body['meta'] === undefined ? {} : asRec(body['meta'])
    const message = JSON.stringify(body).slice(0, 220)
    lines.push(`GET /audit/events${query} → HTTP ${String(result.status)}${Object.keys(meta).length > 0 ? ` meta.per_page=${String(meta['per_page'])} meta.current_page=${String(meta['current_page'])} meta.total=${String(meta['total'])}` : ` body=${message}`}`)
    return { status: result.status, meta, message }
  }

  const defaults = await probe('')
  const atCeiling = await probe('?per_page=100')
  const overCeiling = await probe('?per_page=101')
  const pageTwo = await probe('?per_page=1&page=2')
  const span92 = await probe('?from=2026-01-01&to=2026-04-03')
  const span93 = await probe('?from=2026-01-01&to=2026-04-04')
  const halfPair = await probe('?from=2026-01-01')

  const counts = guards.stop()
  const evidence = `${lines.join(' ; ')}; the 93-day refusal message = ${span93.message}; ${stamp()}`
  const ok = defaults.status === 200 && defaults.meta['per_page'] === 50
    && atCeiling.status === 200 && atCeiling.meta['per_page'] === 100
    && overCeiling.status === 422 && pageTwo.status === 200 && pageTwo.meta['current_page'] === 2
    && span92.status === 200 && span93.status === 422 && halfPair.status === 422
  record({
    id: 'RH-T4-a', lane: 'T4',
    scenario: 'audit-event bounds (API-contract: the endpoint has NO web consumer — see §Not covered)',
    result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors,
  })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(defaults.meta['per_page'], 'default page size is 50').toBe(50)
  expect(overCeiling.status, 'per_page above 100 is refused').toBe(422)
  expect(span93.status, 'a 93-day span is refused').toBe(422)
  expect(span92.status, 'a 92-day span is accepted').toBe(200)
  expect(halfPair.status, 'a half from/to pair is refused').toBe(422)
})

test('RH-T4-b the documents list clamps limit into 1..100', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  await page.goto('/dashboard')
  const guards = captureGuards(page)
  const companyId = need('companyId')
  const lines: string[] = []

  async function probe(query: string): Promise<{ status: number; perPage: unknown }> {
    const result = await apiJson(page, 'GET', `/documents${query}`, undefined, companyId)
    const body = result.body === null ? {} : asRec(result.body)
    const meta = body['meta'] === undefined ? {} : asRec(body['meta'])
    lines.push(`GET /documents${query} → HTTP ${String(result.status)} meta.per_page=${String(meta['per_page'])} meta.total=${String(meta['total'])}`)
    return { status: result.status, perPage: meta['per_page'] }
  }

  const five = await probe('?limit=5')
  const huge = await probe('?limit=5000')
  const zero = await probe('?limit=0')
  const hundred = await probe('?limit=100')

  const counts = guards.stop()
  const evidence = `${lines.join(' ; ')}; the campaign's five call sites all send limit=100, exactly at the ceiling — anything past 100 documents is silently truncated (plan Task 4 as-shipped note 4); ${stamp()}`
  const ok = five.perPage === 5 && huge.perPage === 100 && zero.perPage === 1 && hundred.perPage === 100
  record({ id: 'RH-T4-b', lane: 'T4', scenario: 'documents `limit` clamp 1..100', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(five.perPage, 'limit=5 is honoured verbatim').toBe(5)
  expect(huge.perPage, 'limit=5000 clamps to 100').toBe(100)
  expect(zero.perPage, 'limit=0 clamps to 1').toBe(1)
})

/* ================================================================== */
/* Placeholder-data follow-up (merge 190f38109)                        */
/* ================================================================== */

interface SwitchSample { t: number; switcher: string; body: string }

/**
 * Click a company in the switcher while sampling, every animation frame, what the
 * switcher button says and what the list body contains. A `keepPreviousData` leak
 * shows up as a sample where the switcher already names company B and the table
 * still holds a company-A row.
 */
/**
 * A company-1 marker taken from what page 1 ACTUALLY renders: the most
 * distinctive CELL of the first row. A whole-row `innerText` cannot be used —
 * a `<tr>`'s innerText is tab-separated while the parent `<tbody>`'s is not, so
 * the substring check would never match. A hardcoded reference cannot be used
 * either: the reused tenant accumulates rows across runs and pushes it off page 1.
 */
async function firstRowMarker(page: Page): Promise<string> {
  const cells = await page.locator('tbody tr').first().locator('td').allInnerTexts()
  const trimmed = cells.map((cell) => cell.trim()).filter((cell) => cell !== '')
  const distinctive = trimmed.filter((cell) => /RH-/.test(cell)).sort((a, b) => b.length - a.length)[0]
  const fallback = [...trimmed].sort((a, b) => b.length - a.length)[0]
  return distinctive ?? fallback ?? ''
}

async function sampleCompanySwitch(page: Page, companyName: string, listSelector: string): Promise<SwitchSample[]> {
  await page.getByRole('button', { name: 'Select company' }).click()
  const [samples] = await Promise.all([
    page.evaluate(async (selector: string) => {
      const out: { t: number; switcher: string; body: string }[] = []
      const start = Date.now()
      while (Date.now() - start < 5000) {
        const switcher = document.querySelector('button[aria-label="Select company"]')
        const list = document.querySelector(selector)
        out.push({
          t: Date.now() - start,
          switcher: switcher instanceof HTMLElement ? switcher.innerText : '',
          body: list instanceof HTMLElement ? list.innerText : '',
        })
        await new Promise((resolve) => requestAnimationFrame(() => { resolve(null) }))
      }
      return out
    }, listSelector),
    page.getByRole('button', { name: companyName, exact: true }).click(),
  ])
  return samples
}

test('RH-PLACEHOLDER-a a company switch never shows the previous company rows on the payments list', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)

  await page.goto('/treasury/payments')
  await expect(page.getByText(/showing \d+ to \d+ of \d+ results/i)).toBeVisible({ timeout: 60_000 })
  // The marker is taken from what page 1 ACTUALLY renders (the tenant accumulates
  // payments across re-runs, so a hardcoded reference falls off page 1).
  const marker = await firstRowMarker(page)
  expect(marker.length, 'page 1 must render at least one payment row').toBeGreaterThan(0)
  await expect(page.locator('tbody')).toContainText(marker)
  const headerBefore = await page.getByText(/\d+ payments? total/i).first().innerText({ timeout: 5000 }).catch(() => '(header not matched)')

  const samples = await sampleCompanySwitch(page, need('company2Name'), 'tbody')
  const leaks = samples.filter((sample) => sample.switcher.includes(need('company2Name')) && sample.body.includes(marker))
  const firstOnC2 = samples.find((sample) => sample.switcher.includes(need('company2Name')))
  await page.waitForTimeout(1500)
  const headerAfter = await page.getByText(/\d+ payments? total/i).first().innerText({ timeout: 5000 }).catch(() => '(header absent)')
  const rowsAfter = await page.locator('tbody tr').count()
  const bodyAfter = await page.locator('tbody').first().innerText({ timeout: 5000 }).catch(() => '')

  const screenshot = await shot(page, 'RH-PLACEHOLDER-a')
  const counts = guards.stop()

  const evidence = `payments list, ${String(samples.length)} frames sampled across the switch; company-1 marker (most distinctive cell of page-1 row 1, taken from the DOM) "${marker}" still rendered while the switcher already said "${need('company2Name')}" in ${String(leaks.length)} frame(s) (first company-2 frame at t=${String(firstOnC2?.t ?? -1)}ms); header before="${headerBefore}" after="${headerAfter}"; rows after=${String(rowsAfter)}; marker present after=${String(bodyAfter.includes(marker))}; screenshot ${screenshot}; ${stamp()}`
  const ok = leaks.length === 0 && !bodyAfter.includes(marker) && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-PLACEHOLDER-a', lane: 'placeholder-data', scenario: 'PaymentListPage company switch — no keepPreviousData leak', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  await page.getByRole('button', { name: 'Select company' }).click()
  await page.getByRole('button', { name: need('companyName'), exact: true }).click()

  expect(counts.detail).toBe('[]')
  expect(leaks, 'no frame may show company-A rows under company B').toEqual([])
})

test('RH-PLACEHOLDER-b a company switch never shows the previous company rows on the stock-movements list', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  const guards = captureGuards(page)

  await page.goto('/inventory/movements')
  await expect(page.getByText(/showing \d+ to \d+ of \d+ results/i)).toBeVisible({ timeout: 60_000 })
  const marker = await firstRowMarker(page)
  expect(marker.length, 'page 1 must render at least one movement row').toBeGreaterThan(0)
  await expect(page.locator('tbody')).toContainText(marker)
  const headerBefore = await page.getByText(/\d+ movements? recorded/i).first().innerText({ timeout: 5000 }).catch(() => '(header not matched)')

  const samples = await sampleCompanySwitch(page, need('company2Name'), 'tbody')
  const leaks = samples.filter((sample) => sample.switcher.includes(need('company2Name')) && sample.body.includes(marker))
  await page.waitForTimeout(1500)
  const headerAfter = await page.getByText(/\d+ movements? recorded/i).first().innerText({ timeout: 5000 }).catch(() => '(header absent)')
  const bodyAfter = await page.locator('tbody').first().innerText({ timeout: 5000 }).catch(() => '')

  const screenshot = await shot(page, 'RH-PLACEHOLDER-b')
  const counts = guards.stop()

  const evidence = `stock-movements list, ${String(samples.length)} frames sampled; company-1 marker (most distinctive cell of page-1 row 1, taken from the DOM) "${marker}" rendered under the company-2 switcher label in ${String(leaks.length)} frame(s); header before="${headerBefore}" after="${headerAfter}"; marker present after=${String(bodyAfter.includes(marker))}; screenshot ${screenshot}; ${stamp()}`
  const ok = leaks.length === 0 && !bodyAfter.includes(marker) && counts.fivexx === 0 && counts.consoleErrors === 0
  record({ id: 'RH-PLACEHOLDER-b', lane: 'placeholder-data', scenario: 'StockMovementsPage company switch — no keepPreviousData leak', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  await page.getByRole('button', { name: 'Select company' }).click()
  await page.getByRole('button', { name: need('companyName'), exact: true }).click()

  expect(counts.detail).toBe('[]')
  expect(leaks, 'no frame may show company-A rows under company B').toEqual([])
})

/* ================================================================== */
/* T8 — reconnect cooldown (lane rh-t8)                                */
/* ================================================================== */

/**
 * The web shell opens its Echo socket at `ws://<window.location.host>/app/<key>`
 * (`lib/echo.ts` derives host and port from `window.location`; there is NO
 * VITE_WS_PORT/VITE_WS_HOST). The orchestrator's vite config proxies only `/api`
 * and `/sanctum`, so a Reverb process on another port is unreachable from the
 * browser and cannot be used without editing the running stack.
 *
 * The subject of Task 8 is the FRONTEND cooldown provider, so the Pusher server is
 * mocked at the transport level with `page.routeWebSocket`: the handshake frame is
 * real protocol, and closing the socket produces a genuine pusher-js
 * disconnect → reconnect edge whose timing this leg controls exactly.
 */
test('RH-T8-a the initial connect sweeps nothing; a reconnect sweeps once; a second reconnect inside the cooldown sweeps nothing', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)

  const openSockets: { close: () => void }[] = []
  let handshakes = 0

  await page.route('**/broadcasting/auth', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ auth: 'local_key:mocked' }) })
  })
  await page.routeWebSocket(/\/app\//, (ws) => {
    handshakes += 1
    openSockets.push({ close: () => { ws.close() } })
    ws.onMessage((message) => {
      const text = typeof message === 'string' ? message : message.toString()
      let event = ''
      try { event = String((JSON.parse(text) as { event?: string }).event ?? '') } catch { event = '' }
      if (event === 'pusher:subscribe') {
        let channel = ''
        try { channel = String(((JSON.parse(text) as { data?: { channel?: string } }).data ?? {}).channel ?? '') } catch { channel = '' }
        ws.send(JSON.stringify({ event: 'pusher_internal:subscription_succeeded', channel, data: '{}' }))
      }
      if (event === 'pusher:ping') ws.send(JSON.stringify({ event: 'pusher:pong', data: '{}' }))
    })
    ws.send(JSON.stringify({
      event: 'pusher:connection_established',
      data: JSON.stringify({ socket_id: `1.${String(handshakes)}`, activity_timeout: 120 }),
    }))
  })

  const guards = captureGuards(page)
  const net = recordNetwork(page)

  await page.goto('/treasury/payments')
  await expect(page.getByText(/showing \d+ to \d+ of \d+ results/i)).toBeVisible({ timeout: 60_000 })
  // Record the pusher connection state machine so the leg can say WHY a sweep did
  // or did not happen (the provider keys off useWebSocketConnection's isConnected,
  // which follows the 'connected' / 'disconnected' / 'error' events only).
  await page.evaluate(() => {
    const w = window as unknown as { Echo?: { connector: { pusher: { connection: { bind: (e: string, cb: (p: unknown) => void) => void } } } }; __rhStates?: string[] }
    w.__rhStates = []
    const connection = w.Echo?.connector.pusher.connection
    connection?.bind('state_change', (payload: unknown) => {
      const change = payload as { previous?: string; current?: string }
      w.__rhStates?.push(`${String(change.previous)}→${String(change.current)}@${String(Date.now())}`)
    })
  })
  // Past the 5 s INITIAL_CONNECT_GRACE_MS: the startup handshake must consume the
  // edge without sweeping and without arming the cooldown.
  await page.waitForTimeout(8000)
  const afterInitialConnect = net.matching('/api/v1/payments', 'GET').length

  // Drop 1 — the socket is closed by the server (what a Reverb restart or a
  // network blip looks like).
  const socketsBefore = handshakes
  openSockets[openSockets.length - 1]?.close()
  await expect.poll(() => handshakes, { timeout: 60_000 }).toBeGreaterThan(socketsBefore)
  await page.waitForTimeout(5000)
  const afterFirstReconnect = net.matching('/api/v1/payments', 'GET').length

  // Drop 2 — same shape, to show the first was not a one-off.
  const socketsBefore2 = handshakes
  openSockets[openSockets.length - 1]?.close()
  await expect.poll(() => handshakes, { timeout: 60_000 }).toBeGreaterThan(socketsBefore2)
  await page.waitForTimeout(6000)
  const afterSecondReconnect = net.matching('/api/v1/payments', 'GET').length

  // Drop 3 — an EXPLICIT `Echo.disconnect()` / `connect()`: the one shape that
  // does emit pusher's `disconnected` event, i.e. the edge the provider listens
  // for. This is the leg that measures the cooldown contract itself.
  await page.evaluate(() => {
    const w = window as unknown as { Echo?: { disconnect: () => void; connect?: () => void; connector: { pusher: { connect: () => void; disconnect: () => void } } } }
    w.Echo?.connector.pusher.disconnect()
  })
  await page.waitForTimeout(1500)
  await page.evaluate(() => {
    const w = window as unknown as { Echo?: { connector: { pusher: { connect: () => void } } } }
    w.Echo?.connector.pusher.connect()
  })
  await page.waitForTimeout(6000)
  const afterExplicitReconnect = net.matching('/api/v1/payments', 'GET').length

  // Drop 4 — the same explicit cycle again, well inside the 30 s cooldown.
  await page.evaluate(() => {
    const w = window as unknown as { Echo?: { connector: { pusher: { disconnect: () => void } } } }
    w.Echo?.connector.pusher.disconnect()
  })
  await page.waitForTimeout(1500)
  await page.evaluate(() => {
    const w = window as unknown as { Echo?: { connector: { pusher: { connect: () => void } } } }
    w.Echo?.connector.pusher.connect()
  })
  await page.waitForTimeout(6000)
  const afterCooldownReconnect = net.matching('/api/v1/payments', 'GET').length

  const states = await page.evaluate(() => (window as unknown as { __rhStates?: string[] }).__rhStates ?? [])
  const screenshot = await shot(page, 'RH-T8-a')
  const counts = guards.stop()
  net.stop()
  await page.unrouteAll()

  const initialSweep = afterInitialConnect - 1               // the page's own first read
  const unexpectedDrop1 = afterFirstReconnect - afterInitialConnect
  const unexpectedDrop2 = afterSecondReconnect - afterFirstReconnect
  const explicitSweep = afterExplicitReconnect - afterSecondReconnect
  const cooldownSweep = afterCooldownReconnect - afterExplicitReconnect
  const evidence = `WebSocket server mocked at the transport level (page.routeWebSocket; the local vite server proxies only /api and /sanctum, so Reverb is unreachable at ws://localhost:5174/app/*). handshakes=${String(handshakes)}. Refetch deltas on GET /api/v1/payments — startup handshake + 8 s idle: ${String(initialSweep)} (beyond the page's own first read); server-closed socket #1: ${String(unexpectedDrop1)}; server-closed socket #2: ${String(unexpectedDrop2)}; explicit pusher.disconnect()+connect(): ${String(explicitSweep)}; second explicit cycle inside the 30 s cooldown: ${String(cooldownSweep)}. RECONNECT_INVALIDATION_COOLDOWN_MS=30000, INITIAL_CONNECT_GRACE_MS=5000. pusher connection state_change trail = [${states.join(' , ')}]; screenshot ${screenshot}; ${stamp()}`
  const ok = initialSweep === 0 && explicitSweep === 1 && cooldownSweep === 0 && counts.fivexx === 0
  record({ id: 'RH-T8-a', lane: 'T8', scenario: 'initial connect = no burst; a reconnect = one sweep; a second reconnect inside the cooldown = none', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(initialSweep, 'the startup handshake must not sweep').toBe(0)
  expect(explicitSweep, 'a reconnect that delivers the disconnect edge sweeps exactly once').toBe(1)
  expect(cooldownSweep, 'a second reconnect inside the cooldown sweeps nothing').toBe(0)
})

/* ------------------------------------------------------------------ */
/* T12-f — the intent-scoped "possibly recorded" banner                */
/* ------------------------------------------------------------------ */

/**
 * The banner needs three things at once: `mutation.isError`, a confirmed line, and
 * `balanceDue === 0` — i.e. the lost response HAD committed. Reproduced honestly:
 * `page.route` forwards the POST to the real server (`route.fetch()`), then hands
 * the browser a 500, so the payment really is booked while the operator sees a
 * failure. The document is then refetched through the product's own gap-recovery
 * path (the Task 8 reconnect sweep), because nothing else on the local stack
 * refetches a mounted document (`refetchOnWindowFocus:false`, `staleTime` 5 min).
 */
test('RH-T12-f after a lost-but-committed payment the "possibly recorded" banner shows, and rotating the intent clears it', async ({ page }) => {
  test.setTimeout(300_000)
  await restoreSession(page)
  // Installed before any `page.route` so `forceFail` can register the exact Request
  // whose 5xx is tolerated (gate r1 BLK-1/BLK-2/MAJ-5).
  const guards = captureGuards(page)
  guards.tolerateConsole('Payment recording failed', "RecordPaymentModal's own console.error for the failure this leg forced")

  // A dedicated CUSTOMER INVOICE: the backend refuses payments against a purchase
  // order (422 DOCUMENT_NOT_ALLOCATABLE — see finding F-RH-6), so the PO host can
  // never produce the committed-but-lost response this leg needs. A service line
  // keeps the invoice free of stock/delivery preconditions.
  await page.goto('/dashboard')
  const service = await apiJson(page, 'POST', '/products', {
    name: `RH-SERVICE ${String(Date.now())}`, sku: `RH-SVC-${String(Date.now())}`, type: 'service',
    is_physical: false, unit_id: need('pieceUnitId'), purchase_price: '100.000', sale_price: '100.000',
    default_tax_configuration_id: need('tax19'), tax_rate: '19.00', requires_batch_tracking: false, is_active: true,
  }, need('companyId'))
  expect([200, 201], JSON.stringify(service.body)).toContain(service.status)
  const serviceId = str(asRec(dataOf(service.body, 'service')), 'id')

  const created = await apiJson(page, 'POST', '/invoices', {
    partner_id: need('customerId'), location_id: need('mainLocationId'),
    document_date: TODAY, currency: 'TND', notes: `RH T12-f invoice ${String(Date.now())}`,
    lines: [{ product_id: serviceId, description: 'RH service', quantity: '1', unit_price: '100.000', tax_configuration_id: need('tax19') }],
  }, need('companyId'))
  expect(created.status, `create invoice: ${JSON.stringify(created.body)}`).toBe(201)
  const invoiceId = str(asRec(dataOf(created.body, 'T12-f invoice')), 'id')
  const confirmed = await apiJson(page, 'POST', `/invoices/${invoiceId}/confirm`, {}, need('companyId'))
  expect(confirmed.status, `confirm invoice: ${JSON.stringify(confirmed.body)}`).toBe(200)
  const posted = await apiJson(page, 'POST', `/invoices/${invoiceId}/post`, {}, need('companyId'))
  expect(posted.status, `post invoice: ${JSON.stringify(posted.body)}`).toBe(200)
  const invoiceRow = asRec(dataOf(posted.body, 'T12-f invoice posted'))
  const outstanding = String(invoiceRow['outstanding_amount'] ?? invoiceRow['balance_due'] ?? invoiceRow['amount_residual'] ?? invoiceRow['total'] ?? '')
  expect(outstanding, 'the invoice must carry an outstanding amount').not.toBe('')

  // Mock the Pusher server so the leg can drive one gap-recovery sweep on demand.
  await page.route('**/broadcasting/auth', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ auth: 'local_key:mocked' }) })
  })
  await page.routeWebSocket(/\/app\//, (ws) => {
    ws.onMessage((message) => {
      const text = typeof message === 'string' ? message : message.toString()
      let event = ''
      let channel = ''
      try {
        const parsed = JSON.parse(text) as { event?: string; data?: { channel?: string } }
        event = String(parsed.event ?? '')
        channel = String(parsed.data?.channel ?? '')
      } catch { /* ignore */ }
      if (event === 'pusher:subscribe') ws.send(JSON.stringify({ event: 'pusher_internal:subscription_succeeded', channel, data: '{}' }))
      if (event === 'pusher:ping') ws.send(JSON.stringify({ event: 'pusher:pong', data: '{}' }))
    })
    ws.send(JSON.stringify({ event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '9.1', activity_timeout: 120 }) }))
  })

  // The server commits; the browser is told it failed.
  let upstream = 'not-called'
  await page.route('**/api/v1/payments', async (route) => {
    if (route.request().method() !== 'POST') { await route.fallback(); return }
    const real = await route.fetch()
    upstream = `${String(real.status())} ${(await real.text()).slice(0, 300)}`
    await guards.forceFail(route, 500, { message: 'lost response (RH-T12-f)' })
  })

  const net = recordNetwork(page)

  await page.goto(`/sales/invoices/${invoiceId}`)
  await expect(page.getByRole('button', { name: /record payment/i }).first()).toBeVisible({ timeout: 60_000 })
  await armRecordPaymentModal(page, outstanding)
  await page.getByRole('button', { name: /^record payment \(\d+\)$|^enregistrer le paiement \(\d+\)$/i }).last().click()
  await expect.poll(() => net.matching('/api/v1/payments', 'POST')[0]?.status ?? null, { timeout: 60_000 }).toBe(500)
  await expect.poll(() => upstream, { timeout: 30_000 }).not.toBe('not-called')

  const firstPost = net.matching('/api/v1/payments', 'POST')[0]
  const usedKey = firstPost === undefined ? '' : bodyField(firstPost, 'idempotency_key') ?? ''
  // The multi-payment endpoint suffixes the client key per line (`<uuid>:multi:0000`).
  const committed = Number(sql(tenantDb(), `SELECT COUNT(*) FROM payments WHERE company_id=${quoted(need('companyId'))} AND idempotency_key LIKE ${quoted(usedKey + '%')}`)[0] ?? '0')

  const banner = page.getByText(/this payment may already have been recorded/i)
  const bannerBeforeSweep = await banner.isVisible().catch(() => false)
  expect(upstream, 'the forwarded POST must have really committed').toMatch(/^20\d/)

  // One gap-recovery sweep → the PO refetches → outstanding is now zero.
  await page.evaluate(() => {
    const w = window as unknown as { Echo?: { connector: { pusher: { disconnect: () => void } } } }
    w.Echo?.connector.pusher.disconnect()
  })
  await page.waitForTimeout(1500)
  await page.evaluate(() => {
    const w = window as unknown as { Echo?: { connector: { pusher: { connect: () => void } } } }
    w.Echo?.connector.pusher.connect()
  })
  await expect(banner).toBeVisible({ timeout: 30_000 })
  const bannerShot = await shot(page, 'RH-T12-f-banner')

  // Rotating the intent (any operator payload edit) must clear it.
  await page.locator('#payment-date').fill('2026-09-04')
  await expect(banner).toBeHidden({ timeout: 15_000 })
  const clearedShot = await shot(page, 'RH-T12-f-cleared')

  // MIN-7: report the INVOICE's post-sweep outstanding, not an unrelated supplier count.
  const invoiceOutstanding = sql(tenantDb(), `SELECT balance_due::text FROM documents WHERE id=${quoted(invoiceId)}`)[0] ?? 'n/a'
  const counts = guards.stop()
  net.stop()
  await page.unrouteAll()

  const evidence = `posted customer invoice ${invoiceId} outstanding=${outstanding}; upstream (forwarded) response = ${upstream}; the POST was forwarded to the real server (route.fetch) and the browser was handed a 500 — psql payments rows for that idempotency_key = ${String(committed)}; banner visible before the gap-recovery sweep = ${String(bannerBeforeSweep)}; after one explicit reconnect sweep the banner "This payment may already have been recorded — the outstanding is now zero…" is VISIBLE (${bannerShot}); after editing #payment-date (intent rotation → mutation.reset) the banner is GONE (${clearedShot}); psql documents.balance_due for the invoice after the sweep = ${invoiceOutstanding}; ${stamp()}`
  const ok = committed === 1 && counts.fivexx === 0
  record({ id: 'RH-T12-f', lane: 'T12/T12c', scenario: 'intent-scoped "possibly recorded" banner appears on a lost-but-committed payment and clears on rotation', result: ok ? 'PASS' : 'FAIL', evidence, fivexx: counts.fivexx, consoleErrors: counts.consoleErrors })

  expect(counts.detail, 'forbidden 5xx/console errors (measured, tolerances named in support.ts)').toBe('[]')
  expect(committed, 'the lost response had really committed one payment').toBe(1)
})
