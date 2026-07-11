export type ReplenishmentStatus = 'pending' | 'in_progress' | 'fulfilled' | 'rejected' | 'cancelled'
export interface ReplenishmentLine {
  id: string; location_id: string; location_name: string; product_id: string; product_name: string
  variant_id: string | null; variant_name: string | null; requested_qty: string | null; note: string | null
  request_count: number; status: ReplenishmentStatus; source_channel: 'pos' | 'web'
  first_requested_at: string; last_requested_at: string; sourcing_document_id: string | null
  fulfillment_type: 'transfer' | 'purchase_order' | null; fulfillment_id: string | null; rejection_reason: string | null
}
export interface ReplenishmentPaginationMeta { current_page: number; per_page: number; total: number; last_page: number; from: number | null; to: number | null }
export interface OpenReplenishmentListResponse { data: ReplenishmentLine[]; meta: { truncated: boolean } }
export interface ReplenishmentHistoryResponse { data: ReplenishmentLine[]; meta: ReplenishmentPaginationMeta }
export interface ReplenishmentListFilters { status?: 'open' | ReplenishmentStatus; location_ids?: string[]; product_id?: string; from?: string; to?: string; page?: number }
export interface CaptureReplenishmentInput { location_id: string; product_id: string; variant_id?: string | null; requested_qty?: string | null; note?: string | null }
export interface CreateTransferActionInput { source_location_id: string; lines: { request_id: string; quantity: string }[] }
export interface CreatePoActionInput { supplier_id: string; destination_location_id: string; existing_document_id?: string; lines: { request_id: string; quantity: string }[] }
export interface RejectActionInput { request_ids: string[]; reason: string }
