/**
 * Document types matching backend DTOs
 */

export interface VehicleContextData {
  vehicle_id: string
  snapshot: Record<string, unknown> | null
  mileage: number | null
  display: string | null
  additional_data: Record<string, unknown> | null
}

export interface DocumentLine {
  id: string
  product_id: string
  product_name: string
  description: string
  quantity: number
  unit_price: number
  tax_rate: number
  line_total: number
}

export interface PaymentRecord {
  id: string
  payment_id: string
  amount: string
  payment_date: string
  payment_reference: string | null
  payment_method: string | null
}

export interface Document {
  id: string
  document_number: string
  type: 'quote' | 'order' | 'invoice' | 'credit_note' | 'delivery_note' | 'return_note' | 'sales_order' | 'purchase_order'
  status: 'draft' | 'confirmed' | 'posted' | 'cancelled' | 'received'
  fiscal_category: 'NON_FISCAL' | 'FISCAL_RECEIPT' | 'TAX_INVOICE' | 'CREDIT_NOTE' | 'RETURN_NOTE'
  fiscal_status: 'DRAFT' | 'SEALED' | 'VOIDED'
  is_sealed: boolean
  is_fiscal: boolean
  partner_id: string
  partner_name: string | null
  partner_email: string | null
  vehicle_context: VehicleContextData | null
  subtotal: string | null
  tax_amount: string | null
  total: string | null
  balance_due: string | null
  document_date: string
  issue_date?: string  // Alias for document_date (some endpoints use this)
  due_date: string | null
  valid_until: string | null
  notes: string | null
  external_document_number: string | null
  external_document_date: string | null
  converted_to_order_id: string | null
  converted_at: string | null
  source_document_id: string | null
  source_document_number: string | null
  source_document_type: string | null
  fully_delivered: boolean
  fully_invoiced: boolean
  goods_received: boolean
  delivery_note_ids: string[]
  invoice_ids: string[]
  lines: DocumentLine[]
  payments?: PaymentRecord[]
  created_at: string
  updated_at: string
}
