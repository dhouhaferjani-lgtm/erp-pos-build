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
  product_name: string
  line_number: number
  description: string
  quantity: string  // Formatted number string from backend
  unit_price: string  // Formatted number string from backend
  discount_percent: string | null
  discount_amount: string | null
  tax_rate: string | null  // Formatted number string from backend
  line_total: string  // Formatted number string from backend
  notes: string | null
  designation_default_snapshot: string | null
  // Unit precision (unit decimal_places) → drives the qty input step.
  quantity_decimals?: number
  // Extended fields for delivery/receipt tracking (may be in payload)
  quantity_delivered?: string
  quantity_received?: string
}

export interface Document {
  id: string
  type: string
  status: string
  document_number: string
  document_date: string
  due_date: string | null
  valid_until: string | null
  currency: string
  subtotal: string
  tax_amount: string
  total: string
  notes: string | null
  internal_notes: string | null

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

  // Computed/extended properties
  issue_date?: string  // Alias for document_date (some endpoints use this)

  // Lines (may be eager-loaded)
  lines?: DocumentLineData[]

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
