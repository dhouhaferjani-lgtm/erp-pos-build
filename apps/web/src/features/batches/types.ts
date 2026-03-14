/**
 * Batch & Expiry Tracking Types
 * Types for pharmacy/parapharmacy batch tracking and expiry management
 */

/**
 * Expiry status enum matching backend ExpiryStatus
 */
export type ExpiryStatus = 'OK' | 'APPROACHING' | 'WARNING' | 'CRITICAL' | 'EXPIRED'

/**
 * Product batch entity
 */
export interface Batch {
  id: number
  uuid: string
  product_id: string
  product_variant_id: string | null
  batch_number: string
  manufacturing_date: string | null
  expiry_date: string
  expiry_status: ExpiryStatus
  is_active: boolean
  is_recalled: boolean
  is_expired: boolean
  recall_reason: string | null
  recalled_at: string | null
  notes: string | null
  can_be_sold: boolean
  days_until_expiry: number
  total_quantity: number
  available_quantity: number
  created_at: string
  updated_at: string

  // Relationships
  product?: {
    id: string
    name: string
    sku: string
  }
  batch_stock?: Array<{
    location_id: number
    quantity: number
    reserved_quantity: number
    available_quantity: number
  }>
}

/**
 * Batch stock by location
 */
export interface BatchStock {
  id: number
  tenant_id: number
  batch_id: number
  warehouse_id: number
  location_id: number
  quantity: string
  reserved_quantity: string
  available_quantity: string  // Computed column: quantity - reserved_quantity
  created_at: string
  updated_at: string | null

  // Relationships
  batch?: Batch
  location?: {
    id: number
    name: string
  }
}

/**
 * Batch movement history
 */
export interface BatchMovement {
  id: number
  tenant_id: number
  batch_id: number
  movement_id: number
  quantity: string
  created_at: string

  // Relationships
  batch?: Batch
  movement?: {
    id: number
    movement_type: string
    reason: string | null
    reference_type: string | null
    reference_id: string | null
  }
}

/**
 * FEFO batch suggestion
 */
export interface FEFOSuggestion {
  batch: Batch
  quantity: number
  available_quantity: number
  expiry_status: ExpiryStatus
  days_until_expiry: number
}

/**
 * FEFO result
 */
export interface FEFOResult {
  suggestions: FEFOSuggestion[]
  fully_fulfilled: boolean
  total_allocated: number
  requested_quantity: number
  shortfall: number
}

/**
 * Batch list filters
 */
export interface GetBatchesParams {
  product_id?: string
  location_id?: number
  expiry_status?: ExpiryStatus
  is_active?: boolean
  is_recalled?: boolean
  expiring_within_days?: number
  search?: string  // Search batch_number
  per_page?: number
  cursor?: string | null
}

/**
 * Paginated batches response
 */
export interface PaginatedBatchesResponse {
  data: Batch[]
  meta: {
    per_page: number
    has_more: boolean
    total?: number
  }
  links: {
    next: string | null
    prev: string | null
  }
}

/**
 * Create batch payload
 */
export interface CreateBatchInput {
  product_id: string
  batch_number: string
  manufacturing_date?: string | null | undefined
  expiry_date: string
  notes?: string | null | undefined
}

/**
 * Update batch payload
 */
export interface UpdateBatchInput {
  batch_number?: string | undefined
  manufacturing_date?: string | null | undefined
  expiry_date?: string | undefined
  notes?: string | null | undefined
  is_active?: boolean | undefined
}

/**
 * Batch recall payload
 */
export interface RecallBatchInput {
  recall_reason: string
}

/**
 * Products expiring soon response
 */
export interface ExpiringProduct {
  product_id: string
  product_name: string
  product_sku: string
  total_batches: number
  total_quantity: string
  earliest_expiry_date: string
  critical_count: number
  warning_count: number
  batches: Array<{
    batch_id: number
    batch_number: string
    expiry_date: string
    expiry_status: ExpiryStatus
    quantity: string
    location_name: string
  }>
}

/**
 * Batch stock level by location
 */
export interface BatchStockByLocation {
  location_id: number
  location_name: string
  quantity: string
  reserved_quantity: string
  available_quantity: string
}

/**
 * POS batch selection item
 */
export interface POSBatchOption extends Batch {
  suggested_quantity?: number  // For FEFO suggestions
  stock_level?: BatchStock
}

/**
 * Expiry status display configuration
 */
export interface ExpiryStatusConfig {
  status: ExpiryStatus
  label: string
  color: string
  bgColor: string
  threshold_days: number | null
}
