/**
 * Document types - local definitions mirroring backend DTOs
 */

export interface VehicleContextData {
  vehicle_id: string | null
  display: string | null
  mileage: number | null
  license_plate: string | null
  vehicle_snapshot?: {
    make: string | null;
    model: string | null;
    year: number | null;
    license_plate: string | null;
  } | null;
}

export interface DocumentLineData {
  id: string
  document_id: string
  product_id: string | null
  service_id?: string | null
  product_name: string
  product_code?: string | null
  product_barcode?: string | null
  primary_image_url?: string | null
  line_number: number
  description: string
  quantity: string  // Formatted number string from backend
  free_quantity?: string
  free_quantity_received?: string
  free_quantity_invoiced?: string
  unit_price: string  // Formatted number string from backend
  price_entry_mode?: 'unit' | 'total'
  landed_unit_cost?: string | null
  is_bonus_line?: boolean
  is_service?: boolean
  discount_percent: string | null
  discount_amount: string | null
  tax_rate: string | null  // Formatted number string from backend
  line_total: string  // Formatted number string from backend
  notes: string | null
  designation_default_snapshot: string | null
  // Unit precision (unit decimal_places) → drives the qty input step.
  quantity_decimals?: number
  // Product batch policy for goods receipt / stock movement capture.
  requires_batch_tracking: boolean
  // Extended fields for delivery/receipt tracking (may be in payload)
  quantity_delivered?: string
  quantity_received?: string
}

/**
 * The two tax-INCLUSIVE figures one line of a proforma prints — mirrors
 * `App\Modules\Document\Application\DTOs\ProformaLineAmounts`.
 */
export interface ProformaLineAmounts {
  line_id: string
  unit_price: string
  line_total: string
}

/**
 * Everything a detail page needs to render a PROFORMA — mirrors
 * `App\Modules\Document\Application\DTOs\ProformaPresentationData`.
 *
 * Present on the payload only when `is_proforma` is true AND the endpoint builds
 * the projection (every fiscal DETAIL endpoint does). A page gates its VAT
 * rendering on `is_proforma`, never on this object being present, so an endpoint
 * that ships no projection can only cost the reader rows — never leak a tax figure.
 */
export interface ProformaPresentation {
  estimated_total: string
  gross_lines: string
  stamp_duty: string | null
  discount: string | null
  adjustment: string | null
  lines: ProformaLineAmounts[]
}

export interface Document {
  id: string
  type: string
  status: string
  document_number: string | null
  document_date: string
  due_date: string | null
  valid_until: string | null
  currency: string
  subtotal: string
  tax_amount: string
  total: string
  notes: string | null
  internal_notes: string | null

  /**
   * C-F0w / SPEC §2.4 — whether a RENDERING of this document is a proforma: no
   * VAT, no rate rows, no seal wording, an ESTIMATED total.
   *
   * The SERVER's predicate (`ProformaOutputPolicy`, keyed on the fiscal seal), the
   * same one the PDF uses. NEVER re-derive it from `status`: a `paid`-but-unsealed
   * invoice IS a proforma and a sealed-then-cancelled one is NOT.
   *
   * Optional only because this interface is a hand-maintained mirror that dozens of
   * fixtures construct; every real API response carries it. Compare with `=== true`.
   */
  is_proforma?: boolean

  /** The VAT-free figures the proforma branch renders. Never read on a definitive document. */
  proforma?: ProformaPresentation | null

  // Partner info (denormalized on the resource)
  partner_id: string | null
  partner_name: string | null
  partner_email: string | null

  // Cross-document tracking
  source_document_id: string | null
  source_document_number: string | null
  source_document_type: string | null
  converted_to_order_id: string | null

  // Fulfillment flags
  fully_delivered: boolean | null
  fully_invoiced: boolean | null
  goods_received: boolean | null

  // Payment tracking
  payment_status: string | null
  amount_paid: string | null
  balance_due: string | null
  outstanding_amount: string | null

  // External reference
  external_document_number: string | null
  external_document_date: string | null

  // Vehicle context (Otospex) — backend sends snake_case, some pages use camelCase
  vehicle_context: VehicleContextData | null
  vehicleContext?: VehicleContextData | null

  // Flexible payload for type-specific data
  payload?: Record<string, unknown> | null

  /**
   * The goods decision recorded when this invoice was cancelled (plan CF T16 / CF-D5).
   *
   * Projected by `DocumentData` from `payload.return_decisions` — only the decision that
   * TOOK EFFECT, never a rejected one. Present on the detail endpoint; absent on list
   * responses that serialise raw models.
   */
  return_decision?: {
    mode: 'will_return' | 'already_returned' | 'no_return' | 'no_goods_issued' | 'not_applicable'
    returned_on: string | null
    return_note_id: string | null
    decided_by: string | null
    decided_at: string
  } | null
  return_decision_count?: number

  // Computed/extended properties
  issue_date?: string  // Alias for document_date (some endpoints use this)

  // Credit notes only — surfaced by the /credit-notes endpoints
  // (CreditNoteController::formatCreditNote), never by the generic DocumentData.
  reason?: string | null

  // Lines (may be eager-loaded)
  lines?: DocumentLineData[]

  goods_receipts?: {
    id: string
    receipt_number: string | null
    status: string
    received_at: string | null
    external_reference: string | null
  }[]

  supplier_invoices?: {
    id: string
    document_number: string | null
    status: string
    total: string | null
  }[]

  created_at: string
  updated_at: string
}

// DocumentLine type with delivery/receipt tracking fields (alias for compatibility)
export type { DocumentLineData as DocumentLine }

// PaymentRecord from allocations
export interface PaymentRecord {
  id: string
  payment_id: string
  amount: string
  payment_date: string
  payment_reference: string | null
  payment_method: string | null
}
