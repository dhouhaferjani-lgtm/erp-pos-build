/**
 * MONEY TEST CAMPAIGN — W-2 execution agent (docs/qa/2026-08-02-full-e2e-campaign-plan.md
 * §F-7: P0 new-case classes IDEM/UOM/FRD/RET + the W-2 config/pricing wave).
 *
 * Support helpers ONLY for this agent's own spec files
 * (fraud-settings.spec.ts, idempotency.spec.ts, return-notes.spec.ts,
 * uom-precision.spec.ts, config-pricing.spec.ts). Not `helpers.ts` (shared
 * login/apiRequest, owned collectively) — kept separate to avoid a
 * collision while sibling money-campaign agents may still be touching the
 * live stack. PREFIX is `W2c` (not `W2a`/`W2b`, already claimed by the
 * original plan's purchasing/treasury agents) so every fixture this file
 * creates is uniquely attributable and never collides with a sibling's
 * data — this satisfies the house "W2-prefixed" fixture-naming convention
 * while staying collision-safe.
 *
 * Real login + real backend against the LIVE local stack (web :5173 -> api
 * :8010, tenant demo-pharmacy-tn). No route mocking anywhere in this file.
 * Reuses w1b-support.ts (documents) and w2b-support.ts (products/suppliers/
 * purchase orders/stock) helpers + fixture ids rather than redefining them.
 */
import type { Page } from '@playwright/test'
import { apiRequest, type ApiResult } from './helpers'
import { COMPANY_ID } from './w2b-support'

export const PREFIX = 'W2c'

export function uniq(base: string): string {
  return `${PREFIX}-${base}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`
}

export function bodyOf(res: ApiResult): Record<string, unknown> {
  return ((res.body as { data?: Record<string, unknown> })?.data ?? (res.body as Record<string, unknown>)) ?? {}
}

// ---------------------------------------------------------------------------
// Fraud settings (surface FRD) — PATCH /fraud-settings is COMPANY-WIDE
// (company_fraud_settings.company_id is UNIQUE — one row per company, no
// narrower scope). Every test that mutates it MUST capture the pre-test
// value and restore it in a `finally`/afterEach so a concurrent sibling
// session's fraud-settings assertions are not corrupted.
// ---------------------------------------------------------------------------
export interface FraudSettingsBody {
  id?: string
  company_id?: string
  abandoned_draft_threshold: number
  time_window_days: number
  alert_emails: string[] | null
  alert_enabled: boolean
  auto_trigger_counting: boolean
  auto_restrict_access: boolean
  cash_variance_over_soft: string
  cash_variance_over_hard: string
  cash_variance_under_soft: string
  cash_variance_under_hard: string
  require_blind_cash_count: boolean
  require_manager_pin_above_hard: boolean
  cash_variance_email_severity: string
  is_configured?: boolean
}

export async function getFraudSettings(page: Page): Promise<FraudSettingsBody> {
  const res = await apiRequest(page, 'GET', '/fraud-settings')
  return bodyOf(res) as unknown as FraudSettingsBody
}

export async function patchFraudSettings(page: Page, patch: Record<string, unknown>): Promise<ApiResult> {
  return apiRequest(page, 'PATCH', '/fraud-settings', patch)
}

export async function resetFraudSettings(page: Page): Promise<ApiResult> {
  return apiRequest(page, 'POST', '/fraud-settings/reset', {})
}

/** Restores every mutable field of a previously-captured fraud-settings
 * snapshot via PATCH, best-effort (does not throw if the restore itself
 * fails — a test cleanup step must never mask the real assertion failure). */
export async function restoreFraudSettings(page: Page, snapshot: FraudSettingsBody): Promise<void> {
  await patchFraudSettings(page, {
    abandoned_draft_threshold: snapshot.abandoned_draft_threshold,
    time_window_days: snapshot.time_window_days,
    alert_emails: snapshot.alert_emails ?? [],
    alert_enabled: snapshot.alert_enabled,
    auto_trigger_counting: snapshot.auto_trigger_counting,
    auto_restrict_access: snapshot.auto_restrict_access,
    cash_variance_over_soft: snapshot.cash_variance_over_soft,
    cash_variance_over_hard: snapshot.cash_variance_over_hard,
    cash_variance_under_soft: snapshot.cash_variance_under_soft,
    cash_variance_under_hard: snapshot.cash_variance_under_hard,
    require_blind_cash_count: snapshot.require_blind_cash_count,
    require_manager_pin_above_hard: snapshot.require_manager_pin_above_hard,
    cash_variance_email_severity: snapshot.cash_variance_email_severity,
  }).catch(() => undefined)
}

// ---------------------------------------------------------------------------
// Return notes / delivery notes (surface RET)
// ---------------------------------------------------------------------------
export interface DocLineSpec {
  productId: string
  description?: string
  quantity: string
  unitPrice: string
  taxRate?: string
  locationId?: string
}

export async function createReturnNote(
  page: Page,
  opts: {
    partnerId: string
    sourceDocumentId?: string
    returnReason?: string
    returnCondition?: string
    locationId?: string
    lines: DocLineSpec[]
  }
): Promise<ApiResult & { id?: string }> {
  const today = new Date().toISOString().slice(0, 10)
  const res = await apiRequest(page, 'POST', '/return-notes', {
    partner_id: opts.partnerId,
    source_document_id: opts.sourceDocumentId,
    document_date: today,
    location_id: opts.locationId,
    return_reason: opts.returnReason,
    return_condition: opts.returnCondition,
    lines: opts.lines.map((l) => ({
      product_id: l.productId,
      description: l.description ?? 'W2c return line',
      quantity: l.quantity,
      unit_price: l.unitPrice,
      tax_rate: l.taxRate ?? '19.00',
      location_id: l.locationId,
    })),
  })
  const body = bodyOf(res)
  return { ...res, id: body.id as string | undefined }
}

export async function confirmReturnNote(page: Page, id: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/return-notes/${id}/confirm`)
}

export async function getReturnNote(page: Page, id: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/return-notes/${id}`)
  return bodyOf(res)
}

export async function createDeliveryNote(
  page: Page,
  opts: { partnerId: string; sourceDocumentId?: string; locationId?: string; lines: DocLineSpec[] }
): Promise<ApiResult & { id?: string }> {
  const today = new Date().toISOString().slice(0, 10)
  const res = await apiRequest(page, 'POST', '/delivery-notes', {
    partner_id: opts.partnerId,
    source_document_id: opts.sourceDocumentId,
    document_date: today,
    location_id: opts.locationId,
    lines: opts.lines.map((l) => ({
      product_id: l.productId,
      description: l.description ?? 'W2c delivery line',
      quantity: l.quantity,
      unit_price: l.unitPrice,
      tax_rate: l.taxRate ?? '19.00',
      location_id: l.locationId,
    })),
  })
  const body = bodyOf(res)
  return { ...res, id: body.id as string | undefined }
}

export async function confirmDeliveryNote(page: Page, id: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/delivery-notes/${id}/confirm`)
}

export async function getDeliveryNote(page: Page, id: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/delivery-notes/${id}`)
  return bodyOf(res)
}

export async function consolidateDeliveryNotes(page: Page, deliveryNoteIds: string[]): Promise<ApiResult> {
  return apiRequest(page, 'POST', '/delivery-notes/consolidate-to-invoice', { delivery_note_ids: deliveryNoteIds })
}

// ---------------------------------------------------------------------------
// Company / setup config (surface CFG)
// ---------------------------------------------------------------------------
export async function getCompanySettings(page: Page): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', '/settings/company')
  return bodyOf(res)
}

export async function patchCompanySettings(page: Page, patch: Record<string, unknown>): Promise<ApiResult> {
  return apiRequest(page, 'PATCH', '/settings/company', patch)
}

export async function getCompany(page: Page, companyId: string = COMPANY_ID): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/companies/${companyId}`)
  return bodyOf(res)
}

export async function putCompany(page: Page, patch: Record<string, unknown>, companyId: string = COMPANY_ID): Promise<ApiResult> {
  return apiRequest(page, 'PUT', `/companies/${companyId}`, patch)
}

export async function getOnboardingStatus(page: Page): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', '/onboarding/status')
  return bodyOf(res)
}

// ---------------------------------------------------------------------------
// Payment methods + GL routing (surface PMT)
// ---------------------------------------------------------------------------
export async function listPaymentMethods(page: Page): Promise<Array<Record<string, unknown>>> {
  const res = await apiRequest(page, 'GET', '/payment-methods')
  const body = res.body as { data?: Array<Record<string, unknown>> }
  return body.data ?? []
}

export async function createPaymentMethod(page: Page, payload: Record<string, unknown>): Promise<ApiResult & { id?: string }> {
  const res = await apiRequest(page, 'POST', '/payment-methods', payload)
  const body = bodyOf(res)
  return { ...res, id: body.id as string | undefined }
}

export async function updatePaymentMethod(page: Page, id: string, payload: Record<string, unknown>): Promise<ApiResult> {
  return apiRequest(page, 'PATCH', `/payment-methods/${id}`, payload)
}

export async function listAccounts(page: Page): Promise<Array<Record<string, unknown>>> {
  const res = await apiRequest(page, 'GET', '/accounts')
  const body = res.body as { data?: Array<Record<string, unknown>> }
  return body.data ?? []
}

export async function listPaymentRepositories(page: Page): Promise<Array<Record<string, unknown>>> {
  const res = await apiRequest(page, 'GET', '/payment-repositories')
  const body = res.body as { data?: Array<Record<string, unknown>> }
  return body.data ?? []
}

// ---------------------------------------------------------------------------
// Pricing (surface PRC)
// ---------------------------------------------------------------------------
export async function createPriceList(page: Page, payload: Record<string, unknown>): Promise<ApiResult & { id?: string }> {
  const res = await apiRequest(page, 'POST', '/price-lists', payload)
  const body = bodyOf(res)
  return { ...res, id: body.id as string | undefined }
}

export async function addPriceListItem(page: Page, priceListId: string, payload: Record<string, unknown>): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/price-lists/${priceListId}/items`, payload)
}

export async function assignPriceListToPartner(page: Page, priceListId: string, payload: Record<string, unknown>): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/price-lists/${priceListId}/partners`, payload)
}

export async function getPrice(
  page: Page,
  params: { product_id: string; partner_id?: string; quantity: string; currency?: string; date?: string }
): Promise<ApiResult> {
  const qs = new URLSearchParams({
    product_id: params.product_id,
    quantity: params.quantity,
    currency: params.currency ?? 'TND',
    ...(params.partner_id ? { partner_id: params.partner_id } : {}),
    ...(params.date ? { date: params.date } : {}),
  })
  return apiRequest(page, 'POST', `/pricing/get-price?${qs.toString()}`)
}

export async function getQuantityBreaks(page: Page, priceListId: string, productId: string): Promise<ApiResult> {
  const qs = new URLSearchParams({ price_list_id: priceListId, product_id: productId })
  return apiRequest(page, 'POST', `/pricing/quantity-breaks?${qs.toString()}`)
}

export async function checkMargin(page: Page, productId: string, sellPrice: string): Promise<ApiResult> {
  const qs = new URLSearchParams({ product_id: productId, sell_price: sellPrice })
  return apiRequest(page, 'POST', `/pricing/check-margin?${qs.toString()}`)
}

// ---------------------------------------------------------------------------
// Expenses (used by the IDEM surface)
// ---------------------------------------------------------------------------
export async function createExpense(page: Page, payload: Record<string, unknown>): Promise<ApiResult & { id?: string }> {
  const today = new Date().toISOString().slice(0, 10)
  const res = await apiRequest(page, 'POST', '/expenses', { document_date: today, ...payload })
  const body = bodyOf(res)
  return { ...res, id: body.id as string | undefined }
}

export async function postExpense(page: Page, id: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/expenses/${id}/post`)
}

export async function payExpense(page: Page, id: string, payload: Record<string, unknown>): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/expenses/${id}/pay`, payload)
}

export async function getExpense(page: Page, id: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/expenses/${id}`)
  return bodyOf(res)
}
