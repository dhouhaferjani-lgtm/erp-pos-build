/**
 * MONEY TEST CAMPAIGN — wave W-4 (purchasing + inventory).
 *
 * Additive helpers ONLY. Everything W2a already built (`w2b-support.ts`) is REUSED,
 * not rebuilt: product/supplier creation, purchase orders, receipts, supplier invoices,
 * additional costs, opening batches (INVENTORY), counting, stock transfers, batch
 * write-off, and the `queryScalar` DB reader.
 *
 * This file adds only the surfaces W-4 introduces:
 *   - purchase quote requests (RFQ)                -> /purchase-quote-requests*
 *   - standalone goods receipts                    -> /goods-receipts/standalone
 *   - replenishment queue/capture/fulfilment (REP) -> /replenishment-requests*
 *   - generic opening batches for non-INVENTORY types (OPB)
 *   - stock matrix / entry-exit notes / stock movements readers
 *   - partner payable balance reader
 *
 * House money discipline (CLAUDE.md rule 19 / plan §0.2): every money comparison in
 * W-4 specs is an EXACT DECIMAL STRING. Where a case needs arithmetic over money it
 * uses `addMoney`/`subMoney`/`mulMoneyQty` below, which are BigInt-in-millimes — never
 * float. No `toBeCloseTo` anywhere in this wave.
 *
 * DB reads: a handful of persistence-critical columns are not exposed by any API
 * response, or are exposed only through a float-recomputing endpoint
 * (`GET /documents/{id}/landed-cost-breakdown` returns JSON numbers, not the
 * persisted bcmath strings — see w2b-support.ts header). Plan §3 explicitly allows
 * "the relevant DB row" as evidence for fiscal cases; used here for exactly that,
 * never as a substitute for an available API assertion.
 */
import type { Page } from '@playwright/test'
import { execSync } from 'node:child_process'
import { apiRequest, type ApiResult } from './helpers'
import { COMPANY_ID, PIECE_UNIT_ID, TENANT_DB, WAREHOUSE_LOCATION_ID } from './w2b-support'

export const W4_PREFIX = 'W4'

export function uniq4(base: string): string {
  return `${W4_PREFIX}-${base}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`
}

export function today(): string {
  return new Date().toISOString().slice(0, 10)
}

export function daysFromToday(days: number): string {
  const d = new Date()
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

// ---------------------------------------------------------------------------
// Exact money arithmetic (BigInt millimes — TND scale 3). Same contract as
// treasury-support.ts's addMoney; duplicated here so the purchasing/inventory
// specs do not import the treasury session harness (different auth model).
// ---------------------------------------------------------------------------
const MONEY_SCALE = 3

function toMillimes(v: string): bigint {
  const neg = v.trim().startsWith('-')
  const [intPart, fracRaw = ''] = v.trim().replace(/^-/, '').split('.')
  const frac = (fracRaw + '000').slice(0, MONEY_SCALE)
  const n = BigInt(intPart) * 1000n + BigInt(frac)
  return neg ? -n : n
}

function fromMillimes(n: bigint): string {
  const neg = n < 0n
  const abs = neg ? -n : n
  const int = abs / 1000n
  const frac = (abs % 1000n).toString().padStart(MONEY_SCALE, '0')
  return `${neg ? '-' : ''}${int}.${frac}`
}

export function addMoney(a: string, b: string): string {
  return fromMillimes(toMillimes(a) + toMillimes(b))
}

export function subMoney(a: string, b: string): string {
  return fromMillimes(toMillimes(a) - toMillimes(b))
}

export function sumMoney(values: string[]): string {
  return values.reduce((acc, v) => addMoney(acc, v), '0.000')
}

/**
 * qty (scale 4, may be negative) x unit money (scale >= 3), TRUNCATED toward zero
 * at money scale 3 — the `bcformatStrict` contract (CurrencyScale::bcformatStrict
 * truncates; it does NOT round half-up). Used to predict variance values.
 */
export function mulQtyMoneyTrunc(qty: string, unit: string, unitScale: number): string {
  const qNeg = qty.trim().startsWith('-')
  const [qi, qfRaw = ''] = qty.trim().replace(/^-/, '').split('.')
  const qf = (qfRaw + '0000').slice(0, 4)
  const qScaled = BigInt(qi) * 10000n + BigInt(qf) // scale 4

  const uNeg = unit.trim().startsWith('-')
  const [ui, ufRaw = ''] = unit.trim().replace(/^-/, '').split('.')
  const uf = (ufRaw + '0'.repeat(unitScale)).slice(0, unitScale)
  const uScaled = BigInt(ui) * 10n ** BigInt(unitScale) + BigInt(uf || '0')

  // product is at scale (4 + unitScale); truncate toward zero to scale 3
  const prod = qScaled * uScaled
  const drop = 10n ** BigInt(4 + unitScale - MONEY_SCALE)
  const truncated = prod / drop // BigInt division truncates toward zero for positives
  const signed = qNeg !== uNeg ? -truncated : truncated
  return fromMillimes(signed)
}

// ---------------------------------------------------------------------------
// Direct DB read — see file header.
// ---------------------------------------------------------------------------
export function queryRows(sql: string): string[][] {
  const out = execSync(
    `PGPASSWORD=autoerp_secret psql -h 127.0.0.1 -p 5433 -U autoerp -d "${TENANT_DB}" -t -A -F'|' -c "${sql.replace(/"/g, '\\"')}"`,
    { encoding: 'utf-8' }
  )
  return out
    .trim()
    .split('\n')
    .filter((l) => l.length > 0)
    .map((l) => l.split('|'))
}

export function queryOne(sql: string): string {
  const rows = queryRows(sql)
  return rows.length > 0 ? rows[0][0] : ''
}

/** Persisted (bcmath) landed-cost columns for a PO, ordered by line_number. */
export function persistedLandedCosts(documentId: string): Array<{
  lineNumber: string
  lineTotal: string
  allocatedCosts: string
  landedUnitCost: string
  nonRecoverableTax: string
}> {
  return queryRows(
    `select line_number, line_total, allocated_costs, landed_unit_cost, non_recoverable_tax from document_lines where document_id='${documentId}' order by line_number`
  ).map((r) => ({
    lineNumber: r[0],
    lineTotal: r[1],
    allocatedCosts: r[2],
    landedUnitCost: r[3],
    nonRecoverableTax: r[4],
  }))
}

// ---------------------------------------------------------------------------
// Products — W-4 creates a DEDICATED product per costing case (fixture discipline:
// WAC mutations PERSIST, so a seeded pharmacy product must never be used).
// The parapharmacy vertical defaults products to requires_batch_tracking=true;
// costing cases pass false explicitly so a plain receive does not 422 on
// "Batch data is required".
// ---------------------------------------------------------------------------
export async function createW4Product(
  page: Page,
  base: string,
  opts: { unitId?: string; requiresBatchTracking?: boolean } = {}
): Promise<{ id: string; sku: string }> {
  const sku = uniq4(base)
  const res = await apiRequest(page, 'POST', '/products', {
    name: sku,
    sku,
    type: 'part',
    unit_id: opts.unitId ?? PIECE_UNIT_ID,
    requires_batch_tracking: opts.requiresBatchTracking ?? false,
  })
  if (res.status !== 201 && res.status !== 200) {
    throw new Error(`createW4Product(${base}) failed: ${res.status} ${JSON.stringify(res.body)}`)
  }
  const body = (res.body as { data: { id: string } }).data
  return { id: body.id, sku }
}

/** Current perpetual WAC at rest (6 dp) as the API reports it. */
export async function costPrice(page: Page, productId: string): Promise<string> {
  const res = await apiRequest(page, 'GET', `/products/${productId}`)
  return (res.body as { data: { cost_price: string } }).data.cost_price
}

// ---------------------------------------------------------------------------
// PO -> receipt convenience (one PO per receipt so WAC blends are deterministic)
// ---------------------------------------------------------------------------
export interface ReceiveOnceOpts {
  supplierId: string
  productId: string
  quantity: string
  unitPrice: string
  locationId?: string
  freeQuantity?: string
  receivedUnitPrice?: string
  priceOverrideReason?: string
  taxRate?: string
  additionalCost?: string
  skipReceive?: boolean
}

export interface ReceiveOnceResult {
  poId: string
  lineId: string
  confirmStatus: number
  receiveStatus: number
  receiveBody: unknown
}

export async function poAndReceive(page: Page, opts: ReceiveOnceOpts): Promise<ReceiveOnceResult> {
  const poRes = await apiRequest(page, 'POST', '/purchase-orders', {
    partner_id: opts.supplierId,
    document_date: today(),
    location_id: opts.locationId ?? WAREHOUSE_LOCATION_ID,
    lines: [
      {
        product_id: opts.productId,
        description: 'W4 line',
        quantity: opts.quantity,
        unit_price: opts.unitPrice,
        tax_rate: opts.taxRate ?? '19.00',
        free_quantity: opts.freeQuantity,
      },
    ],
  })
  if (poRes.status !== 201) {
    throw new Error(`poAndReceive: PO create ${poRes.status} ${JSON.stringify(poRes.body)}`)
  }
  const poId = (poRes.body as { data: { id: string } }).data.id

  if (opts.additionalCost !== undefined) {
    // Landed costs are allocated at CONFIRM (PurchaseOrderService::confirmAndAllocateCosts
    // -> LandedCostService::allocateCostsAndTaxes) and re-allocated at receipt
    // (GoodsReceiptService -> reallocateCosts). Adding the cost AFTER confirm leaves
    // allocated_costs at 0 until a receipt runs — so add it first.
    const addRes = await apiRequest(page, 'POST', `/documents/${poId}/additional-costs`, {
      cost_type: 'shipping',
      description: 'W4 freight',
      amount: opts.additionalCost,
    })
    if (addRes.status !== 201) {
      throw new Error(`poAndReceive: additional-cost ${addRes.status} ${JSON.stringify(addRes.body)}`)
    }
  }

  const confirmRes = await apiRequest(page, 'POST', `/purchase-orders/${poId}/confirm`)
  const po = await apiRequest(page, 'GET', `/purchase-orders/${poId}`)
  const lineId = (po.body as { data: { lines: Array<{ id: string }> } }).data.lines[0].id

  if (opts.skipReceive === true) {
    return { poId, lineId, confirmStatus: confirmRes.status, receiveStatus: 0, receiveBody: null }
  }

  const payload: Record<string, unknown> = { quantities: { [lineId]: opts.quantity } }
  if (opts.freeQuantity !== undefined) payload.free_quantities = { [lineId]: opts.freeQuantity }
  if (opts.receivedUnitPrice !== undefined) payload.received_unit_prices = { [lineId]: opts.receivedUnitPrice }
  if (opts.priceOverrideReason !== undefined) payload.price_override_reason = opts.priceOverrideReason
  const recRes = await apiRequest(page, 'POST', `/purchase-orders/${poId}/receive`, payload)

  return { poId, lineId, confirmStatus: confirmRes.status, receiveStatus: recRes.status, receiveBody: recRes.body }
}

// ---------------------------------------------------------------------------
// Standalone goods receipt (PUR-29/30)
// ---------------------------------------------------------------------------
export interface StandaloneLine {
  product_id: string
  qty: string
  unit_price: string
  free_qty?: string
}

export async function standaloneReceipt(
  page: Page,
  opts: {
    supplierId: string
    locationId?: string
    lines: StandaloneLine[]
    idempotencyKey?: string
    postImmediately?: boolean
    externalReference?: string
  }
): Promise<ApiResult> {
  return apiRequest(page, 'POST', '/goods-receipts/standalone', {
    supplier_id: opts.supplierId,
    location_id: opts.locationId ?? WAREHOUSE_LOCATION_ID,
    idempotency_key: (opts.idempotencyKey ?? uniq4('idem')).slice(0, 64),
    external_reference: opts.externalReference,
    post_immediately: opts.postImmediately ?? true,
    lines: opts.lines,
  })
}

// ---------------------------------------------------------------------------
// RFQ — purchase quote requests
// ---------------------------------------------------------------------------
export interface RfqLineSpec {
  product_id: string
  quantity: string
  unit_price?: string
  description?: string
}

export async function createRfq(
  page: Page,
  opts: { partnerIds: string[]; lines: RfqLineSpec[]; validityDate?: string; notes?: string }
): Promise<ApiResult> {
  return apiRequest(page, 'POST', '/purchase-quote-requests', {
    partner_ids: opts.partnerIds,
    lines: opts.lines,
    validity_date: opts.validityDate,
    notes: opts.notes,
  })
}

export async function getRfq(page: Page, id: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/purchase-quote-requests/${id}`)
  return ((res.body as { data?: Record<string, unknown> })?.data ?? {}) as Record<string, unknown>
}

export async function getRfqGroup(page: Page, groupId: string): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/purchase-quote-requests/groups/${groupId}`)
}

export async function quoteRfq(
  page: Page,
  id: string,
  opts: { lines: Array<{ id?: string; product_id: string; quantity: string; unit_price?: string }>; supplierReference?: string; leadTimeDays?: number }
): Promise<ApiResult> {
  return apiRequest(page, 'PUT', `/purchase-quote-requests/${id}`, {
    lines: opts.lines,
    supplier_reference: opts.supplierReference,
    lead_time_days: opts.leadTimeDays,
  })
}

export async function sendRfq(page: Page, id: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/purchase-quote-requests/${id}/send`)
}

export async function awardRfq(page: Page, id: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/purchase-quote-requests/${id}/convert-to-po`)
}

export async function reopenRfqGroup(page: Page, groupId: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/purchase-quote-requests/groups/${groupId}/reopen`)
}

// ---------------------------------------------------------------------------
// Replenishment (REP)
// ---------------------------------------------------------------------------
export async function captureReplenishment(
  page: Page,
  opts: { locationId: string; productId: string; requestedQty?: string; note?: string }
): Promise<ApiResult> {
  return apiRequest(page, 'POST', '/replenishment-requests', {
    location_id: opts.locationId,
    product_id: opts.productId,
    requested_qty: opts.requestedQty,
    note: opts.note,
  })
}

export async function listReplenishment(page: Page, query = ''): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/replenishment-requests${query}`)
}

export async function replenishmentCreatePo(
  page: Page,
  opts: { supplierId: string; destinationLocationId: string; lines: Array<{ request_id: string; quantity: string }>; existingDocumentId?: string }
): Promise<ApiResult> {
  return apiRequest(page, 'POST', '/replenishment-requests/actions/create-po', {
    supplier_id: opts.supplierId,
    destination_location_id: opts.destinationLocationId,
    existing_document_id: opts.existingDocumentId,
    lines: opts.lines,
  })
}

export async function replenishmentReject(page: Page, requestIds: string[], reason?: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', '/replenishment-requests/actions/reject', {
    request_ids: requestIds,
    reason,
  })
}

export async function cancelReplenishment(page: Page, id: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/replenishment-requests/${id}/cancel`)
}

// ---------------------------------------------------------------------------
// Opening balance batches — generic (all four types). The INVENTORY-only
// helpers live in w2b-support.ts; these take an explicit type so OPB-01..06
// can drive ACCOUNTING / AR_OPEN_ITEMS / AP_OPEN_ITEMS.
// ---------------------------------------------------------------------------
export type OpeningBatchType = 'ACCOUNTING' | 'INVENTORY' | 'AR_OPEN_ITEMS' | 'AP_OPEN_ITEMS'

/**
 * Deterministic, W-4-owned GL accounts for the ACCOUNTING opening cases.
 *
 * Review finding I-7: picking `postable[0]` / `postable[1]` out of the chart of accounts
 * is order-dependent, so the wave's GL amounts would land on whichever real accounts
 * happen to sort first — unassertable by W-6 and a pollution of the tenant's own ledger.
 * These two accounts are created once (idempotent) and are the ONLY accounts W-4 posts
 * opening balances to, so W-6 can assert per-account.
 */
export const W4_GL_DEBIT_ACCOUNT_CODE = 'W4GL1'
export const W4_GL_CREDIT_ACCOUNT_CODE = 'W4GL2'

export async function ensureAccount(
  page: Page,
  opts: { code: string; name: string; type: string }
): Promise<{ id: string; code: string }> {
  const existing = await apiRequest(page, 'GET', `/accounts?per_page=500`)
  const found = ((existing.body as { data?: Array<{ id: string; code: string }> }).data ?? []).find((a) => a.code === opts.code)
  if (found !== undefined) return { id: found.id, code: found.code }

  const res = await apiRequest(page, 'POST', '/accounts', {
    code: opts.code,
    name: opts.name,
    type: opts.type,
    is_active: true,
  })
  if (res.status !== 201) {
    throw new Error(`ensureAccount(${opts.code}) failed: ${res.status} ${JSON.stringify(res.body)}`)
  }
  const body = (res.body as { data: { id: string; code: string } }).data
  return { id: body.id, code: body.code }
}

export async function openingBatchTypes(page: Page): Promise<ApiResult> {
  return apiRequest(page, 'GET', '/opening-batches/types')
}

/**
 * The company may hold at most ONE *unlocked* batch per type
 * (OpeningBalanceBatchService::createBatch():62-72 — `unlocked()` means status != LOCKED,
 * so a DRAFT **and** an already-POSTED-but-VALIDATED batch both occupy the slot). The
 * wizard's own lock step is what frees it. Sequential campaign cases therefore have to
 * clear the slot before creating: DRAFT batches are deletable, VALIDATED ones are not —
 * they must be LOCKED (which is the wizard's terminal step anyway, and never un-posts
 * anything).
 *
 * SAFETY (review finding I-4): this only ever touches batches THIS WAVE created, i.e.
 * whose `name` carries the `W4-` prefix. A foreign unlocked batch — another wave's, or
 * one the owner left mid-wizard — must NOT be destroyed to make room for a test; deleting
 * a stranger's draft opening balance would silently discard real work, and locking one
 * would seal it prematurely. When a foreign batch holds the slot this returns
 * `{ blockedBy }` and the caller records a BLOCKED verdict instead of proceeding.
 */
export interface SlotClearResult {
  cleared: string[]
  blockedBy: Array<{ id: string; name: string; status: string }>
}

export async function clearOpeningBatchSlot(page: Page, type: OpeningBatchType): Promise<SlotClearResult> {
  const list = await apiRequest(page, 'GET', `/companies/${COMPANY_ID}/opening-batches?type=${type}`)
  const rows = ((list.body as { data?: Array<{ id: string; type: string; status: string; name: string }> }).data ?? []).filter(
    (b) => b.type === type && b.status !== 'LOCKED'
  )
  const cleared: string[] = []
  const blockedBy: Array<{ id: string; name: string; status: string }> = []
  for (const b of rows) {
    if (!(b.name ?? '').startsWith(`${W4_PREFIX}-`)) {
      blockedBy.push({ id: b.id, name: b.name, status: b.status })
      continue
    }
    const res = b.status === 'DRAFT' ? await deleteOpening(page, b.id) : await lockOpening(page, b.id)
    if (res.status < 300) cleared.push(b.id)
  }
  return { cleared, blockedBy }
}

/**
 * Clear the slot and fail loudly (with the foreign batch named) rather than destroying
 * someone else's work. Callers use this before every `createOpeningBatchOfType`.
 */
export async function requireOpeningBatchSlot(page: Page, type: OpeningBatchType): Promise<SlotClearResult> {
  const res = await clearOpeningBatchSlot(page, type)
  if (res.blockedBy.length > 0) {
    throw new Error(
      `BLOCKED: the ${type} opening-batch slot is held by a batch this wave did not create — ` +
        `${res.blockedBy.map((b) => `${b.name} (${b.status}, ${b.id})`).join(', ')}. ` +
        'Refusing to delete or lock a foreign batch; resolve it manually and re-run.'
    )
  }
  return res
}

/**
 * Retire a batch a case created but must not leave behind: DRAFT rows are DELETED
 * (they would otherwise show up in every later opening-balance list read); VALIDATED
 * rows are not deletable by design, so they are LOCKED — the wizard's own terminal step,
 * which neither posts nor un-posts anything. Returns the status of whichever call ran so
 * the caller can assert a <300 cleanup per the campaign's finally-block discipline.
 */
export async function retireOpeningBatch(page: Page, batchId: string): Promise<number> {
  const del = await deleteOpening(page, batchId)
  if (del.status < 300) return del.status
  const lock = await lockOpening(page, batchId)
  if (lock.status < 300) return lock.status
  // Neither path applied — the batch may already be gone (a previous slot-clear) or
  // already LOCKED, both of which mean "retired". Re-read before reporting failure so a
  // cleanup assertion cannot fail on an already-clean slot.
  const show = await apiRequest(page, 'GET', `/companies/${COMPANY_ID}/opening-batches/${batchId}`)
  if (show.status === 404) return 200
  const status = (show.body as { data?: { status?: string } })?.data?.status
  if (status === 'LOCKED') return 200
  return lock.status
}

export async function createOpeningBatchOfType(
  page: Page,
  type: OpeningBatchType,
  opts: { name: string; cutoverDate?: string } = { name: 'W4 opening' }
): Promise<ApiResult & { id?: string }> {
  const res = await apiRequest(page, 'POST', `/companies/${COMPANY_ID}/opening-batches`, {
    type,
    name: opts.name,
    cutover_date: opts.cutoverDate ?? today(),
  })
  const body = (res.body as { data?: { id?: string } })?.data
  return { ...res, id: body?.id }
}

export async function importOpeningRowsGeneric(
  page: Page,
  batchId: string,
  rows: Array<Record<string, unknown>>
): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/companies/${COMPANY_ID}/opening-batches/${batchId}/import`, { rows })
}

export async function validateOpening(page: Page, batchId: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/companies/${COMPANY_ID}/opening-batches/${batchId}/validate`)
}

export async function previewOpening(page: Page, batchId: string): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/companies/${COMPANY_ID}/opening-batches/${batchId}/preview`)
}

export async function postOpening(page: Page, batchId: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/companies/${COMPANY_ID}/opening-batches/${batchId}/post`)
}

export async function lockOpening(page: Page, batchId: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/companies/${COMPANY_ID}/opening-batches/${batchId}/lock`)
}

export async function deleteOpening(page: Page, batchId: string): Promise<ApiResult> {
  return apiRequest(page, 'DELETE', `/companies/${COMPANY_ID}/opening-batches/${batchId}`)
}

export async function openingRows(page: Page, batchId: string): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/companies/${COMPANY_ID}/opening-batches/${batchId}/rows`)
}

export async function openingStatus(page: Page): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/companies/${COMPANY_ID}/opening-batches/status`)
}

// ---------------------------------------------------------------------------
// Inventory readers
// ---------------------------------------------------------------------------
export async function stockMatrix(page: Page, query = ''): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/inventory/stock-matrix${query}`)
}

/**
 * Drive a product's on-hand at a location to an exact target, through the
 * `stock_adjustments` DOCUMENT.
 *
 * Rewritten by DPA V7 / T12. The old premise — "the only web path that REMOVES
 * company-owned quantity without a document or a batch lot" — is precisely what
 * V7 abolishes: `POST /stock-movements/adjust` is deleted, and every manual stock
 * mutation now carries its own justifying document.
 *
 * Three things this helper must get right, all of them consequences of the
 * absolute -> DELTA change:
 *
 *  1. The delta is computed from a FRESH `GET /stock-levels/{product}/{location}`
 *     read, not from a remembered value — that read is also what authors
 *     `observed_before`, so the staleness guard is satisfied by construction.
 *  2. `delta = target - fresh_before` is ZERO when the product is already at the
 *     target, which the new contract refuses TWICE (`not_in:0` plus the
 *     `stock_adjustment_lines_delta_nonzero` CHECK). The helper exists to drive a
 *     product to exactly 0, so it returns early in that case rather than 422ing.
 *  3. `reason_code` is DERIVED from the sign of the computed delta. A hardcoded
 *     `adjustment_negative` passes only when the target happens to be below
 *     current; the reason<->sign invariant 422s otherwise.
 *
 * `acknowledge_stale` is sent because the campaign runs concurrent flows against
 * the same products; the fresh read above makes it a belt, not the braces.
 */
export async function adjustStockTo(
  page: Page,
  opts: { productId: string; locationId?: string; newQuantity: string; reason?: string }
): Promise<ApiResult> {
  const locationId = opts.locationId ?? WAREHOUSE_LOCATION_ID

  const level = await apiRequest(page, 'GET', `/stock-levels/${opts.productId}/${locationId}`)
  const before = String((level.body as { data?: { quantity?: string } })?.data?.quantity ?? '0')

  const delta = subtractQuantity(opts.newQuantity, before)

  // Already at target: there is no correction to document.
  //
  // Compared as a STRING, not via Number(): subtractQuantity() always emits a
  // canonical 4-dp decimal, so '0.0000' is the only zero it can produce, and a
  // float round-trip on a quantity is the rule-19 breach this whole lane exists
  // to remove (gate code-review M-1).
  if (delta === '0.0000') {
    return level
  }

  return apiRequest(page, 'POST', '/stock-adjustments', {
    location_id: locationId,
    note: opts.reason ?? 'W4 campaign adjustment',
    post_immediately: true,
    acknowledge_stale: true,
    lines: [
      {
        product_id: opts.productId,
        reason_code: delta.startsWith('-') ? 'adjustment_negative' : 'adjustment_positive',
        delta_quantity: delta,
        observed_before: before,
      },
    ],
  })
}

/**
 * `a - b` at the canonical quantity scale, as a STRING.
 *
 * Deliberately not `Number(a) - Number(b)`: quantities are `decimal(15,4)` and
 * the payload must carry a decimal string, never a float round-trip (rule 19).
 */
function subtractQuantity(a: string, b: string): string {
  const scale = 4
  const toScaledInt = (value: string): bigint => {
    const negative = value.trim().startsWith('-')
    const [whole, fraction = ''] = value.trim().replace('-', '').split('.')
    const padded = (fraction + '0'.repeat(scale)).slice(0, scale)
    const magnitude = BigInt(whole || '0') * BigInt(10 ** scale) + BigInt(padded || '0')
    return negative ? -magnitude : magnitude
  }

  const diff = toScaledInt(a) - toScaledInt(b)
  const sign = diff < 0n ? '-' : ''
  const magnitude = (diff < 0n ? -diff : diff).toString().padStart(scale + 1, '0')

  return `${sign}${magnitude.slice(0, -scale)}.${magnitude.slice(-scale)}`
}

export async function stockMovements(page: Page, query = ''): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/stock-movements${query}`)
}

export async function entryExitNotes(page: Page, query = ''): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/entry-exit-notes${query}`)
}

export async function partnerBalance(page: Page, partnerId: string): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/companies/${COMPANY_ID}/partners/${partnerId}/balance`)
}

export async function refreshPartnerBalance(page: Page, partnerId: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/companies/${COMPANY_ID}/partners/${partnerId}/balance/refresh`)
}

// ---------------------------------------------------------------------------
// Counting extras (the "apply" step is `finalize`; there is no /apply route).
// ---------------------------------------------------------------------------
export async function finalizeCounting(page: Page, countingId: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/inventory/countings/${countingId}/finalize`)
}

export async function getCounting(page: Page, countingId: string): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/inventory/countings/${countingId}`)
}

/** `reason` is REQUIRED by CancelCountingRequest — omitting it 422s. */
export async function cancelCounting(page: Page, countingId: string, reason = 'W4 campaign cleanup'): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/inventory/countings/${countingId}/cancel`, { reason })
}

export async function setOpeningCost(page: Page, countingId: string, itemId: string, unitCost: string): Promise<ApiResult> {
  return apiRequest(page, 'PATCH', `/inventory/countings/${countingId}/items/${itemId}/opening-cost`, {
    opening_unit_cost: unitCost,
  })
}

// ---------------------------------------------------------------------------
// Batches / expiry
// ---------------------------------------------------------------------------
export async function listBatches(page: Page, query = ''): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/batches${query}`)
}

export async function batchStock(page: Page, batchUuid: string): Promise<ApiResult> {
  return apiRequest(page, 'GET', `/batches/${batchUuid}/stock`)
}
