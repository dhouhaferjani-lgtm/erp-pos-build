/**
 * Interim TypeScript interfaces for Supplier Invoice API.
 *
 * CONTRACT-FIRST: These types match the API contract in
 * docs/superpowers/coordination/2026-06-26-supplier-invoice-api-contract.md
 *
 * SWAP POINT: When backend DTOs are generated via `php artisan typescript:transform`,
 * replace this entire file with an import from `packages/shared/types/`.
 * All consumers import from this file so only this file changes.
 */

// ── Enums ──────────────────────────────────────────────────────────────────

export type SupplierInvoiceStatus = 'draft' | 'posted' | 'paid'

export type SupplierInvoiceMatchStatus =
  | 'matched'
  | 'price_variance'
  | 'qty_blocked'
  | 'unmatched'

export type AttachmentRole = 'source_document' | 'other'

// ── List ───────────────────────────────────────────────────────────────────

export interface SupplierInvoicePartnerRef {
  id: string
  name: string
}

/** Item returned in the paginated list. All money as strings. */
export interface SupplierInvoiceListItem {
  id: string
  number: string
  partner: SupplierInvoicePartnerRef
  issue_date: string
  currency: string
  /** String — precision contract */
  total: string
  status: SupplierInvoiceStatus
  match_status: SupplierInvoiceMatchStatus
  has_source_document: boolean
  pending_receipt?: boolean
}

/** Cursor-based pagination meta (matches backend cursorPaginate). */
export interface SupplierInvoiceListMeta {
  per_page: number
  has_more: boolean
}

export interface SupplierInvoiceListResponse {
  data: SupplierInvoiceListItem[]
  meta: SupplierInvoiceListMeta
  links: {
    next: string | null
    prev: string | null
  }
}

// ── Detail ─────────────────────────────────────────────────────────────────

export interface SupplierInvoiceLine {
  id: string
  source_line_id: string | null
  product_id?: string | null
  variant_id?: string | null
  quantity: string
  unit_price: string
  vat_rate: string
  recoverable_tax_amount: string
  line_subtotal: string
}

export interface SourcePurchaseOrder {
  id: string
  number: string
}

export interface ConsumedReceipt {
  id: string
  receipt_number: string | null
  status: string
  received_at: string | null
  external_reference: string | null
}

export interface PerLineMatch {
  po_line_id: string
  ordered: string
  received: string
  invoiced: string
  matchable: string
  /** Boolean flag — true when invoiced unit_price vs PO unit_price exceeds tolerance policy. */
  price_variance: boolean
}

export interface InvoiceMatch {
  status: SupplierInvoiceMatchStatus
  per_line: PerLineMatch[]
}

export interface DocumentAttachment {
  id: string
  filename: string
  mime_type: string
  size: number
  role: AttachmentRole
  created_at: string
}

/** Full detail shape returned by GET /supplier-invoices/{id} and POST actions. */
export interface SupplierInvoiceDetail {
  id: string
  number: string
  partner: SupplierInvoicePartnerRef
  issue_date: string
  due_date: string | null
  /** Maps to external_document_number column via supplier_reference key. */
  supplier_reference: string | null
  currency: string
  balance_due?: string | null
  total: string
  status: SupplierInvoiceStatus
  match_status: SupplierInvoiceMatchStatus
  /** Present in detail response (not has_source_document — use source_purchase_order instead). */
  source_document_id: string | null
  pending_receipt?: boolean
  lines: SupplierInvoiceLine[]
  source_purchase_order: SourcePurchaseOrder | null
  source_purchase_orders?: SourcePurchaseOrder[]
  consumed_receipts?: ConsumedReceipt[]
  match: InvoiceMatch
  attachments: DocumentAttachment[]
  posted_at: string | null
  /** Conditionally present on POST /post when price_variance under warn enforcement. */
  warning?: string
}

// ── Create payload ─────────────────────────────────────────────────────────

export interface CreateSupplierInvoiceLinePayload {
  source_line_id?: string
  product_id?: string
  variant_id?: string | null
  /** qty scale: max 4 decimal places as string */
  quantity: string
  /** money scale: max 3 decimal places as string */
  unit_price: string
  /** percent scale: max 2 decimal places as string */
  vat_rate: string
  batch?: {
    batch_number: string
    expiry_date: string
    manufacturing_date?: string
  }
}

export interface CreateSupplierInvoicePayload {
  partner_id: string
  source_document_id?: string
  source_document_ids?: string[]
  currency: string
  issue_date: string
  due_date?: string
  supplier_reference?: string
  notes?: string
  pending_receipt?: boolean
  invoice_first_delivered?: boolean
  location_id?: string
  idempotency_key?: string
  external_reference?: string
  external_date?: string
  lines: CreateSupplierInvoiceLinePayload[]
}

export interface LinkSupplierInvoiceReceiptLinePayload {
  invoice_line_id: string
  receipt_line_id: string
}

export interface LinkSupplierInvoiceReceiptsPayload {
  links: LinkSupplierInvoiceReceiptLinePayload[]
}

// ── 422 error shape for blocked post ──────────────────────────────────────

export interface PostBlockError {
  block: 'qty_blocked' | 'price_block'
  message: string
}

// ── Payment payload (reuses existing POST /payments endpoint) ──────────────

export interface RecordPaymentPayload {
  document_id?: string
  amount: string
  currency: string
  payment_method_id: string
  repository_id?: string
  partner_id?: string
  payment_date: string
  reference?: string
  notes?: string
  allocations?: {
    document_id: string
    amount: string
  }[]
}

// ── Create prefill reads ──────────────────────────────────────────────────

export interface PurchaseOrderReceiptLine {
  id: string
  receipt_number: string
  product_id: string
  variant_id: string | null
  received_qty: string
  free_qty: string
  quantity_invoiced: string
  free_quantity_invoiced: string
  accrual_unit_cost: string
  received_unit_price: string | null
  po_line_id: string
}

export interface PurchaseOrderInvoiceLine {
  id: string
  description: string
  product_id: string | null
  product_name: string | null
  unit_price: string
  tax_rate: string | null
}

export interface PurchaseOrderForSupplierInvoice {
  id: string
  document_number: string
  partner_id: string
  partner_name: string
  currency: string
  lines: PurchaseOrderInvoiceLine[]
}

export interface OpenPurchaseOrderForSupplierInvoice {
  id: string
  document_number: string
  currency: string
  total: string
}

export interface DuplicateSupplierInvoiceReferenceResult {
  exists: boolean
  invoice_number?: string
}

// ── List filter params ─────────────────────────────────────────────────────

export interface SupplierInvoiceListParams {
  partner_id?: string
  status?: SupplierInvoiceStatus
  match_status?: SupplierInvoiceMatchStatus
  date_from?: string
  date_to?: string
  /** Free-text search over document number and supplier (partner) name. */
  search?: string
  /** "1" filters invoices that still need receipt association. */
  pending_receipt?: '1'
  /** Cursor token for the next/previous page (cursor-based pagination). */
  cursor?: string
}
