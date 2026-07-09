import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { randomUUID } from 'node:crypto'
import { test, expect, type APIRequestContext } from '@playwright/test'

/**
 * Treasury Money-Movement Spine — LIVE end-to-end verification (spine Task 29).
 *
 * This is a SMOKE test (repo convention: live/no-mock tests live under
 * e2e/smoke/*.smoke.ts + playwright.smoke.config.ts, never under the default
 * mocked e2e suite). It runs against the RUNNING db-per-tenant stack (API
 * :8010, Vite :5173, tenant demo-pharmacy-tn) — NOT the staging URL the smoke
 * config defaults to — so both endpoints are parameterized via env vars
 * (TREASURY_SPINE_API_BASE / TREASURY_SPINE_BASE_URL) with the current local
 * values as defaults; override them to point at a different stack. It is NOT
 * a mocked test — it exercises the real append-only `repository_movements`
 * ledger, the single write port, the GL links and the server-side
 * cash-position, and it proves the read surface (Movements tab + Total Cash)
 * reflects the chain.
 *
 * Chain proven, all on the Main Cash Register:
 *   money IN  (customer payment  +50.000)
 *   money OUT (full refund       -50.000)
 *   adjustment (count_variance   +10.000)
 *   => net +10.000 vs the baseline captured at run start.
 *
 * `treasury:reconcile` (checked 7, froze 0) is run OUT OF BAND via artisan — see
 * task-29-report.md — because it is a console command, not an HTTP surface.
 *
 * DEVIATIONS (controller-approved, see task-29-brief.md):
 *  - The POS sale is Tauri-only, so "money in" is a customer PAYMENT (the same
 *    converged PaymentController write port).
 *  - The payment is created via the request API rather than the PaymentForm UI:
 *    the form drives partner/method through typeahead comboboxes that are
 *    brittle to drive deterministically. The owner-mandated VISUAL check (Total
 *    Cash + Movements tab) is still driven through the real browser UI.
 *  - Refund and adjustment have no (or an incomplete) FE and are exercised via
 *    the request API, exactly as the brief instructs.
 *
 * Because the ledger is append-only there is no teardown: every run permanently
 * shifts the balance by +10.000. All assertions are therefore RELATIVE to the
 * baseline captured at step 2, so the spec stays green across repeated runs.
 */

const FRONTEND_BASE_URL = process.env['TREASURY_SPINE_BASE_URL'] || 'http://localhost:5173'
const API_BASE = process.env['TREASURY_SPINE_API_BASE'] || 'http://127.0.0.1:8010/api/v1'
const CREDENTIALS = { email: 'owner@pharmabio.tn', password: 'password' }
const CURRENCY_CODE = 'TND'

// This smoke test targets the locally running demo stack, not the smoke
// config's default STAGING_URL — override baseURL for this file only.
test.use({ baseURL: FRONTEND_BASE_URL })

const SCREENSHOT_DIR = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
  '..',
  'docs',
  'sessions',
  'treasury-spine-e2e',
)

// --- decimal helpers: integer millimes arithmetic, never a float on money ---

function toMillimes(value: string): bigint {
  const [intPart, fracPart = ''] = value.split('.')
  const negative = intPart.trimStart().startsWith('-')
  const intAbs = intPart.replace('-', '')
  const frac = (fracPart + '000').slice(0, 3)
  const magnitude = BigInt(intAbs) * 1000n + BigInt(frac)
  return negative ? -magnitude : magnitude
}

function fromMillimes(value: bigint): string {
  const negative = value < 0n
  const abs = negative ? -value : value
  const intPart = abs / 1000n
  const frac = (abs % 1000n).toString().padStart(3, '0')
  return `${negative ? '-' : ''}${intPart.toString()}.${frac}`
}

function addDecimal(a: string, b: string): string {
  return fromMillimes(toMillimes(a) + toMillimes(b))
}

/**
 * Tolerant matcher for a money value as rendered by Intl (fr locale, TND, 3dp):
 * the integer part may carry group separators (space / NBSP / NNBSP / comma) and
 * the decimal separator may be '.' or ','. We match the digit stream, allowing an
 * optional single separator char between any two digits.
 */
function tolerantMoneyRegExp(value: string): RegExp {
  const digits = value.replace('-', '').replace('.', '')
  const pattern = digits.split('').join('[\\s\\u00a0\\u202f.,]?')
  return new RegExp(pattern)
}

/**
 * Exact-cell matcher for a money value as the movements table actually renders
 * it: `formatCurrency` appends the currency code, and signed cells (the amount
 * column) prefix a bare '-' for direction=out (see RepositoryMovementsTab.tsx).
 * Anchored start-to-end so it only matches the whole cell content, not an
 * ambiguous substring shared with another row/column.
 */
function exactRenderedMoneyText(value: string, options: { negative?: boolean } = {}): RegExp {
  const sign = options.negative ? '-' : ''
  const digits = tolerantMoneyRegExp(value).source
  return new RegExp(`^${sign}${digits}[\\s\\u00a0\\u202f]*${CURRENCY_CODE}$`)
}

interface CashPositionRepository {
  id: string
  code: string
  name: string
  balance: string
}

interface CashPositionGroup {
  type: string
  total: string
  repositories: CashPositionRepository[]
}

interface CashPosition {
  currency: string
  grand_total: string
  groups: CashPositionGroup[]
}

// --- shared serial state ------------------------------------------------------

let token = ''
let user: { id: string; name: string; email: string; tenant_id: string; roles: string[] } | null =
  null

let mainRepoId = ''
let baselineMainBalance = ''
let baselineGrandTotal = ''
let paymentId = ''

const PAYMENT_AMOUNT = '50.000'
const ADJUSTMENT_AMOUNT = '10.000'
const NET_DELTA = '10.000' // +50 -50 +10

function authHeaders(): Record<string, string> {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json' }
}

async function fetchCashPosition(request: APIRequestContext): Promise<CashPosition> {
  const res = await request.get(`${API_BASE}/treasury/cash-position`, { headers: authHeaders() })
  expect(res.ok(), `cash-position -> ${res.status()}`).toBeTruthy()
  return (await res.json()).data as CashPosition
}

async function fetchRepoBalance(request: APIRequestContext, repoId: string): Promise<string> {
  const res = await request.get(`${API_BASE}/payment-repositories/${repoId}`, {
    headers: authHeaders(),
  })
  expect(res.ok(), `repo show -> ${res.status()}`).toBeTruthy()
  return String((await res.json()).data.balance)
}

test.describe.serial('Treasury spine — live E2E', () => {
  test('1. login as owner and capture the bearer token', async ({ request }) => {
    const res = await request.post(`${API_BASE}/auth/login`, {
      headers: { Accept: 'application/json' },
      data: CREDENTIALS,
    })
    expect(res.ok(), `login -> ${res.status()}`).toBeTruthy()
    const body = (await res.json()).data
    expect(body.token, 'login returned a token').toBeTruthy()

    token = body.token as string
    user = {
      id: body.user.id,
      name: body.user.name,
      email: body.user.email,
      tenant_id: body.user.tenantId,
      roles: body.user.roles,
    }
    // The refund path is gated by can:payments.refund — assert the role can
    // actually reach it (a missing permission silently 403s the money-out step).
    expect(
      (body.user.permissions as string[]).includes('payments.refund'),
      'owner role holds payments.refund',
    ).toBeTruthy()
  })

  test('2. record the baseline cash position', async ({ request }) => {
    const position = await fetchCashPosition(request)
    baselineGrandTotal = position.grand_total

    const cashGroup = position.groups.find((g) => g.type === 'cash_register')
    expect(cashGroup, 'cash_register group present').toBeTruthy()
    const main = cashGroup!.repositories.find((r) => r.code === 'CASH-01')
    expect(main, 'Main Cash Register (CASH-01) present').toBeTruthy()

    mainRepoId = main!.id
    baselineMainBalance = main!.balance

    // eslint-disable-next-line no-console
    console.log(
      `[baseline] grand_total=${baselineGrandTotal} mainCashRegister=${baselineMainBalance} (id=${mainRepoId})`,
    )
  })

  test('3. money IN — customer payment of 50.000 into Main Cash Register', async ({ request }) => {
    // Discover a customer partner + the CASH payment method dynamically so the
    // spec does not hard-code seed ids.
    const partnersRes = await request.get(`${API_BASE}/partners?per_page=25`, {
      headers: authHeaders(),
    })
    expect(partnersRes.ok()).toBeTruthy()
    const partners = ((await partnersRes.json()).data as Array<{ id: string; type?: string }>) ?? []
    const customer = partners.find((p) => (p.type ?? 'customer') === 'customer') ?? partners[0]
    expect(customer, 'a customer partner exists').toBeTruthy()

    const methodsRes = await request.get(`${API_BASE}/payment-methods`, { headers: authHeaders() })
    expect(methodsRes.ok()).toBeTruthy()
    const methods = (await methodsRes.json()).data as Array<{ id: string; code: string }>
    const cash = methods.find((m) => m.code === 'CASH')
    expect(cash, 'CASH payment method exists').toBeTruthy()

    const today = new Date().toISOString().slice(0, 10)
    const res = await request.post(`${API_BASE}/payments`, {
      headers: authHeaders(),
      data: {
        partner_id: customer.id,
        payment_method_id: cash!.id,
        repository_id: mainRepoId,
        amount: PAYMENT_AMOUNT,
        currency: 'TND',
        payment_date: today,
      },
    })
    expect(res.status(), 'payment created (201)').toBe(201)
    paymentId = String((await res.json()).data.id)
    expect(paymentId).toBeTruthy()

    const balance = await fetchRepoBalance(request, mainRepoId)
    expect(balance, 'balance rose by exactly the payment amount').toBe(
      addDecimal(baselineMainBalance, PAYMENT_AMOUNT),
    )
  })

  test('4. money OUT — full refund of the payment (request API, refund_request_id)', async ({
    request,
  }) => {
    const refundRequestId = randomUUID()
    const res = await request.post(`${API_BASE}/payments/${paymentId}/refund`, {
      headers: authHeaders(),
      data: { reason: 'Treasury spine E2E — full refund', refund_request_id: refundRequestId },
    })
    expect(res.status(), 'refund created (201)').toBe(201)

    const balance = await fetchRepoBalance(request, mainRepoId)
    expect(balance, 'balance returned to baseline after the full refund').toBe(baselineMainBalance)
  })

  test('5. adjustment — +10.000 count_variance (request API)', async ({ request }) => {
    const res = await request.post(`${API_BASE}/payment-repositories/${mainRepoId}/adjustments`, {
      headers: authHeaders(),
      data: {
        direction: 'in',
        amount: ADJUSTMENT_AMOUNT,
        reason_code: 'count_variance',
        reason_text: 'Treasury spine E2E — count variance',
      },
    })
    expect(res.status(), 'adjustment created (201)').toBe(201)
    const result = (await res.json()).data
    expect(result.balance_after, 'adjustment balance_after = baseline + net').toBe(
      addDecimal(baselineMainBalance, NET_DELTA),
    )

    const balance = await fetchRepoBalance(request, mainRepoId)
    expect(balance).toBe(addDecimal(baselineMainBalance, NET_DELTA))
  })

  test('6. UI — Total Cash on the finance overview reflects the +10.000 net', async ({ page }) => {
    await seedAuth(page)
    await page.goto('/finance/overview')

    // Language-agnostic: assert the expected grand-total money figure is
    // rendered somewhere on the overview (the StatCard label is translated).
    const expectedGrandTotal = addDecimal(baselineGrandTotal, NET_DELTA)
    await expect(
      page.getByText(tolerantMoneyRegExp(expectedGrandTotal)).first(),
      `Total Cash shows ${expectedGrandTotal}`,
    ).toBeVisible({ timeout: 20000 })

    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '01-finance-overview-total-cash.png'),
      fullPage: true,
    })
  })

  test('7. UI — Movements tab shows opening / payment / refund / adjustment rows', async ({
    page,
  }) => {
    await seedAuth(page)
    await page.goto(`/treasury/repositories/${mainRepoId}`)

    // Open the Movements tab (second tab; name is translated so target by index).
    await page.getByRole('tab').nth(1).click()

    const table = page.locator('table')
    await expect(table).toBeVisible({ timeout: 20000 })

    // Stable anchor: the opening_balance row's running balance (374.750) never
    // changes across runs, so it always identifies the opening movement.
    await expect(
      table.getByText(tolerantMoneyRegExp('374.750')).first(),
      'opening_balance row visible',
    ).toBeVisible()

    // This run's rows (relative to the captured baseline):
    const afterPayment = addDecimal(baselineMainBalance, PAYMENT_AMOUNT)
    const afterAdjust = addDecimal(baselineMainBalance, NET_DELTA)
    await expect(
      table.getByText(tolerantMoneyRegExp(afterPayment)).first(),
      `payment row balance_after ${afterPayment}`,
    ).toBeVisible()
    await expect(
      table.getByText(tolerantMoneyRegExp(afterAdjust)).first(),
      `adjustment row balance_after ${afterAdjust}`,
    ).toBeVisible()

    // Positive assertion on the REFUND row itself (not just "opening_balance
    // disappeared" and not the ambiguous generic '50.000' text, which matches
    // both the payment row and the refund row). The ledger is append-only, so
    // after repeated spec runs there are MULTIPLE historical "Refund" rows —
    // disambiguate THIS run's row by combining the Refund source badge with
    // its balance_after, which is unique to this run's baseline (each prior
    // run captured a different, strictly-increasing baseline).
    const refundRow = table
      .locator('tr')
      .filter({ has: page.getByText('Refund', { exact: true }) })
      .filter({ has: page.getByText(exactRenderedMoneyText(baselineMainBalance)) })
    await expect(
      refundRow,
      `exactly one Refund-source row with balance_after ${baselineMainBalance}`,
    ).toHaveCount(1)

    const refundAmountCell = refundRow.locator('td').nth(2)
    const refundBalanceCell = refundRow.locator('td').nth(3)

    await expect(
      refundAmountCell,
      `refund row renders the amount as -${PAYMENT_AMOUNT} ${CURRENCY_CODE}`,
    ).toHaveText(exactRenderedMoneyText(PAYMENT_AMOUNT, { negative: true }))

    await expect(
      refundBalanceCell,
      `refund row balance_after equals the pre-payment baseline (${baselineMainBalance})`,
    ).toHaveText(exactRenderedMoneyText(baselineMainBalance))

    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '02-movements-tab-all.png'),
      fullPage: true,
    })

    // Exercise the direction filter once — filtering to "out" must re-query the
    // server with direction=out (proves the filter is wired, not client-side).
    const [filtered] = await Promise.all([
      page.waitForResponse(
        (r) => /\/payment-repositories\/.+\/movements\?.*direction=out/.test(r.url()) && r.ok(),
        { timeout: 20000 },
      ),
      page.locator('#movements-filter-direction').selectOption('out'),
    ])
    expect(filtered.ok(), 'direction=out query returned ok').toBeTruthy()

    // After filtering to "out", the opening_balance (an "in" row) disappears.
    await expect(table.getByText(tolerantMoneyRegExp('374.750'))).toHaveCount(0)

    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '03-movements-tab-filter-out.png'),
      fullPage: true,
    })
  })

  test('8. API grand_total equals baseline + net (matches the UI figure)', async ({ request }) => {
    const position = await fetchCashPosition(request)
    expect(position.grand_total, 'grand_total = baseline + 10.000').toBe(
      addDecimal(baselineGrandTotal, NET_DELTA),
    )
  })
})

/**
 * Rehydrate the real zustand auth store from a live token so the browser session
 * is authenticated end-to-end (no request mocking): the app then hits the real
 * /auth/me, /user/companies and treasury endpoints with a valid Bearer token.
 */
async function seedAuth(page: import('@playwright/test').Page): Promise<void> {
  const authState = {
    state: { user, token, isAuthenticated: true, isLoading: false },
    version: 0,
  }
  await page.addInitScript((value) => {
    window.localStorage.setItem('autoerp-auth', value)
  }, JSON.stringify(authState))
}
