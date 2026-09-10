/** Transfer statuses come from the generated backend enum. */
export type StockTransferStatus = App.Modules.Inventory.Domain.Enums.TransferStatus

export type StockTransferType = 'intracompany' | 'intercompany'

export type TransferCostDistribution =
  | 'pro_rata_value'
  | 'pro_rata_quantity'
  | 'equal_per_line'

export type StockTransfer = App.Modules.Inventory.Application.DTOs.StockTransferData
export type StockTransferLine = App.Modules.Inventory.Application.DTOs.StockTransferLineData
export type StockTransferLineBatchAllocation = App.Modules.Inventory.Application.DTOs.StockTransferLineBatchAllocationData

export interface CreateStockTransferLineInput {
  product_id: string
  variant_id?: string | null
  quantity: string
  batch_allocations?: CreateStockTransferLineBatchAllocationInput[]
}

export interface CreateStockTransferLineBatchAllocationInput {
  batch_id: number
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
  meta: OffsetPaginationMeta
}
import type { OffsetPaginationMeta } from '@/types/pagination'
