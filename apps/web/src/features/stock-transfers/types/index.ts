/**
 * Stock Transfer feature — TypeScript types.
 *
 * NOTE: Domain types are intentionally hand-written here (not auto-generated
 * from PHP DTOs) because this feature ships before the generic DTO transformer
 * is wired up for the new module. When `php artisan typescript:transform`
 * gains support for inventory transfers, replace this file with the generated
 * output and drop the hand-written interfaces — types ALWAYS flow from
 * backend (CLAUDE.md rule 7).
 */

export type StockTransferStatus = 'draft' | 'in_transit' | 'completed' | 'cancelled'

export type StockTransferType = 'intracompany' | 'intercompany'

export type TransferCostDistribution =
  | 'pro_rata_value'
  | 'pro_rata_quantity'
  | 'equal_per_line'

export interface StockTransferLine {
  id: string
  product_id: string
  product_name: string | null
  product_sku: string | null
  quantity: string
  unit_cost_snapshot: string | null
  allocated_transfer_cost: string
}

export interface StockTransfer {
  id: string
  transfer_number: string
  transfer_type: StockTransferType
  status: StockTransferStatus
  source_location_id: string
  source_location_name: string | null
  destination_location_id: string
  destination_location_name: string | null
  notes: string | null
  transfer_cost: string
  transfer_cost_label: string | null
  transfer_cost_distribution: TransferCostDistribution
  initiated_by_user_id: string
  initiated_by_name: string | null
  completed_by_user_id: string | null
  completed_by_name: string | null
  cancelled_by_user_id: string | null
  cancelled_by_name: string | null
  initiated_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  cancellation_reason: string | null
  created_at: string | null
  updated_at: string | null
  lines?: StockTransferLine[]
}

export interface CreateStockTransferLineInput {
  product_id: string
  quantity: string
}

export interface CreateStockTransferInput {
  source_location_id: string
  destination_location_id: string
  notes?: string | null
  transfer_cost?: string
  transfer_cost_label?: string | null
  transfer_cost_distribution?: TransferCostDistribution
  idempotency_key?: string
  lines: CreateStockTransferLineInput[]
}

export interface StockTransferListFilters {
  status?: StockTransferStatus | 'all'
  source_location_id?: string
  destination_location_id?: string
  page?: number
  per_page?: number
}

export interface StockTransferListResponse {
  data: StockTransfer[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
  }
}
