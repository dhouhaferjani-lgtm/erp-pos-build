/**
 * MONEY TEST CAMPAIGN — agent W2a (§E `TRE`/`WHT` treasury, MTP-TRE-01..77, MTP-WHT-01..05).
 *
 * Support helpers ONLY for W2a's own spec files. Talks to the LIVE local stack
 * (web :5173 -> api :8010, tenant demo-pharmacy-tn). No mocks anywhere — every
 * response asserted against in the W2a specs is the real backend response.
 *
 * Uses Playwright's `request` APIRequestContext directly (not the browser) for
 * almost every case: treasury money-movement endpoints are stateful multi-step
 * flows (payment -> allocate -> refund, instrument -> remit -> clear, statement
 * upload -> reconcile -> complete) that are far more reliable driven at the API
 * layer than through comboboxes, and this mirrors the pattern already proven by
 * e2e/smoke/treasury-spine.smoke.ts and treasury-phase5b-reconciliation.smoke.ts.
 * A handful of cases that specifically assert UI behaviour (button visibility
 * despite no FE permission gate, a displayed remittance total) drive `page`
 * instead — those import loginAsRole from ./helpers.ts.
 *
 * All test data authored by W2a carries the `W2a-` prefix so it never collides
 * with sibling money-campaign agents or seeded demo data.
 */
import type { APIRequestContext } from '@playwright/test'
import { expect } from '@playwright/test'

export const PREFIX = 'W2a'
export const API_BASE = 'http://127.0.0.1:8010/api/v1'

// accountant / viewer / technician were added by W-5b (2026-08-04) to match
// `helpers.ts`'s ROLE_CREDENTIALS — `MTP-TRE-47/48` need the accountant, who is
// the only seeded principal holding bank-statements.reconcile WITHOUT
// bank-statements.reopen. Purely additive: the three original entries are
// unchanged, so every existing `login(request, 'owner'|'manager'|'cashier')`
// call is untouched.
export const CREDENTIALS = {
  owner: { email: 'owner@pharmabio.tn', password: 'password' },
  manager: { email: 'manager@pharmabio.tn', password: 'password' },
  cashier: { email: 'cashier@pharmabio.tn', password: 'password' },
  accountant: { email: 'accountant@pharmabio.tn', password: 'password' },
  viewer: { email: 'viewer@pharmabio.tn', password: 'password' },
  technician: { email: 'technician@pharmabio.tn', password: 'password' },
} as const

export type TreRole = keyof typeof CREDENTIALS

export interface Session {
  token: string
  userId: string
  tenantId: string
  companyId: string
  permissions: string[]
}

/** Uniqueness across concurrent test runs / re-runs against the live stack. */
export function uniqueName(label: string): string {
  return `${PREFIX}-${label}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
}

// --- decimal helpers: integer millimes arithmetic, never a float on money ---

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
  const intPart = abs / 1000n
  const frac = (abs % 1000n).toString().padStart(3, '0')
  return `${negative ? '-' : ''}${intPart.toString()}.${frac}`
}

export function addMoney(a: string, b: string): string {
  return fromMillimes(toMillimes(a) + toMillimes(b))
}

export function subMoney(a: string, b: string): string {
  return fromMillimes(toMillimes(a) - toMillimes(b))
}

/** Logs in as one of the three seeded demo-pharmacy-tn roles via the real
 * /auth/login endpoint (NOT the UI form) and returns a bearer-token session. */
export async function login(request: APIRequestContext, role: TreRole): Promise<Session> {
  const res = await request.post(`${API_BASE}/auth/login`, {
    headers: { Accept: 'application/json' },
    data: CREDENTIALS[role],
  })
  expect(res.ok(), `login as ${role} -> ${res.status()}`).toBeTruthy()
  const body = (await res.json()).data as {
    token: string
    user: { id: string; tenantId: string; permissions: string[] }
  }
  const companies = await request.get(`${API_BASE}/user/companies`, {
    headers: { Authorization: `Bearer ${body.token}`, Accept: 'application/json' },
  })
  const companiesBody = (await companies.json()).data as Array<{ id: string; is_primary: boolean }>
  const companyId = (companiesBody.find((c) => c.is_primary) ?? companiesBody[0])!.id
  return {
    token: body.token,
    userId: body.user.id,
    tenantId: body.user.tenantId,
    companyId,
    permissions: body.user.permissions,
  }
}

export function authHeaders(session: Session): Record<string, string> {
  return {
    Authorization: `Bearer ${session.token}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  }
}

export interface JsonResult {
  status: number
  ok: boolean
  data: Record<string, unknown>
}

async function asJsonResult(res: { status(): number; ok(): boolean; json(): Promise<unknown> }): Promise<JsonResult> {
  let body: unknown = {}
  try {
    body = await res.json()
  } catch {
    body = {}
  }
  const parsed = (body as { data?: Record<string, unknown>; error?: Record<string, unknown> })
  return {
    status: res.status(),
    ok: res.ok(),
    data: (parsed.data ?? parsed.error ?? (body as Record<string, unknown>)) ?? {},
  }
}

export async function createCustomer(
  request: APIRequestContext,
  session: Session,
  name: string,
): Promise<string> {
  const res = await request.post(`${API_BASE}/partners`, {
    headers: authHeaders(session),
    data: { name, type: 'customer' },
  })
  expect(res.ok(), `create customer ${name} -> ${res.status()} ${await res.text()}`).toBeTruthy()
  return String(((await res.json()).data as { id: string }).id)
}

/**
 * Creates a Posted invoice whose final `total`/`balance_due` equals exactly
 * `targetTotal` (a plan-exact fixture value like `600.000`). Achieved by
 * setting the single line's `tax_rate` to `0.00` (so Draft subtotal ==
 * unit_price, no line VAT) and pricing the line at `targetTotal - 1.000` to
 * absorb the Tunisia document-level stamp duty (1.000 flat) that
 * `confirm()` applies — the same mechanism W1b's documents-totals.spec.ts
 * documented (InvoiceController::store() does NOT apply document-level
 * taxes; confirm() does). Requires targetTotal > 1.000.
 */
export async function createPostedInvoice(
  request: APIRequestContext,
  session: Session,
  partnerId: string,
  targetTotal: string,
  description = 'W2a treasury fixture line',
): Promise<{ id: string; total: string }> {
  const unitPrice = subMoney(targetTotal, '1.000')
  const createRes = await request.post(`${API_BASE}/invoices`, {
    headers: authHeaders(session),
    data: {
      partner_id: partnerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ description, quantity: '1', unit_price: unitPrice, tax_rate: '0.00' }],
    },
  })
  expect(createRes.ok(), `create invoice -> ${createRes.status()} ${await createRes.text()}`).toBeTruthy()
  const invoiceId = String(((await createRes.json()).data as { id: string }).id)

  const confirmRes = await request.post(`${API_BASE}/invoices/${invoiceId}/confirm`, {
    headers: authHeaders(session),
  })
  expect(confirmRes.ok(), `confirm invoice -> ${confirmRes.status()} ${await confirmRes.text()}`).toBeTruthy()

  const postRes = await request.post(`${API_BASE}/invoices/${invoiceId}/post`, {
    headers: authHeaders(session),
  })
  expect(postRes.ok(), `post invoice -> ${postRes.status()} ${await postRes.text()}`).toBeTruthy()
  const posted = (await postRes.json()).data as { total: string; balance_due: string }
  expect(posted.total, `posted invoice total matches target ${targetTotal}`).toBe(targetTotal)
  expect(posted.balance_due).toBe(targetTotal)

  return { id: invoiceId, total: posted.total }
}

export async function getPaymentMethods(
  request: APIRequestContext,
  session: Session,
): Promise<Array<{ id: string; code: string; instrument_kind: string | null; has_maturity: boolean }>> {
  const res = await request.get(`${API_BASE}/payment-methods`, { headers: authHeaders(session) })
  expect(res.ok()).toBeTruthy()
  return (await res.json()).data
}

export async function getRepositories(
  request: APIRequestContext,
  session: Session,
): Promise<Array<{ id: string; code: string; type: string; currency: string; balance: string; gl_account_id: string | null; is_active: boolean }>> {
  const res = await request.get(`${API_BASE}/payment-repositories`, { headers: authHeaders(session) })
  expect(res.ok()).toBeTruthy()
  return (await res.json()).data
}

export async function getRepositoryBalance(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
): Promise<string> {
  const res = await request.get(`${API_BASE}/payment-repositories/${repositoryId}`, {
    headers: authHeaders(session),
  })
  expect(res.ok()).toBeTruthy()
  return String(((await res.json()).data as { balance: string }).balance)
}

export async function createPayment(
  request: APIRequestContext,
  session: Session,
  payload: Record<string, unknown>,
): Promise<JsonResult> {
  const res = await request.post(`${API_BASE}/payments`, {
    headers: authHeaders(session),
    data: payload,
  })
  return asJsonResult(res)
}

export async function post(
  request: APIRequestContext,
  session: Session,
  path: string,
  payload?: Record<string, unknown>,
): Promise<JsonResult> {
  const res = await request.post(`${API_BASE}${path}`, {
    headers: authHeaders(session),
    data: payload ?? {},
  })
  return asJsonResult(res)
}

export async function get(
  request: APIRequestContext,
  session: Session,
  path: string,
): Promise<JsonResult> {
  const res = await request.get(`${API_BASE}${path}`, { headers: authHeaders(session) })
  return asJsonResult(res)
}

export async function patch(
  request: APIRequestContext,
  session: Session,
  path: string,
  payload: Record<string, unknown>,
): Promise<JsonResult> {
  const res = await request.patch(`${API_BASE}${path}`, {
    headers: authHeaders(session),
    data: payload,
  })
  return asJsonResult(res)
}

export async function del(
  request: APIRequestContext,
  session: Session,
  path: string,
): Promise<JsonResult> {
  const res = await request.delete(`${API_BASE}${path}`, { headers: authHeaders(session) })
  return asJsonResult(res)
}

export const TODAY = new Date().toISOString().slice(0, 10)
