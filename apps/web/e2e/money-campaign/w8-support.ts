/**
 * MONEY TEST CAMPAIGN — wave W-8 (§B.6 row 81, §B.8 rows 103-104, debt C-4).
 *
 * Cross-tenant / cross-company ISOLATION: `MTP-ISO-01..07`, `MTP-GL-24/25`,
 * `MTP-PUR-13`, `MTP-LOY-15`.
 *
 * Talks to the LIVE local stack (api :8010, web :5173). No mocks anywhere.
 *
 * WHY `APIRequestContext` AND NOT `page`
 * --------------------------------------
 * Every other wave logs into ONE tenant and can use the browser-scoped
 * `apiRequest(page, ...)` helper in `helpers.ts`, which reads the bearer token
 * out of the page's own localStorage. W-8 needs TWO tenants authenticated at the
 * same time and needs to point tenant-A's token at tenant-B's resource ids — a
 * shape the page-scoped helper structurally cannot express (one page holds one
 * token). So the isolation matrix is driven at the API layer with explicit
 * sessions, exactly like `treasury-support.ts` / `w6-support.ts` do. The two
 * cases that assert a rendered UI surface (`MTP-ISO-01` list walk,
 * `MTP-GL-24` finance pages) drive `page` and import `loginPageAsTenant` below.
 *
 * TENANT FOOTPRINT
 * ----------------
 * All WRITES land in `demo-tenant-a` / `demo-tenant-b` (this wave's own tenants,
 * provisioned by C-4 — see MONEY-CAMPAIGN-RESULTS.md § W-8). `demo-pharmacy-tn`
 * is touched by exactly ONE helper, {@link harvestPharmacyPosReceipt}, and only
 * with a GET.
 */
import type { APIRequestContext, Page } from '@playwright/test'
import { expect } from '@playwright/test'

export const PREFIX = 'W8'
export const API_BASE = 'http://127.0.0.1:8010/api/v1'

/**
 * C-4 credentials. Both users are `admin`-role owners of their tenant's primary
 * company, so a refusal observed in this wave is an ISOLATION refusal and never
 * a permission refusal — that distinction is the whole point of the wave.
 */
export const TENANT_CREDENTIALS = {
  a: { email: 'owner@demo-tenant-a.tn', password: 'password', slug: 'demo-tenant-a' },
  b: { email: 'owner@demo-tenant-b.tn', password: 'password', slug: 'demo-tenant-b' },
} as const

export type TenantKey = keyof typeof TENANT_CREDENTIALS

/** READ-ONLY probe target (the other waves' tenant). Never written to by W-8. */
export const PHARMACY_CREDENTIALS = { email: 'owner@pharmabio.tn', password: 'password' } as const

export interface CompanyRow {
  id: string
  name: string
  currency: string
  is_primary: boolean
}

export interface Session {
  tenant: TenantKey | 'pharmacy'
  token: string
  userId: string
  tenantId: string
  /** The company this session addresses (sent as `X-Company-Id`). */
  companyId: string
  companies: CompanyRow[]
  permissions: string[]
}

export function uniqueName(label: string): string {
  return `${PREFIX}-${label}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
}

export const TODAY = new Date().toISOString().slice(0, 10)

// ---------------------------------------------------------------------------
// Exact-string money (house rule: never a float on money)
// ---------------------------------------------------------------------------

export function toMillimes(value: string): bigint {
  const [intPart, fracPart = ''] = value.split('.')
  const negative = intPart.trimStart().startsWith('-')
  const intAbs = intPart.replace('-', '')
  const frac = (fracPart + '000').slice(0, 3)
  const magnitude = BigInt(intAbs) * 1000n + BigInt(frac)
  return negative ? -magnitude : magnitude
}

export function fromMillimes(value: bigint): string {
  const negative = value < 0n
  const abs = negative ? -value : value
  const frac = (abs % 1000n).toString().padStart(3, '0')
  return `${negative ? '-' : ''}${(abs / 1000n).toString()}.${frac}`
}

export function addMoney(a: string, b: string): string {
  return fromMillimes(toMillimes(a) + toMillimes(b))
}

export function subMoney(a: string, b: string): string {
  return fromMillimes(toMillimes(a) - toMillimes(b))
}

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------

/**
 * Repeated scoped runs trip `RateLimiter::for('login')` (5/min per email+IP).
 * W-7 (S-5) established that Laravel does NOT increment the bucket while it is
 * blocking, so a plain retry after the window is free. Kept here because W-8
 * logs in up to 4 principals per file.
 */
async function loginResilient(
  request: APIRequestContext,
  credentials: { email: string; password: string },
): Promise<{ token: string; user: { id: string; tenantId: string; permissions: string[] } }> {
  for (let attempt = 0; attempt < 4; attempt++) {
    const res = await request.post(`${API_BASE}/auth/login`, {
      headers: { Accept: 'application/json' },
      data: { email: credentials.email, password: credentials.password },
    })
    if (res.ok()) {
      return (await res.json()).data
    }
    if (res.status() !== 429) {
      throw new Error(`login ${credentials.email} -> ${res.status()} ${await res.text()}`)
    }
    await new Promise((r) => setTimeout(r, 20_000))
  }
  throw new Error(`login ${credentials.email} -> still 429 after 4 attempts`)
}

async function buildSession(
  request: APIRequestContext,
  tenant: Session['tenant'],
  credentials: { email: string; password: string },
): Promise<Session> {
  const body = await loginResilient(request, credentials)
  const res = await request.get(`${API_BASE}/user/companies`, {
    headers: { Authorization: `Bearer ${body.token}`, Accept: 'application/json' },
  })
  expect(res.ok(), `GET /user/companies for ${credentials.email} -> ${res.status()}`).toBeTruthy()
  const companies = (await res.json()).data as CompanyRow[]
  const primary = companies.find((c) => c.is_primary) ?? companies[0]
  expect(primary, `${credentials.email} has at least one company`).toBeDefined()
  return {
    tenant,
    token: body.token,
    userId: body.user.id,
    tenantId: body.user.tenantId,
    companyId: primary!.id,
    companies,
    permissions: body.user.permissions,
  }
}

export async function loginTenant(request: APIRequestContext, key: TenantKey): Promise<Session> {
  return buildSession(request, key, TENANT_CREDENTIALS[key])
}

/** READ-ONLY session on the other waves' tenant. Only GETs may use it. */
export async function loginPharmacyReadOnly(request: APIRequestContext): Promise<Session> {
  return buildSession(request, 'pharmacy', PHARMACY_CREDENTIALS)
}

/**
 * Same token, different `X-Company-Id`. This is how the cross-COMPANY boundary
 * (`MTP-ISO-06`, `MTP-GL-25`, `MTP-PUR-13`) is addressed: one principal, one
 * tenant, two companies — a boundary that is completely independent of the
 * per-tenant database and therefore must be asserted separately.
 */
export function withCompany(session: Session, companyId: string): Session {
  return { ...session, companyId }
}

/**
 * A session whose bearer token belongs to `tokenOwner` but whose `X-Company-Id`
 * header names a company of a DIFFERENT tenant. The exact shape `MTP-ISO-05`
 * exists to refuse.
 */
export function withForeignCompanyHeader(session: Session, foreignCompanyId: string): Session {
  return { ...session, companyId: foreignCompanyId }
}

export function authHeaders(session: Session): Record<string, string> {
  return {
    Authorization: `Bearer ${session.token}`,
    'X-Company-Id': session.companyId,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  }
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

export interface JsonResult {
  status: number
  ok: boolean
  /** The unwrapped `data` (or `error`) envelope. */
  data: Record<string, unknown>
  /** The RAW response text — what the leak scanner reads. Never unwrapped. */
  raw: string
}

async function asJsonResult(res: {
  status(): number
  ok(): boolean
  text(): Promise<string>
}): Promise<JsonResult> {
  const raw = await res.text()
  let body: unknown = {}
  try {
    body = JSON.parse(raw)
  } catch {
    body = {}
  }
  const parsed = body as { data?: Record<string, unknown>; error?: Record<string, unknown> }
  return {
    status: res.status(),
    ok: res.ok(),
    data: parsed.data ?? parsed.error ?? (body as Record<string, unknown>) ?? {},
    raw,
  }
}

export async function get(request: APIRequestContext, session: Session, path: string): Promise<JsonResult> {
  return asJsonResult(await request.get(`${API_BASE}${path}`, { headers: authHeaders(session) }))
}

export async function post(
  request: APIRequestContext,
  session: Session,
  path: string,
  payload?: Record<string, unknown>,
): Promise<JsonResult> {
  return asJsonResult(
    await request.post(`${API_BASE}${path}`, { headers: authHeaders(session), data: payload ?? {} }),
  )
}

export async function patch(
  request: APIRequestContext,
  session: Session,
  path: string,
  payload: Record<string, unknown>,
): Promise<JsonResult> {
  return asJsonResult(
    await request.patch(`${API_BASE}${path}`, { headers: authHeaders(session), data: payload }),
  )
}

export async function del(request: APIRequestContext, session: Session, path: string): Promise<JsonResult> {
  return asJsonResult(await request.delete(`${API_BASE}${path}`, { headers: authHeaders(session) }))
}

// ---------------------------------------------------------------------------
// Refusal / leak vocabulary
// ---------------------------------------------------------------------------

/**
 * "Fails closed" for an isolation probe. 404 is preferred (it does not even
 * confirm the id exists); 403 is accepted; 401 is accepted for the token-claim
 * path. A `422` is ALSO accepted ONLY where the route validates the foreign id
 * as an input (`ScopedExists`) rather than route-model-binding it — those cases
 * say so at the call site.
 *
 * A 5xx is NOT a pass: it means the boundary was reached by an unguarded code
 * path and the shape of the error is accidental. A 200 is the leak.
 */
export const FAIL_CLOSED_STATUSES = [401, 403, 404] as const

export function isFailClosed(status: number): boolean {
  return (FAIL_CLOSED_STATUSES as readonly number[]).includes(status)
}

export interface LeakHit {
  token: string
  /** ~120 chars of the raw response around the hit — the evidence for the ticket. */
  context: string
}

/**
 * Scans a RAW response body for any forbidden token (a foreign id, document
 * number, partner name or exact money string).
 *
 * Deliberately operates on the raw text and not on a parsed object: a leak that
 * hides in an error message, a validation hint, a URL, or a nested `meta` block
 * is still a leak, and walking a typed object would miss all four.
 */
export function findLeaks(
  raw: string,
  forbidden: readonly string[],
  /**
   * Tokens the PROBE ITSELF supplied (ids in the URL or the request body).
   * A refusal that echoes the id you just sent it is not a leak — it tells the
   * caller nothing it did not already know. Excluding them is mandatory here
   * because the local stack runs `APP_DEBUG=true`, so Laravel's
   * `ModelNotFoundException` body quotes the requested id verbatim; without this
   * every single fail-closed probe would read as a false-positive P0.
   */
  echoed: readonly string[] = [],
): LeakHit[] {
  const hits: LeakHit[] = []
  for (const token of forbidden) {
    if (token === '' || echoed.includes(token)) continue
    const at = raw.indexOf(token)
    if (at >= 0) {
      hits.push({ token, context: raw.slice(Math.max(0, at - 60), at + token.length + 60) })
    }
  }
  return hits
}

/** Asserts a payload carries no trace of the other side. Failure prints the hit. */
export function expectNoLeak(
  raw: string,
  forbidden: readonly string[],
  what: string,
  echoed: readonly string[] = [],
): void {
  const hits = findLeaks(raw, forbidden, echoed)
  expect(
    hits,
    `${what}: CROSS-TENANT LEAK — ${hits.map((h) => `"${h.token}" in …${h.context}…`).join(' | ')}`,
  ).toEqual([])
}

// ---------------------------------------------------------------------------
// Money fixtures
// ---------------------------------------------------------------------------

export async function createPartner(
  request: APIRequestContext,
  session: Session,
  name: string,
  type: 'customer' | 'supplier' = 'customer',
): Promise<string> {
  const res = await post(request, session, '/partners', { name, type })
  expect(res.status, `create ${type} ${name} -> ${res.status} ${res.raw}`).toBe(201)
  return String(res.data.id)
}

/**
 * A Posted invoice whose `total`/`balance_due` equals exactly `targetTotal`.
 * Same mechanism `treasury-support.ts` documented: `tax_rate 0.00` on the single
 * line and `unit_price = targetTotal - 1.000` to absorb the TN document-level
 * stamp duty that `confirm()` applies.
 */
export async function createPostedInvoice(
  request: APIRequestContext,
  session: Session,
  partnerId: string,
  targetTotal: string,
  description: string,
): Promise<{ id: string; number: string; total: string }> {
  const create = await post(request, session, '/invoices', {
    partner_id: partnerId,
    document_date: TODAY,
    lines: [
      { description, quantity: '1', unit_price: subMoney(targetTotal, '1.000'), tax_rate: '0.00' },
    ],
  })
  expect(create.status, `create invoice -> ${create.status} ${create.raw}`).toBe(201)
  const id = String(create.data.id)

  const confirm = await post(request, session, `/invoices/${id}/confirm`)
  expect(confirm.ok, `confirm invoice -> ${confirm.status} ${confirm.raw}`).toBeTruthy()

  const posted = await post(request, session, `/invoices/${id}/post`)
  expect(posted.ok, `post invoice -> ${posted.status} ${posted.raw}`).toBeTruthy()
  expect(posted.data.total, 'posted total matches the fixture target').toBe(targetTotal)

  return { id, number: String(posted.data.document_number), total: String(posted.data.total) }
}

export async function cashRepositoryId(request: APIRequestContext, session: Session): Promise<string> {
  const res = await get(request, session, '/payment-repositories')
  expect(res.ok, `GET /payment-repositories -> ${res.status}`).toBeTruthy()
  const rows = res.data as unknown as Array<{ id: string; code: string; type: string }>
  const cash = rows.find((r) => r.code === 'CASH-01') ?? rows.find((r) => r.type === 'cash') ?? rows[0]
  expect(cash, 'a payment repository exists').toBeDefined()
  return cash!.id
}

export async function cashMethodId(request: APIRequestContext, session: Session): Promise<string> {
  const res = await get(request, session, '/payment-methods')
  expect(res.ok, `GET /payment-methods -> ${res.status}`).toBeTruthy()
  const rows = res.data as unknown as Array<{ id: string; code: string }>
  const cash = rows.find((r) => r.code === 'CASH')
  expect(cash, 'the CASH payment method exists').toBeDefined()
  return cash!.id
}

export async function createAllocatedPayment(
  request: APIRequestContext,
  session: Session,
  opts: { partnerId: string; documentId: string; amount: string; methodId: string; repositoryId: string },
): Promise<{ id: string; amount: string }> {
  const res = await post(request, session, '/payments', {
    partner_id: opts.partnerId,
    payment_method_id: opts.methodId,
    payment_repository_id: opts.repositoryId,
    amount: opts.amount,
    payment_date: TODAY,
    direction: 'inbound',
    allocations: [{ document_id: opts.documentId, amount: opts.amount }],
  })
  expect(res.status, `create payment -> ${res.status} ${res.raw}`).toBe(201)
  return { id: String(res.data.id), amount: String(res.data.amount) }
}

export async function createProductWithCost(
  request: APIRequestContext,
  session: Session,
  name: string,
): Promise<{ id: string; sku: string }> {
  const res = await post(request, session, '/products', { name, sku: name, type: 'part' })
  expect(res.status, `create product ${name} -> ${res.status} ${res.raw}`).toBe(201)
  return { id: String(res.data.id), sku: name }
}

export async function ensureLoyaltyProgram(
  request: APIRequestContext,
  session: Session,
  name: string,
): Promise<string> {
  const res = await post(request, session, '/loyalty/programs', {
    name,
    program_type: 'points',
    status: 'active',
  })
  expect(res.status, `create loyalty program -> ${res.status} ${res.raw}`).toBe(201)
  return String(res.data.id)
}

export interface LoyaltyFixture {
  programId: string
  memberId: string
  enrollmentId: string
  phone: string
  points: string
}

export async function createEnrolledLoyaltyMember(
  request: APIRequestContext,
  session: Session,
  opts: { programName: string; phone: string; firstName: string; adjustPoints: string },
): Promise<LoyaltyFixture> {
  const programId = await ensureLoyaltyProgram(request, session, opts.programName)

  const member = await post(request, session, '/loyalty/members', {
    phone: opts.phone,
    first_name: opts.firstName,
    last_name: PREFIX,
  })
  expect(member.status, `create loyalty member -> ${member.status} ${member.raw}`).toBe(201)
  const memberId = String(member.data.id)

  const enroll = await post(request, session, `/loyalty/members/${memberId}/enroll`, {
    program_id: programId,
  })
  expect(enroll.ok, `enroll loyalty member -> ${enroll.status} ${enroll.raw}`).toBeTruthy()
  const enrollmentId = String(enroll.data.id)

  const adjust = await post(
    request,
    session,
    `/loyalty/members/${memberId}/enrollments/${enrollmentId}/adjust`,
    { points: opts.adjustPoints, reason: `${PREFIX} isolation fixture` },
  )
  expect(adjust.ok, `adjust loyalty points -> ${adjust.status} ${adjust.raw}`).toBeTruthy()

  return { programId, memberId, enrollmentId, phone: opts.phone, points: opts.adjustPoints }
}

// ---------------------------------------------------------------------------
// The per-tenant fixture bundle
// ---------------------------------------------------------------------------

export interface TenantFixture {
  session: Session
  partnerId: string
  partnerName: string
  invoiceId: string
  invoiceNumber: string
  invoiceTotal: string
  paymentId: string
  paymentAmount: string
  productId: string
  productSku: string
  repositoryId: string
  loyalty: LoyaltyFixture
  /** Everything that must NEVER appear in the other tenant's responses. */
  forbidden: string[]
}

export interface FixtureAmounts {
  invoiceTotal: string
  paymentAmount: string
  loyaltyPoints: string
  /**
   * Per-tenant phone PREFIX only. `loyalty_members.phone` carries a
   * tenant-scoped uniqueness rule, so a fixed number makes the whole wave
   * single-shot — the second run 422s in `beforeAll` and every case reads
   * "did not run". The digits after the prefix are minted per run.
   */
  phonePrefix: string
}

/**
 * Deliberately distinctive amounts. `617.000` / `1319.000` were chosen because
 * neither is a rounding of the other, neither appears in any seeded fixture, and
 * neither is a substring of the other — so a raw-text leak scan cannot produce a
 * false positive OR a false negative on them.
 */
export const AMOUNTS: Record<TenantKey, FixtureAmounts> = {
  a: { invoiceTotal: '617.000', paymentAmount: '241.000', loyaltyPoints: '3131', phonePrefix: '+2169081' },
  b: { invoiceTotal: '1319.000', paymentAmount: '433.000', loyaltyPoints: '7272', phonePrefix: '+2169083' },
}

function mintPhone(prefix: string): string {
  return `${prefix}${Math.floor(Math.random() * 9000 + 1000)}`
}

export async function buildTenantFixture(
  request: APIRequestContext,
  key: TenantKey,
): Promise<TenantFixture> {
  const session = await loginTenant(request, key)
  const amounts = AMOUNTS[key]
  const label = key.toUpperCase()

  const partnerName = uniqueName(`${label}-Customer`)
  const partnerId = await createPartner(request, session, partnerName)

  const invoice = await createPostedInvoice(
    request,
    session,
    partnerId,
    amounts.invoiceTotal,
    `${PREFIX} ${label} isolation fixture line`,
  )

  const repositoryId = await cashRepositoryId(request, session)
  const methodId = await cashMethodId(request, session)
  const payment = await createAllocatedPayment(request, session, {
    partnerId,
    documentId: invoice.id,
    amount: amounts.paymentAmount,
    methodId,
    repositoryId,
  })

  const product = await createProductWithCost(request, session, uniqueName(`${label}-Product`))

  const loyalty = await createEnrolledLoyaltyMember(request, session, {
    programName: uniqueName(`${label}-Program`),
    phone: mintPhone(amounts.phonePrefix),
    firstName: `${PREFIX}-${label}`,
    adjustPoints: amounts.loyaltyPoints,
  })

  return {
    session,
    partnerId,
    partnerName,
    invoiceId: invoice.id,
    invoiceNumber: invoice.number,
    invoiceTotal: invoice.total,
    paymentId: payment.id,
    paymentAmount: payment.amount,
    productId: product.id,
    productSku: product.sku,
    repositoryId,
    loyalty,
    forbidden: [
      partnerName,
      invoice.id,
      invoice.number,
      amounts.invoiceTotal,
      payment.id,
      product.id,
      product.sku,
      loyalty.memberId,
      loyalty.programId,
      loyalty.phone,
    ],
  }
}

// ---------------------------------------------------------------------------
// READ-ONLY probe into the other waves' tenant (POS money for ISO-04/05)
// ---------------------------------------------------------------------------

export interface PharmacyPosProbe {
  session: Session
  receiptId: string
  receiptNumber: string
  receiptTotal: string
  shiftId: string | null
  forbidden: string[]
}

/**
 * ONE GET against `demo-pharmacy-tn` to harvest a real device-authored POS
 * receipt id + total. `MTP-ISO-04` / `MTP-ISO-05` are otherwise vacuous: neither
 * demo tenant has any POS data (no seeder authors `pos_receipts`, plan §0.4), so
 * "tenant B sees no POS money" would be proved against an empty universe. This
 * gives the replay probe a REAL money-bearing id to aim at.
 *
 * Strictly read-only. Returns `null` when the tenant has no receipts, so the
 * case degrades to a recorded BLOCKED half rather than a failure.
 */
export async function harvestPharmacyPosReceipt(
  request: APIRequestContext,
): Promise<PharmacyPosProbe | null> {
  const session = await loginPharmacyReadOnly(request)
  const res = await get(request, session, '/pos/receipts?per_page=5')
  if (!res.ok) return null
  const page = res.data as unknown as { data?: Array<Record<string, unknown>> }
  const rows = page.data ?? []
  if (rows.length === 0) return null
  const row = rows[0]!
  const receiptId = String(row.id)
  const receiptNumber = String(row.receipt_number ?? row.number ?? '')
  const receiptTotal = String(row.total ?? '')

  const shifts = await get(request, session, '/pos/shifts')
  const shiftRows = (shifts.data as unknown as Array<{ id: string }>) ?? []
  const shiftId = Array.isArray(shiftRows) && shiftRows.length > 0 ? String(shiftRows[0]!.id) : null

  return {
    session,
    receiptId,
    receiptNumber,
    receiptTotal,
    shiftId,
    forbidden: [receiptId, receiptNumber, receiptTotal].filter((s) => s !== '' && s !== 'undefined'),
  }
}

// ---------------------------------------------------------------------------
// UI sessions (the two cases that assert a rendered surface)
// ---------------------------------------------------------------------------

/**
 * Logs a real browser page into one of the two W-8 tenants through the real
 * login form. Mirrors `helpers.ts` `loginAsRole()` but parameterised on the W-8
 * credentials rather than the `demo-pharmacy-tn` role table, and retries a 429
 * the same way {@link loginResilient} does.
 */
export async function loginPageAsTenant(page: Page, key: TenantKey): Promise<void> {
  const { email, password } = TENANT_CREDENTIALS[key]
  await page.addInitScript(() => {
    window.localStorage.setItem('autoerp-cookie-consent', 'accepted')
  })
  for (let attempt = 0; attempt < 4; attempt++) {
    await page.goto('/login')
    await page.getByLabel(/email address/i).fill(email)
    await page.getByLabel(/^password$/i).fill(password)
    await page.getByRole('button', { name: /sign in/i }).click()
    try {
      await expect(page).not.toHaveURL(/\/login/, { timeout: 15_000 })
      await expect(page.getByRole('button', { name: /profile/i })).toBeVisible({ timeout: 15_000 })
      return
    } catch (error) {
      if (attempt === 3) throw error
      await new Promise((r) => setTimeout(r, 20_000))
    }
  }
}

/**
 * Waits for a data page to stop showing its loading affordance, then returns the
 * full rendered text. W-7 (S-2) established that matching only `/loading/i`
 * passes instantly under `?lang=fr` — this tenant renders `fr_TN`, so the French
 * stem is mandatory, not optional.
 */
export async function renderedText(page: Page, url: string): Promise<string> {
  await page.goto(url, { waitUntil: 'domcontentloaded' })
  // `networkidle` is BOUNDED and swallowed here: several money surfaces mount a
  // `refetchInterval` tile (W-7's owner-dashboard ticket) and never go idle, so
  // waiting on it unbounded turns a passing isolation assertion into a timeout.
  await page.waitForLoadState('networkidle', { timeout: 8_000 }).catch(() => undefined)
  const spinner = page.getByText(/loading|chargement/i).first()
  if (await spinner.isVisible().catch(() => false)) {
    await spinner.waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => undefined)
  }
  return (await page.locator('body').innerText()).replace(/ | /g, ' ')
}

// ---------------------------------------------------------------------------
// Cleanup (2xx-gated, non-masking — statement-support.ts §I-2a/I-2b contract)
// ---------------------------------------------------------------------------

export { CLEANUP_THREW, isCleanupSuccess } from './statement-support'
