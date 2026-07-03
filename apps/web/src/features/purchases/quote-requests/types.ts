export type QuoteRequestDocumentStatus = 'draft' | 'confirmed' | 'cancelled'

export interface QuoteRequestPartnerRef {
  id: string
  name: string
}

export interface QuoteRequestLine {
  product_id: string | null
  variant_id: string | null
  description?: string | null
  quantity: string
  unit_price: string
}

export interface QuoteRequestListItem {
  id: string
  number: string
  partner: QuoteRequestPartnerRef
  status: QuoteRequestDocumentStatus
  currency: string
  total: string
  group_id?: string | null
  validity_date?: string | null
  supplier_reference?: string | null
  lead_time_days?: number | null
  responded_at?: string | null
  sent_at?: string | null
  closed_reason?: string | null
  lines?: QuoteRequestLine[]
}

export interface QuoteRequestDetail extends QuoteRequestListItem {
  type: 'purchase_rfq'
  group_id: string
  lines: QuoteRequestLine[]
}

export type QuoteRequestGroupSibling = QuoteRequestDetail

export interface QuoteRequestGroup {
  group_id: string
  siblings: QuoteRequestGroupSibling[]
  has_live_purchase_order?: boolean
}

export interface QuoteRequestListParams {
  status?: QuoteRequestDocumentStatus
  search?: string
}

export interface QuoteRequestLinePayload {
  product_id: string
  variant_id?: string | null
  description?: string | null
  quantity: string
  unit_price?: string | null
}

export interface CreateQuoteRequestGroupPayload {
  partner_ids: string[]
  lines: QuoteRequestLinePayload[]
  validity_date?: string | null
  notes?: string | null
}

export interface UpdateQuoteRequestPayload {
  lines: QuoteRequestLinePayload[]
  validity_date?: string | null
  supplier_reference?: string | null
  lead_time_days?: number | null
}

export interface AwardQuoteRequestResponse {
  id: string
  type: 'purchase_order'
  status: string
}

export interface ReopenQuoteRequestGroupResponse {
  reopened: number
}
