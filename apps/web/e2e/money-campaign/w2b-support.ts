/**
 * MONEY TEST CAMPAIGN — agent W2b (§C `PUR` purchasing, §D `INV` inventory valuation).
 *
 * Support helpers ONLY for W2b's own spec files (purchasing-*.spec.ts, inventory-*.spec.ts).
 * Not `helpers.ts` (shared login owned by a colleague agent) — kept separate to avoid a
 * collision while multiple agents work the same live stack concurrently.
 *
 * Real login + real backend against the LIVE local stack (web :5173 -> api :8010,
 * tenant demo-pharmacy-tn). Every mutation this file performs is scoped to W2b's own
 * fixtures (product/supplier names carry the `W2b-` prefix) so it never touches the
 * precious seeded DEMO-PO-000x / DEMO-TR fixtures or another agent's data.
 *
 * DB reads (queryScalar/queryRow): a handful of persistence-critical numeric columns
 * (DocumentLine.allocated_costs / landed_unit_cost) are written by
 * LandedCostService(bcmath, largest-remainder) but are NOT exposed by the standard
 * GET /purchase-orders/{id} response (DocumentLineData carries no landed-cost fields)
 * and the one endpoint that DOES claim to show them
 * (GET /documents/{id}/landed-cost-breakdown, DocumentAdditionalCostController::
 * landedCostBreakdown()) recomputes from scratch with float math + round(...,2)
 * instead of reading the persisted bcmath columns. Plan §3's evidence rule explicitly
 * allows "the relevant DB row" for fiscal cases — used here for exactly that reason,
 * never as a substitute for the API/UI assertions.
 */
import type { Page } from '@playwright/test'
import { execSync } from 'node:child_process'
import { apiRequest, type ApiResult } from './helpers'

export const PREFIX = 'W2b'

// ---------------------------------------------------------------------------
// Fixed tenant fixture ids (demo-pharmacy-tn, resolved once via psql at authoring
// time — 2026-08-01). Re-resolve if the tenant is reseeded.
// ---------------------------------------------------------------------------
export const TENANT_DB = 'tenant019fbe86-944a-7252-8a3b-8c341dfa9de9'
export const COMPANY_ID = '019fbe86-a6d7-7240-a410-f461453d5e66'
export const WAREHOUSE_LOCATION_ID = 'a38d315f-b457-4bd1-b8de-b83035f90ddb' // WH-01, non-POS
export const SHOP1_LOCATION_ID = 'ee2ec173-3fe6-4114-a24a-8b333da5b28e' // PharmaBio Tunis — Lac
export const SHOP2_LOCATION_ID = '3ad26beb-077a-4424-9221-476a3b18783b' // PharmaBio Tunis — Centre
export const PIECE_UNIT_ID = '019fbe86-a6c8-72e5-bf30-1778f7253fd9' // Piece, decimal_places=0
export const KG_UNIT_ID = '019fbe86-a6c0-72bd-91e2-2b2f9d107dba' // Kilogram, decimal_places=3
export const VAT19_TAX_CONFIG_ID = '019fbe86-a94e-7345-a1d8-19225907bafd' // TVA 19%, LINE_ITEMS
export const OWNER_USER_ID = '42fab470-599b-4167-873d-812c29aaef50' // owner@pharmabio.tn

export function uniq(base: string): string {
  return `${PREFIX}-${base}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`
}

// ---------------------------------------------------------------------------
// Direct DB read — see file header. NOT used for mutation, only for reading
// persistence-critical columns the API/UI layer does not surface.
// ---------------------------------------------------------------------------
export function queryScalar(sql: string): string {
  const out = execSync(
    `PGPASSWORD=autoerp_secret psql -h 127.0.0.1 -p 5433 -U autoerp -d "${TENANT_DB}" -t -A -c "${sql.replace(/"/g, '\\"')}"`,
    { encoding: 'utf-8' }
  )
  return out.trim()
}

// ---------------------------------------------------------------------------
// Catalog / partner fixtures
// ---------------------------------------------------------------------------
export interface CreateProductOpts {
  name: string
  sku: string
  unitId?: string
  requiresBatchTracking?: boolean
}

export async function createProduct(page: Page, opts: CreateProductOpts): Promise<{ id: string; body: Record<string, unknown> }> {
  const res = await apiRequest(page, 'POST', '/products', {
    name: opts.name,
    sku: opts.sku,
    type: 'part',
    unit_id: opts.unitId ?? PIECE_UNIT_ID,
    requires_batch_tracking: opts.requiresBatchTracking ?? false,
  })
  const body = (res.body as { data?: Record<string, unknown> })?.data ?? (res.body as Record<string, unknown>)
  if (res.status !== 201 && res.status !== 200) {
    throw new Error(`createProduct(${opts.sku}) failed: ${res.status} ${JSON.stringify(res.body)}`)
  }
  return { id: body.id as string, body }
}

export async function createSupplier(page: Page, name: string): Promise<string> {
  const res = await apiRequest(page, 'POST', '/partners', {
    name,
    type: 'supplier',
    country_code: 'TN',
  })
  const body = (res.body as { data?: { id: string } })?.data
  if (res.status !== 201 || body === undefined) {
    throw new Error(`createSupplier(${name}) failed: ${res.status} ${JSON.stringify(res.body)}`)
  }
  return body.id
}

// ---------------------------------------------------------------------------
// Purchase orders
// ---------------------------------------------------------------------------
export interface POLineSpec {
  productId: string
  quantity: string
  unitPrice: string
  taxRate?: string
  freeQuantity?: string
  isBonusLine?: boolean
  description?: string
}

export async function createPurchaseOrder(
  page: Page,
  opts: { partnerId: string; lines: POLineSpec[]; locationId?: string }
): Promise<ApiResult & { id?: string }> {
  const today = new Date().toISOString().slice(0, 10)
  const res = await apiRequest(page, 'POST', '/purchase-orders', {
    partner_id: opts.partnerId,
    document_date: today,
    location_id: opts.locationId ?? WAREHOUSE_LOCATION_ID,
    lines: opts.lines.map((l) => ({
      product_id: l.productId,
      description: l.description ?? 'W2b PO line',
      quantity: l.quantity,
      unit_price: l.unitPrice,
      tax_rate: l.taxRate ?? '19.00',
      free_quantity: l.freeQuantity,
      is_bonus_line: l.isBonusLine,
    })),
  })
  const body = (res.body as { data?: Record<string, unknown> })?.data
  return { ...res, id: body?.id as string | undefined }
}

export async function confirmPurchaseOrder(page: Page, id: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/purchase-orders/${id}/confirm`)
}

export async function getPurchaseOrder(page: Page, id: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/purchase-orders/${id}`)
  return ((res.body as { data?: Record<string, unknown> })?.data ?? {}) as Record<string, unknown>
}

export interface ReceiveGoodsOpts {
  quantities?: Record<string, string>
  freeQuantities?: Record<string, string>
  batches?: Record<string, { batch_number: string; expiry_date: string; manufacturing_date?: string }>
  receivedUnitPrices?: Record<string, string>
  priceOverrideReason?: string
  locationId?: string
}

export async function receivePurchaseOrder(page: Page, id: string, opts: ReceiveGoodsOpts = {}): Promise<ApiResult> {
  const payload: Record<string, unknown> = {}
  if (opts.quantities) payload.quantities = opts.quantities
  if (opts.freeQuantities) payload.free_quantities = opts.freeQuantities
  if (opts.batches) payload.batches = opts.batches
  if (opts.receivedUnitPrices) payload.received_unit_prices = opts.receivedUnitPrices
  if (opts.priceOverrideReason) payload.price_override_reason = opts.priceOverrideReason
  if (opts.locationId) payload.location_id = opts.locationId
  return apiRequest(page, 'POST', `/purchase-orders/${id}/receive`, payload)
}

// ---------------------------------------------------------------------------
// Additional costs / landed cost
// ---------------------------------------------------------------------------
export async function addAdditionalCost(
  page: Page,
  documentId: string,
  opts: { costType?: string; description?: string; amount: string }
): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/documents/${documentId}/additional-costs`, {
    cost_type: opts.costType ?? 'shipping',
    description: opts.description ?? 'W2b freight',
    amount: opts.amount,
  })
}

export async function updateAdditionalCost(page: Page, documentId: string, costId: string, amount: string): Promise<ApiResult> {
  return apiRequest(page, 'PATCH', `/documents/${documentId}/additional-costs/${costId}`, { amount })
}

export async function getLandedCostBreakdown(page: Page, documentId: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/documents/${documentId}/landed-cost-breakdown`)
  return ((res.body as { data?: Record<string, unknown> })?.data ?? {}) as Record<string, unknown>
}

// ---------------------------------------------------------------------------
// Procurement policy (match tolerance / enforcement)
// ---------------------------------------------------------------------------
export async function getProcurementPolicy(page: Page): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', '/procurement-policies')
  return ((res.body as { data?: Record<string, unknown> })?.data ?? {}) as Record<string, unknown>
}

export async function setProcurementPolicy(page: Page, patch: Record<string, unknown>): Promise<ApiResult> {
  return apiRequest(page, 'PUT', '/procurement-policies', patch)
}

// ---------------------------------------------------------------------------
// Supplier invoices
// ---------------------------------------------------------------------------
export interface SupplierInvoiceLineSpec {
  sourceLineId?: string
  productId?: string
  quantity: string
  unitPrice: string
  vatRate?: string
  isBonusLine?: boolean
}

export async function createSupplierInvoice(
  page: Page,
  opts: {
    partnerId: string
    sourceDocumentIds: string[]
    currency?: string
    lines: SupplierInvoiceLineSpec[]
  }
): Promise<ApiResult & { id?: string }> {
  const today = new Date().toISOString().slice(0, 10)
  const res = await apiRequest(page, 'POST', '/supplier-invoices', {
    partner_id: opts.partnerId,
    source_document_ids: opts.sourceDocumentIds,
    currency: opts.currency ?? 'TND',
    issue_date: today,
    lines: opts.lines.map((l) => ({
      source_line_id: l.sourceLineId,
      product_id: l.productId,
      quantity: l.quantity,
      unit_price: l.unitPrice,
      vat_rate: l.vatRate ?? '19.00',
      is_bonus_line: l.isBonusLine,
    })),
  })
  const body = (res.body as { data?: Record<string, unknown> })?.data
  return { ...res, id: body?.id as string | undefined }
}

export async function getSupplierInvoice(page: Page, id: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/supplier-invoices/${id}`)
  return ((res.body as { data?: Record<string, unknown> })?.data ?? {}) as Record<string, unknown>
}

export async function postSupplierInvoice(page: Page, id: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/supplier-invoices/${id}/post`)
}

// ---------------------------------------------------------------------------
// Products / stock
// ---------------------------------------------------------------------------
export async function getProduct(page: Page, id: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/products/${id}`)
  return ((res.body as { data?: Record<string, unknown> })?.data ?? {}) as Record<string, unknown>
}

export interface StockLevelRow {
  location_id: string
  quantity: string
}

/**
 * GET /products/{id}/stock-levels (ProductController::stockLevels) does NOT return a
 * bare array — its `data` is `{ locations: StockLevelRow[], totals: {...} }`
 * (apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:1058-1068).
 * Original spec code assumed `data` itself was the array and called `.reduce` on it,
 * which throws `TypeError: levels.reduce is not a function` (spec-side bug, fixed here).
 */
export async function getStockLevels(page: Page, productId: string): Promise<StockLevelRow[]> {
  const res = await apiRequest(page, 'GET', `/products/${productId}/stock-levels`)
  const body = res.body as { data?: { locations?: StockLevelRow[] } }
  return body.data?.locations ?? []
}

export async function getBatchStock(page: Page, productId: string): Promise<Array<Record<string, unknown>>> {
  const res = await apiRequest(page, 'GET', `/products/${productId}/batch-stock`)
  const body = res.body as { data?: Array<Record<string, unknown>> }
  return body.data ?? []
}

// ---------------------------------------------------------------------------
// Opening balance batches (inventory)
// ---------------------------------------------------------------------------
export async function createOpeningBatch(page: Page, opts: { name: string; cutoverDate?: string }): Promise<string> {
  const res = await apiRequest(page, 'POST', `/companies/${COMPANY_ID}/opening-batches`, {
    type: 'INVENTORY',
    name: opts.name,
    cutover_date: opts.cutoverDate ?? new Date().toISOString().slice(0, 10),
  })
  const body = (res.body as { data?: { id: string } })?.data
  if (res.status !== 201 || body === undefined) {
    throw new Error(`createOpeningBatch failed: ${res.status} ${JSON.stringify(res.body)}`)
  }
  return body.id
}

export interface OpeningRow {
  product_code: string
  location_code: string
  quantity: string
  unit_cost: string
}

export async function importOpeningRows(page: Page, batchId: string, rows: OpeningRow[]): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/companies/${COMPANY_ID}/opening-batches/${batchId}/import`, { rows })
}

export async function validateOpeningBatch(page: Page, batchId: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'POST', `/companies/${COMPANY_ID}/opening-batches/${batchId}/validate`)
  return ((res.body as { data?: Record<string, unknown> })?.data ?? {}) as Record<string, unknown>
}

export async function previewOpeningBatch(page: Page, batchId: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/companies/${COMPANY_ID}/opening-batches/${batchId}/preview`)
  return ((res.body as { data?: Record<string, unknown> })?.data ?? {}) as Record<string, unknown>
}

export async function postOpeningBatch(page: Page, batchId: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/companies/${COMPANY_ID}/opening-batches/${batchId}/post`)
}

export async function lockOpeningBatch(page: Page, batchId: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/companies/${COMPANY_ID}/opening-batches/${batchId}/lock`)
}

// ---------------------------------------------------------------------------
// Counting
// ---------------------------------------------------------------------------
export async function createCounting(
  page: Page,
  opts: { productIds: string[]; locationId: string; countUserId?: string }
): Promise<ApiResult & { id?: string }> {
  const res = await apiRequest(page, 'POST', '/inventory/countings', {
    scope_type: 'product_location',
    scope_filters: {
      product_ids: opts.productIds,
      location_id: opts.locationId,
    },
    include_zero_stock: true,
    count_1_user_id: opts.countUserId ?? OWNER_USER_ID,
  })
  const body = (res.body as { data?: Record<string, unknown> })?.data
  return { ...res, id: body?.id as string | undefined }
}

export async function activateCounting(page: Page, countingId: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/inventory/countings/${countingId}/activate`)
}

export interface CountingItemRow {
  id: string
  product: { id: string }
}

export async function getCountingItemsToCount(page: Page, countingId: string): Promise<CountingItemRow[]> {
  const res = await apiRequest(page, 'GET', `/inventory/countings/${countingId}/items/to-count`)
  const body = res.body as { data?: CountingItemRow[] }
  return body.data ?? []
}

export async function submitCount(page: Page, countingId: string, itemId: string, quantity: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/inventory/countings/${countingId}/items/${itemId}/count`, { quantity })
}

export async function getCountingReport(page: Page, countingId: string): Promise<Record<string, unknown>> {
  const res = await apiRequest(page, 'GET', `/inventory/countings/${countingId}/report`)
  return ((res.body as { data?: Record<string, unknown> })?.data ?? {}) as Record<string, unknown>
}

// ---------------------------------------------------------------------------
// Stock transfers
// ---------------------------------------------------------------------------
export async function createStockTransfer(
  page: Page,
  opts: {
    sourceLocationId: string
    destinationLocationId: string
    lines: Array<{ productId: string; quantity: string }>
    transferCost?: string
  }
): Promise<ApiResult & { id?: string }> {
  const res = await apiRequest(page, 'POST', '/stock-transfers', {
    source_location_id: opts.sourceLocationId,
    destination_location_id: opts.destinationLocationId,
    lines: opts.lines.map((l) => ({ product_id: l.productId, quantity: l.quantity })),
    transfer_cost: opts.transferCost,
  })
  const body = (res.body as { data?: Record<string, unknown> })?.data
  return { ...res, id: body?.id as string | undefined }
}

export async function completeStockTransfer(page: Page, id: string): Promise<ApiResult> {
  return apiRequest(page, 'POST', `/stock-transfers/${id}/complete`)
}

// ---------------------------------------------------------------------------
// Composite setup: product + PO + confirm + full receipt, for matching cases
// that need a clean, independent matchable-quantity window per case (the
// matcher consumes `quantity_invoiced` permanently, so cases 04-11 each need
// their OWN PO line rather than sharing MTP-PUR-01's already-invoiced one).
// ---------------------------------------------------------------------------
export async function setupReceivedPoLine(
  page: Page,
  opts: { supplierId: string; quantity: string; unitPrice: string; skuBase: string }
): Promise<{ poId: string; lineId: string; productId: string }> {
  const { id: productId } = await createProduct(page, { name: uniq(opts.skuBase), sku: uniq(opts.skuBase) })
  const poRes = await createPurchaseOrder(page, {
    partnerId: opts.supplierId,
    lines: [{ productId, quantity: opts.quantity, unitPrice: opts.unitPrice }],
  })
  if (poRes.id === undefined) {
    throw new Error(`setupReceivedPoLine: PO create failed: ${poRes.status} ${JSON.stringify(poRes.body)}`)
  }
  const confirmRes = await confirmPurchaseOrder(page, poRes.id)
  if (confirmRes.status !== 200 && confirmRes.status !== 201) {
    throw new Error(`setupReceivedPoLine: PO confirm failed: ${confirmRes.status} ${JSON.stringify(confirmRes.body)}`)
  }
  const po = await getPurchaseOrder(page, poRes.id)
  const lines = po.lines as Array<{ id: string }>
  const lineId = lines[0].id
  const receiveRes = await receivePurchaseOrder(page, poRes.id, { quantities: { [lineId]: opts.quantity } })
  if (receiveRes.status !== 200 && receiveRes.status !== 201) {
    throw new Error(`setupReceivedPoLine: receive failed: ${receiveRes.status} ${JSON.stringify(receiveRes.body)}`)
  }
  return { poId: poRes.id, lineId, productId }
}

// ---------------------------------------------------------------------------
// Batch write-off (expiry)
// ---------------------------------------------------------------------------
export async function writeOffGrouped(
  page: Page,
  opts: { locationId: string; lines: Array<{ batchId: string; quantity: string }>; reason?: string }
): Promise<ApiResult> {
  return apiRequest(page, 'POST', '/batches/write-off-grouped', {
    location_id: opts.locationId,
    lines: opts.lines.map((l) => ({ batch_id: l.batchId, quantity: l.quantity })),
    reason: opts.reason ?? 'expiry',
    idempotency_key: uniq('writeoff'),
  })
}
