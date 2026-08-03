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

export async function openingBatchTypes(page: Page): Promise<ApiResult> {
  return apiRequest(page, 'GET', '/opening-batches/types')
}

/**
 * The company may hold at most ONE *unlocked* batch per type
 * (OpeningBalanceBatchService::createBatch():61-72 — `unlocked()` means status != LOCKED,
 * so a DRAFT **and** an already-POSTED-but-VALIDATED batch both occupy the slot). The
 * wizard's own lock step is what frees it. Sequential campaign cases therefore have to
 * clear the slot before creating: DRAFT batches are deletable, VALIDATED ones are not —
 * they must be LOCKED (which is the wizard's terminal step anyway, and never un-posts
 * anything).
 *
 * Returns the ids it cleared, so a caller can record what it touched.
 */
export async function clearOpeningBatchSlot(page: Page, type: OpeningBatchType): Promise<string[]> {
  const list = await apiRequest(page, 'GET', `/companies/${COMPANY_ID}/opening-batches?type=${type}`)
  const rows = ((list.body as { data?: Array<{ id: string; type: string; status: string }> }).data ?? []).filter(
    (b) => b.type === type && b.status !== 'LOCKED'
  )
  const cleared: string[] = []
  for (const b of rows) {
    const res = b.status === 'DRAFT' ? await deleteOpening(page, b.id) : await lockOpening(page, b.id)
    if (res.status < 300) cleared.push(b.id)
  }
  return cleared
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
