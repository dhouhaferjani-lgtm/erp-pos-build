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
  source_line_id: string
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
  total: string
  status: SupplierInvoiceStatus
  match_status: SupplierInvoiceMatchStatus
  /** Present in detail response (not has_source_document — use source_purchase_order instead). */
  source_document_id: string | null
  lines: SupplierInvoiceLine[]
  source_purchase_order: SourcePurchaseOrder | null
  match: InvoiceMatch
  attachments: DocumentAttachment[]
  posted_at: string | null
  /** Conditionally present on POST /post when price_variance under warn enforcement. */
  warning?: string
}

// ── Create payload ─────────────────────────────────────────────────────────

export interface CreateSupplierInvoiceLinePayload {
  source_line_id: string
  /** qty scale: max 4 decimal places as string */
  quantity: string
  /** money scale: max 3 decimal places as string */
  unit_price: string
  /** percent scale: max 2 decimal places as string */
  vat_rate: string
}

export interface CreateSupplierInvoicePayload {
  partner_id: string
  source_document_id: string
  currency: string
  issue_date: string
  due_date?: string
  supplier_reference?: string
  lines: CreateSupplierInvoiceLinePayload[]
}

// ── 422 error shape for blocked post ──────────────────────────────────────

export interface PostBlockError {
  block: 'qty_blocked' | 'price_block'
  message: string
}

// ── Payment payload (reuses existing POST /payments endpoint) ──────────────

export interface RecordPaymentPayload {
  document_id: string
  amount: string
  currency: string
  payment_method_id: string
  payment_date: string
  reference?: string
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
  /** Cursor token for the next/previous page (cursor-based pagination). */
  cursor?: string
}
