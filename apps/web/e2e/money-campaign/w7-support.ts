/**
 * MONEY TEST CAMPAIGN — wave W-7 (cross-cutting: `PERM-08/14/16..18`,
 * `MLC-01..08`, `I18N-09..11`, `EMPTY-09..12`, `CONC-01..06`).
 *
 * Support helpers ONLY for W-7's own spec files. Talks to the LIVE local stack
 * (web :5173 -> api :8010, tenant `demo-pharmacy-tn`, plus the `cafe-tunis`
 * tenant this wave provisioned for `MTP-PERM-14`). No mocks anywhere.
 *
 * Reuses `treasury-support.ts`'s `Session`/`login`/`authHeaders`/money math and
 * `helpers.ts`'s `apiRequest`/`loginAsRole` rather than redefining them (house
 * convention — see `w2c-support.ts`, `statement-support.ts`).
 *
 * EVERYTHING THIS WAVE AUTHORS CARRIES THE `W7-` PREFIX so a successor can
 * exclude it by predicate and never by subtracting a snapshot.
 */
import type { APIRequestContext, Page } from '@playwright/test'
import { expect } from '@playwright/test'
import { API_BASE, authHeaders, get, login, type Session, type TreRole } from './treasury-support'
import { apiRequest, loginAsRole, type ApiResult, type Role } from './helpers'

export const PREFIX = 'W7'

export function uniq(base: string): string {
  return `${PREFIX}-${base}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
}

export const TODAY = new Date().toISOString().slice(0, 10)

export function daysAhead(days: number): string {
  const d = new Date()
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

// ---------------------------------------------------------------------------
// Multi-location fixtures (MLC). Location IDS ARE RESOLVED LIVE BY CODE, never
// hardcoded: `w2b-support.ts` pins two of them as constants and a reseed would
// silently turn those into 404s. Codes are stable, ids are not.
// ---------------------------------------------------------------------------

export const LOCATION_CODES = {
  warehouse: 'WH-01',
  tunis1: 'STORE-TUN1',
  tunis2: 'STORE-TUN2',
  sousse: 'STORE-SOU',
  sfax: 'STORE-SFA',
} as const

export type LocationCode = (typeof LOCATION_CODES)[keyof typeof LOCATION_CODES]

export interface LocationRow {
  id: string
  code: string
  name: string
  type: string
}

export async function listLocations(request: APIRequestContext, session: Session): Promise<LocationRow[]> {
  const res = await get(request, session, '/locations')
  expect(res.status, 'GET /locations').toBe(200)
  return res.data as unknown as LocationRow[]
}

export async function locationIdByCode(
  request: APIRequestContext,
  session: Session,
  code: LocationCode,
): Promise<string> {
  const rows = await listLocations(request, session)
  const row = rows.find((l) => l.code === code)
  expect(row, `location ${code} exists in this tenant`).toBeTruthy()
  return row!.id
}

/**
 * Location-pinned cashiers seeded by `DemoPharmacySeeder` — each carries an
 * `user_company_memberships.allowed_location_ids` array of exactly ONE shop,
 * which is what makes `MTP-MLC-04`/`-05` testable without an admin grant edit.
 */
export const PINNED_CASHIERS = {
  tunis1: { email: 'tunis1.cashier@pharmabio.tn', password: 'password', locationCode: LOCATION_CODES.tunis1 },
  tunis2: { email: 'tunis2.cashier@pharmabio.tn', password: 'password', locationCode: LOCATION_CODES.tunis2 },
  sousse: { email: 'sousse.cashier@pharmabio.tn', password: 'password', locationCode: LOCATION_CODES.sousse },
} as const

export type PinnedCashier = keyof typeof PINNED_CASHIERS

/**
 * `cafe-tunis` — provisioned by THIS wave (see the W-7 report, C-8). The
 * `barista` carries `max_discount_percent = 25.00`, which is the whole point
 * of `MTP-PERM-14`.
 */
export const CAFE_CREDENTIALS = {
  owner: { email: 'owner@cafe-tunis.tn', password: 'password' },
  barista: { email: 'barista@cafe-tunis.tn', password: 'password' },
} as const

export interface RawSession {
  token: string
  userId: string
  companyId: string
  permissions: string[]
}

/**
 * Bearer-token login for a principal that is NOT in `treasury-support`'s
 * `CREDENTIALS` map (the location-pinned cashiers and the cafe-tunis users).
 * Same request the SPA's login form issues.
 */
export async function loginAs(
  request: APIRequestContext,
  credentials: { email: string; password: string },
): Promise<RawSession> {
  const res = await request.post(`${API_BASE}/auth/login`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: credentials,
  })
  expect(res.ok(), `login as ${credentials.email} -> ${res.status()} ${await res.text()}`).toBeTruthy()
  const body = (await res.json()).data as { token: string; user: { id: string; permissions: string[] } }
  const companies = await request.get(`${API_BASE}/user/companies`, {
    headers: { Authorization: `Bearer ${body.token}`, Accept: 'application/json' },
  })
  const list = (await companies.json()).data as Array<{ id: string; is_primary: boolean }>
  return {
    token: body.token,
    userId: body.user.id,
    companyId: (list.find((c) => c.is_primary) ?? list[0])!.id,
    permissions: body.user.permissions,
  }
}

/** `Session`-shaped view of a `RawSession`, so `treasury-support`'s
 * `get`/`post`/`patch` helpers work unchanged for these principals. */
export function asSession(raw: RawSession): Session {
  return { token: raw.token, userId: raw.userId, tenantId: '', companyId: raw.companyId, permissions: raw.permissions }
}

/**
 * `loginAsRole()`, retried past the login rate limiter.
 *
 * `AppServiceProvider::boot()` registers `RateLimiter::for('login')` as **5
 * attempts per minute per (email, IP)** plus 20/min per IP. A W-7 spec file
 * signs the SAME principal in once per case, and a scoped re-run inside the
 * same minute pushes straight through the per-email bucket — the failure
 * surfaces as `login as owner -> 429`, or as a form that silently stays on
 * `/login`. Laravel's `ThrottleRequests` does NOT increment the counter while
 * it is blocking, so waiting and retrying is free; it is only the successful
 * attempts that consume budget.
 */
export async function loginAsRoleResilient(page: Page, role: Role, attempts = 5): Promise<void> {
  for (let attempt = 1; ; attempt += 1) {
    try {
      await loginAsRole(page, role)
      return
    } catch (error) {
      if (attempt >= attempts) throw error
      // The bucket is per MINUTE, so a bounded wait always clears it.
      await page.waitForTimeout(13_000)
    }
  }
}

/** `treasury-support`'s bearer-token `login()`, retried past the same limiter. */
export async function loginResilient(
  request: APIRequestContext,
  role: TreRole,
  attempts = 5,
): Promise<Session> {
  for (let attempt = 1; ; attempt += 1) {
    try {
      return await login(request, role)
    } catch (error) {
      if (attempt >= attempts) throw error
      await new Promise((resolve) => setTimeout(resolve, 13_000))
    }
  }
}

/** Log a page in through the REAL login form for a non-`Role` principal —
 * retried past the login limiter, exactly like `loginAsRoleResilient`. */
export async function loginPageAs(
  page: Page,
  credentials: { email: string; password: string },
  attempts = 5,
): Promise<void> {
  await page.addInitScript(() => {
    window.localStorage.setItem('autoerp-cookie-consent', 'accepted')
  })
  for (let attempt = 1; ; attempt += 1) {
    try {
      await page.goto('/login')
      await page.getByLabel(/email address/i).fill(credentials.email)
      await page.getByLabel(/^password$/i).fill(credentials.password)
      await page.getByRole('button', { name: /sign in/i }).click()
      await expect(page).not.toHaveURL(/\/login/, { timeout: 15_000 })
      await expect(page.getByRole('button', { name: /profile/i })).toBeVisible({ timeout: 15_000 })
      return
    } catch (error) {
      if (attempt >= attempts) throw error
      await page.waitForTimeout(13_000)
    }
  }
}

// ---------------------------------------------------------------------------
// View scope (the TopBar location picker). `viewScopeStore.ts` persists the
// selection under `autoerp-view-scope:<companyId>:<userId>` and hydrates from
// it on first load, so writing that key BEFORE navigating is exactly what the
// picker does — no UI dance, and deterministic.
// ---------------------------------------------------------------------------

export type ViewScope = 'all' | string[]

export function viewScopeKey(companyId: string, userId: string): string {
  return `autoerp-view-scope:${companyId}:${userId}`
}

/**
 * Sets the persisted view scope for the CURRENTLY logged-in principal and
 * returns the key it wrote.
 *
 * M7 (review fix round 1): this helper does NOT reload — an earlier docstring
 * claimed it did. `viewScopeStore.ts` hydrates from this key on FIRST LOAD
 * (its module-scope `syncForCompany` runs when `previousCompanyId === null`),
 * so the caller must navigate (`page.goto(...)`) after calling this for the
 * new scope to take effect. Every call site here does exactly that.
 */
export async function applyViewScope(page: Page, scope: ViewScope): Promise<string> {
  const key = await page.evaluate((next) => {
    function readPersisted(k: string): unknown {
      const raw = localStorage.getItem(k)
      if (!raw) return null
      try {
        return JSON.parse(raw)?.state ?? null
      } catch {
        return null
      }
    }
    // The company id lives in the PLAIN key `autoerp-company-selection`, NOT
    // inside the zustand-persisted `autoerp-company` blob — that one persists
    // an empty `state` (verified live). Reading only the blob produced the key
    // `autoerp-view-scope:unknown:<userId>`, which the store never reads, so
    // every scoped UI assertion silently ran against the default 'all' scope.
    const selection = localStorage.getItem('autoerp-company-selection')
    const blob = readPersisted('autoerp-company') as { currentCompanyId?: string } | null
    const companyId = selection ?? blob?.currentCompanyId ?? null
    const auth = readPersisted('autoerp-auth') as { user?: { id?: string } } | null
    if (companyId === null) throw new Error('applyViewScope: no persisted company id — is the page logged in?')
    const storageKey = `autoerp-view-scope:${companyId}:${auth?.user?.id ?? 'anonymous'}`
    localStorage.setItem(storageKey, JSON.stringify(next))
    return storageKey
  }, scope)
  return key
}

/**
 * Wait past BOTH loading layers this app shows after a navigation (the shell
 * splash and any provider-specific "Loading …" string). Regex, not a literal:
 * matching only one of the strings was the documented cause of an earlier
 * flake (see `permissions.spec.ts`'s `settleAfterNav`).
 */
export async function settleAfterNav(page: Page): Promise<void> {
  // LOCALISED loading strings matter here: `permissions.spec.ts` matches only
  // /loading/i, which is correct for the default English UI but passes
  // INSTANTLY on `?lang=fr` ("Chargement des entreprises…") — the exact cause
  // of a first-run failure in this wave's i18n cases, where the assertion then
  // ran against a still-booting shell.
  //
  // NO Arabic term is matched here on purpose: the obvious stem (جار) is a
  // substring of ordinary nav labels such as "التجارة الإلكترونية"
  // (e-commerce), so it never clears. `ar` pages must use
  // `waitForMoneyRender()` below instead — and `settings.json` has no `ar`
  // translation for the company splash anyway, so it falls back to English.
  await expect(page.locator('body')).not.toContainText(/loading|chargement/i, { timeout: 30_000 })
}

/**
 * Waits until the page has actually rendered a money figure. Needed on any
 * surface whose content arrives after the shell: the loading-string heuristic
 * above only proves the shell finished, not that the document did.
 */
export async function waitForMoneyRender(page: Page, timeout = 30_000): Promise<void> {
  await expect
    .poll(
      async () => ((await page.locator('body').innerText()).match(/\d[\d.,  ]*\d\s*[A-Z]{3}/g) ?? []).length,
      { timeout, message: 'the page never rendered a currency-labelled figure' },
    )
    .toBeGreaterThan(0)
}

// ---------------------------------------------------------------------------
// Money-string plumbing
// ---------------------------------------------------------------------------

/** Every digit of a rendered money string, so a comparison is immune to the
 * locale's grouping separator (fr uses U+202F) and to symbol placement. */
export function digitsOf(rendered: string): string {
  return rendered.replace(/\D/g, '')
}

/** Exact sum of scale-3 money strings via integer millimes — never a float. */
export function sumMoney(values: string[]): string {
  let total = 0n
  for (const value of values) {
    const [intPart, fracPart = ''] = value.trim().split('.')
    const negative = intPart.startsWith('-')
    const magnitude = BigInt(intPart.replace('-', '') || '0') * 1000n + BigInt((fracPart + '000').slice(0, 3))
    total += negative ? -magnitude : magnitude
  }
  const negative = total < 0n
  const abs = negative ? -total : total
  return `${negative ? '-' : ''}${(abs / 1000n).toString()}.${(abs % 1000n).toString().padStart(3, '0')}`
}

/** Normalises an API money string of ANY scale to scale 3 for comparison —
 * `'300'` -> `'300.000'`, `'181.1'` -> `'181.100'`, `'12.0000'` -> `'12.000'`.
 * Used ONLY where a case has already RECORDED that the endpoint under test
 * emits a non-canonical scale; never to paper over one silently. */
export function toScale3(value: string): string {
  return sumMoney([value])
}

// ---------------------------------------------------------------------------
// Fixture authoring (documents + payments), page-driven so the request carries
// the SPA's own credentials (`apiRequest` from helpers.ts).
// ---------------------------------------------------------------------------

export async function createCustomer(page: Page, label: string): Promise<string> {
  const res = await apiRequest(page, 'POST', '/partners', { name: uniq(label), type: 'customer' })
  expect(res.status, `create customer -> ${res.status} ${JSON.stringify(res.body)}`).toBe(201)
  return ((res.body as { data: { id: string } }).data).id
}

export async function createSupplier(page: Page, label: string): Promise<string> {
  const res = await apiRequest(page, 'POST', '/partners', { name: uniq(label), type: 'supplier' })
  expect(res.status, `create supplier -> ${res.status} ${JSON.stringify(res.body)}`).toBe(201)
  return ((res.body as { data: { id: string } }).data).id
}

export interface InvoiceLine {
  description: string
  quantity: string
  unit_price: string
  tax_rate?: string
  product_id?: string
}

export async function createDraftInvoice(
  page: Page,
  partnerId: string,
  lines: InvoiceLine[],
): Promise<{ id: string; body: Record<string, unknown> }> {
  const res = await apiRequest(page, 'POST', '/invoices', {
    partner_id: partnerId,
    document_date: TODAY,
    lines: lines.map((l) => ({ tax_rate: '0.00', ...l })),
  })
  expect(res.status, `create invoice -> ${res.status} ${JSON.stringify(res.body)}`).toBe(201)
  const data = (res.body as { data: Record<string, unknown> }).data
  return { id: String(data.id), body: data }
}

export async function getInvoice(page: Page, invoiceId: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/invoices/${invoiceId}`)
  expect(res.status, `GET /invoices/${invoiceId}`).toBe(200)
  return (res.body as { data: Record<string, unknown> }).data
}

/** Draft -> Confirmed -> Posted, returning the posted total. Mirrors
 * `treasury-support.createPostedInvoice` but page-driven. */
export async function postInvoice(page: Page, invoiceId: string): Promise<{ total: string; balance_due: string }> {
  const confirm = await apiRequest(page, 'POST', `/invoices/${invoiceId}/confirm`)
  expect(confirm.status, `confirm -> ${confirm.status} ${JSON.stringify(confirm.body)}`).toBe(200)
  const posted = await apiRequest(page, 'POST', `/invoices/${invoiceId}/post`)
  expect(posted.status, `post -> ${posted.status} ${JSON.stringify(posted.body)}`).toBe(200)
  const data = (posted.body as { data: { total: string; balance_due: string } }).data
  return { total: data.total, balance_due: data.balance_due }
}

export async function paymentMethodIdByCode(page: Page, code: string): Promise<string> {
  const res = await apiRequest(page, 'GET', '/payment-methods')
  expect(res.status).toBe(200)
  const rows = (res.body as { data: Array<{ id: string; code: string }> }).data
  const row = rows.find((m) => m.code === code)
  expect(row, `payment method ${code} exists`).toBeTruthy()
  return row!.id
}

export async function repositoryIdByCode(page: Page, code: string): Promise<string> {
  const res = await apiRequest(page, 'GET', '/payment-repositories')
  expect(res.status).toBe(200)
  const rows = (res.body as { data: Array<{ id: string; code: string }> }).data
  const row = rows.find((r) => r.code === code)
  expect(row, `payment repository ${code} exists`).toBeTruthy()
  return row!.id
}

// ---------------------------------------------------------------------------
// Cleanup. Same contract as `statement-support.ts`: a cleanup helper NEVER
// throws (a throw inside `finally` REPLACES the in-flight failure and hides
// it), and callers gate on a real 2xx via `isCleanupSuccess`, never on
// `status < 300`.
// ---------------------------------------------------------------------------

export const CLEANUP_THREW = 599

export function isCleanupSuccess(status: number): boolean {
  return status >= 200 && status < 300
}

/** Soft-deletes a DRAFT invoice (`DELETE /invoices/{id}` refuses anything
 * posted — that refusal is the caller's evidence, not an error here). */
export async function retireDraftInvoice(page: Page, invoiceId: string): Promise<number> {
  try {
    const res = await apiRequest(page, 'DELETE', `/invoices/${invoiceId}`)
    return res.status
  } catch {
    return CLEANUP_THREW
  }
}

/** Deletes a DRAFT opening batch (used only by `MTP-CONC-05`, and only on the
 * path where the product wrongly created one). */
export async function retireOpeningBatch(page: Page, companyId: string, batchId: string): Promise<number> {
  try {
    const res = await apiRequest(page, 'DELETE', `/companies/${companyId}/opening-batches/${batchId}`)
    return res.status
  } catch {
    return CLEANUP_THREW
  }
}

/**
 * `PATCH /settings/company`. NOTE (finding F-9, pinned by `MTP-PERM-14`): this
 * endpoint answers 200 while persisting NONE of `discount_floor_mode`,
 * `default_max_discount_percent`, `default_minimum_margin` — use
 * `patchCompany()` below for those.
 */
export async function patchCompanySettings(
  request: APIRequestContext,
  session: Session,
  payload: Record<string, unknown>,
): Promise<number> {
  try {
    const res = await request.patch(`${API_BASE}/settings/company`, {
      headers: authHeaders(session),
      data: payload,
    })
    return res.status()
  } catch {
    return CLEANUP_THREW
  }
}

/**
 * `PUT /companies/{id}` — the ONLY endpoint that actually persists the
 * pricing-policy triple (`discount_floor_mode`,
 * `default_max_discount_percent`, `default_minimum_margin`). Never throws;
 * returns the observed status, like every cleanup-capable helper here.
 */
export async function patchCompany(
  request: APIRequestContext,
  session: Session,
  payload: Record<string, unknown>,
): Promise<number> {
  try {
    const res = await request.put(`${API_BASE}/companies/${session.companyId}`, {
      headers: authHeaders(session),
      data: payload,
    })
    return res.status()
  } catch {
    return CLEANUP_THREW
  }
}

export type { ApiResult }
