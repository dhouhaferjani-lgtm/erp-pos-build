/**
 * MONEY TEST CAMPAIGN — Wave W-X (EXCLUSIVE / DESTRUCTIVE leg).
 *
 * Cases: TAX-02/03/06, CFG-08/09, TAX-07/09..12, RFP-01..12, GATE-01..06,
 * CMP-01..05 (+ PERM-12 which is orchestrator/console-driven, not a spec).
 *
 * W-X is the ONLY agent on the stack while it runs. It mutates country-scoped,
 * shared reference data (tax configurations, VAT periods, company tax_status,
 * refund policies) that every other leg must never see mid-flight — which is
 * exactly why it runs last and alone.
 *
 * STATE RESTORATION IS A DELIVERABLE. Every country-scoped mutation is wrapped
 * in a try/finally that restores the pre-case value and re-reads to VERIFY the
 * restore actually landed. The demo-pharmacy-tn tenant is the E-7 fiscal
 * evidence tenant and holds every prior wave's fixtures; a leaked mutation
 * corrupts them.
 *
 * Talks to the LIVE local stack (web :5173 -> api :8010, tenant
 * demo-pharmacy-tn). No mocks — every asserted response is the real backend.
 * Driven at the API layer (Playwright `request` fixture) like treasury-support.ts,
 * because these are stateful config flows far more reliable than comboboxes.
 */
import type { APIRequestContext } from '@playwright/test'
import { expect } from '@playwright/test'
import {
  API_BASE,
  authHeaders,
  login,
  type JsonResult,
  type Session,
  type TreRole,
} from './treasury-support'

export { API_BASE, authHeaders, login, type JsonResult, type Session, type TreRole }
export { addMoney, subMoney, TODAY } from './treasury-support'

export const PREFIX = 'WX'

/** Uniqueness across re-runs against the live stack. */
export function uniqueName(label: string): string {
  return `${PREFIX}-${label}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
}

// --- generic verbs (mirror treasury-support's shape; add PUT) --------------

async function asJsonResult(res: {
  status(): number
  ok(): boolean
  json(): Promise<unknown>
}): Promise<JsonResult> {
  let body: unknown = {}
  try {
    body = await res.json()
  } catch {
    body = {}
  }
  const parsed = body as { data?: Record<string, unknown>; error?: Record<string, unknown> }
  return {
    status: res.status(),
    ok: res.ok(),
    data: (parsed.data ?? parsed.error ?? (body as Record<string, unknown>)) ?? {},
  }
}

export async function put(
  request: APIRequestContext,
  session: Session,
  path: string,
  payload: Record<string, unknown>,
): Promise<JsonResult> {
  const res = await request.put(`${API_BASE}${path}`, {
    headers: authHeaders(session),
    data: payload,
  })
  return asJsonResult(res)
}

/** Raw GET that returns the FULL parsed body (not unwrapped), for list/array endpoints. */
export async function getRaw(
  request: APIRequestContext,
  session: Session,
  path: string,
): Promise<{ status: number; body: unknown }> {
  const res = await request.get(`${API_BASE}${path}`, { headers: authHeaders(session) })
  let body: unknown = null
  try {
    body = await res.json()
  } catch {
    body = null
  }
  return { status: res.status(), body }
}

// --- 2xx-gated, non-masking cleanup (statement-support.ts house pattern) ----

export function isCleanupSuccess(status: number): boolean {
  return status >= 200 && status < 300
}

// --- tax configuration snapshot / restore (country-scoped reference data) ---

export interface TaxConfigSnapshot {
  id: string
  code: string | null
  name: string | null
  percentage_rate: string | null
  fixed_amount: string | null
  is_active: boolean
  sequence_order: number | null
}

/** Fields that matter for byte-identity of the entry snapshot. */
type TaxConfigRow = TaxConfigSnapshot & Record<string, unknown>

export async function listTaxConfigs(
  request: APIRequestContext,
  session: Session,
): Promise<TaxConfigRow[]> {
  const { status, body } = await getRaw(request, session, '/taxation/configurations')
  expect(status, 'list tax configs -> 200').toBe(200)
  const rows = (body as { data?: TaxConfigRow[] }).data ?? (body as TaxConfigRow[])
  return rows
}

export function snapshotOf(row: TaxConfigRow): TaxConfigSnapshot {
  return {
    id: String(row.id),
    code: (row.code as string | null) ?? null,
    name: (row.name as string | null) ?? null,
    percentage_rate: (row.percentage_rate as string | null) ?? null,
    fixed_amount: (row.fixed_amount as string | null) ?? null,
    is_active: Boolean(row.is_active),
    sequence_order: (row.sequence_order as number | null) ?? null,
  }
}

/** PATCH a tax config back to its captured field values. Country-scoped. */
export async function restoreTaxConfig(
  request: APIRequestContext,
  session: Session,
  snap: TaxConfigSnapshot,
): Promise<number> {
  const payload: Record<string, unknown> = {
    name: snap.name,
    code: snap.code,
    is_active: snap.is_active,
  }
  if (snap.percentage_rate !== null) payload.percentage_rate = snap.percentage_rate
  if (snap.fixed_amount !== null) payload.fixed_amount = snap.fixed_amount
  const res = await request.patch(`${API_BASE}/taxation/configurations/${snap.id}`, {
    headers: authHeaders(session),
    data: payload,
  })
  return res.status()
}

/**
 * Restore a single config field-set and VERIFY it re-reads identical.
 * Throws only via expect() on the success path — never inside a masking finally.
 */
export async function restoreAndVerifyTaxConfig(
  request: APIRequestContext,
  session: Session,
  snap: TaxConfigSnapshot,
): Promise<void> {
  const status = await restoreTaxConfig(request, session, snap)
  expect(isCleanupSuccess(status), `restore tax config ${snap.code} -> ${status}`).toBeTruthy()
  const rows = await listTaxConfigs(request, session)
  const now = rows.find((r) => String(r.id) === snap.id)
  expect(now, `config ${snap.code} still present`).toBeTruthy()
  const after = snapshotOf(now as TaxConfigRow)
  expect(after.percentage_rate, `${snap.code} rate restored`).toBe(snap.percentage_rate)
  expect(after.fixed_amount, `${snap.code} fixed_amount restored`).toBe(snap.fixed_amount)
  expect(after.is_active, `${snap.code} is_active restored`).toBe(snap.is_active)
}

// --- company tax_status snapshot / restore ---------------------------------

export async function getCompanyTaxFields(
  request: APIRequestContext,
  session: Session,
): Promise<{ tax_status: string; default_tax_rate: string | null; vat_number: string | null }> {
  const { status, body } = await getRaw(request, session, `/companies/${session.companyId}`)
  expect(status, 'get company -> 200').toBe(200)
  const d = (body as { data: Record<string, unknown> }).data
  return {
    tax_status: String(d.tax_status),
    default_tax_rate: (d.default_tax_rate as string | null) ?? null,
    vat_number: (d.vat_number as string | null) ?? null,
  }
}

export async function putCompany(
  request: APIRequestContext,
  session: Session,
  payload: Record<string, unknown>,
): Promise<JsonResult> {
  return put(request, session, `/companies/${session.companyId}`, payload)
}

// --- reservation settings (refund policies) snapshot / restore -------------

export async function getReservationSettings(
  request: APIRequestContext,
  session: Session,
): Promise<Record<string, unknown>> {
  const { status, body } = await getRaw(
    request,
    session,
    `/companies/${session.companyId}/reservation-settings`,
  )
  expect(status, 'get reservation-settings -> 200').toBe(200)
  return (body as { data: Record<string, unknown> }).data
}

export async function putReservationSettings(
  request: APIRequestContext,
  session: Session,
  payload: Record<string, unknown>,
): Promise<JsonResult> {
  return put(request, session, `/companies/${session.companyId}/reservation-settings`, payload)
}

/** The refund-policy fields W-X mutates, for capture-before / restore-after. */
export const REFUND_POLICY_KEYS = [
  'daily_refund_cap_per_cashier',
  'daily_refund_cap_override_allowed',
  'manager_override_threshold_amount',
  'manager_override_threshold_percent',
  'customer_history_window_days',
  'out_of_window_policy',
  'allowed_refund_destinations',
  'proration_strategy',
  'voucher_default_expiry_days',
] as const

export function pickRefundPolicy(all: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const k of REFUND_POLICY_KEYS) out[k] = all[k]
  return out
}

// --- VAT period helpers -----------------------------------------------------

export interface VatPeriodRow {
  id: string
  period_start: string
  period_end: string
  status: string
  [k: string]: unknown
}

export async function listVatPeriods(
  request: APIRequestContext,
  session: Session,
  year?: number,
): Promise<VatPeriodRow[]> {
  const q = year ? `?year=${year}` : ''
  const { status, body } = await getRaw(request, session, `/vat/periods${q}`)
  expect(status, 'list vat periods -> 200').toBe(200)
  return ((body as { data?: VatPeriodRow[] }).data ?? []) as VatPeriodRow[]
}
