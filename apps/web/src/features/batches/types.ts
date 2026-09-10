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
  /**
   * W4-1 — null when the lot records NO expiry. That is a fact about the stock,
   * not a missing value: nobody supplied an expiry and the server no longer
   * invents one. Render it as "no expiry", and rank such a lot LAST wherever
   * FEFO order is shown, matching the server.
   */
  expiry_date: string | null
  expiry_status: ExpiryStatus
  is_active: boolean
  is_recalled: boolean
  is_expired: boolean
  recall_reason: string | null
  recalled_at: string | null
  notes: string | null
  can_be_sold: boolean
  /** Null for a lot with no recorded expiry (W4-1). */
  days_until_expiry: number | null
  total_quantity: string
  available_quantity: string
  created_at: string
  updated_at: string

  // Relationships
  product?: {
    id: string
    name: string
    sku: string
    quantity_decimals: number
  }
  batch_stock?: Array<{
    location_id: string | number
    quantity: string | number
    reserved_quantity: string | number
    available_quantity: string | number
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
 * Expiry status as emitted by the FEFO suggestion endpoint.
 *
 * Deliberately NOT the `ExpiryStatus` type above: `BatchResource` uppercases
 * the enum (`strtoupper($this->expiryStatus()->value)`) but
 * `BatchSuggestionDTO::toArray()` does not, so this one endpoint emits the raw
 * lowercase enum values. Tracked as a residual — see
 * docs/superpowers/tickets/2026-08-08-f7-fefo-residuals.md.
 */
export type FEFOExpiryStatus = 'ok' | 'approaching' | 'warning' | 'critical' | 'expired'

/**
 * FEFO batch suggestion — mirrors `BatchSuggestionDTO::toArray()` exactly.
 *
 * NOTE (F-7): `quantity` is a canonical scale-4 numeric STRING — the backend
 * runs the FEFO pipeline on bcmath decimals and serialises them as strings
 * (precision contract, rule 19). Never `parseFloat` it.
 */
export interface FEFOSuggestion {
  /** Bigint PK of the lot (NOT the uuid) */
  batch_id: number
  batch_number: string
  /** Suggested draw from this lot (4dp numeric string) */
  quantity: string
  /** ISO date string "YYYY-MM-DD", null when the lot records no expiry (W4-1). */
  expiry_date: string | null
  /** Null for a lot with no recorded expiry (W4-1). */
  days_until_expiry: number | null
  expiry_status: FEFOExpiryStatus
  expiry_status_label: string
  expiry_status_color: string
  can_sell: boolean
}

/**
 * FEFO result — mirrors `BatchSuggestionResultDTO::toArray()` exactly.
 *
 * `shortfall` and `total_quantity_suggested` are 4dp numeric strings (F-7).
 */
export interface FEFOResult {
  suggestions: FEFOSuggestion[]
  fully_fulfilled: boolean
  /** Unfulfilled remainder, "0.0000" when fully covered (4dp numeric string) */
  shortfall: string
  /** Total suggested across all lots (4dp numeric string) */
  total_quantity_suggested: string
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

// ---------------------------------------------------------------------------
// Expiry write-off types (B4a)
// ---------------------------------------------------------------------------

/**
 * Per-location stock entry included in an ExpiredBatch.
 *
 * `quantity` and `reserved_quantity` are decimal:4-cast DB columns → JSON
 * strings. `available_quantity` is computed with BCMath at scale 4 and
 * serialises as a JSON string.
 */
export interface ExpiredBatchStock {
  /** Bigint PK from the locations table */
  location_id: number
  /** On-hand quantity (decimal:4 DB cast → string) */
  quantity: string
  /** Reserved quantity (decimal:4 DB cast → string) */
  reserved_quantity: string
  /** Available = quantity − reserved (4-dp JSON string) */
  available_quantity: string
}

/**
 * Shape of a single item returned by GET /api/v1/batches/expired.
 *
 * Mirrors BatchResource exactly.  Unlike the general `Batch` type, `product`
 * and `batch_stock` are always present — the endpoint loads both relations
 * eagerly.
 *
 * Batch totals sum the scoped, loaded stock relation using BCMath. All
 * batch-level and per-location quantities are 4-dp JSON strings, including
 * available quantities. This contract applies before and after activation.
 */
export interface ExpiredBatch {
  /** Bigint PK */
  id: number
  /** UUID used in all API calls */
  uuid: string
  product_id: string
  /** Variant UUID, null for product-level batches */
  variant_id: string | null
  batch_number: string
  /** ISO date string "YYYY-MM-DD", null when not set */
  manufacturing_date: string | null
  /** ISO date string "YYYY-MM-DD" */
  expiry_date: string
  /** Negative integer for expired lots */
  days_until_expiry: number
  is_active: boolean
  is_expired: boolean
  is_recalled: boolean
  recall_reason: string | null
  /** ISO 8601 datetime string */
  recalled_at: string | null
  notes: string | null
  expiry_status: ExpiryStatus
  can_be_sold: boolean
  /** Scoped loaded-stock total (4-dp JSON string). */
  total_quantity: string
  /** Scoped loaded-stock total (4-dp JSON string). */
  available_quantity: string
  /** ISO 8601 datetime string */
  created_at: string | null
  /** ISO 8601 datetime string */
  updated_at: string | null
  /** Always present — endpoint loads the product relation */
  product: {
    id: string
    name: string
    sku: string
    /** Unit precision (unit decimal_places) → drives the qty input step. */
    quantity_decimals?: number | null
  }
  /** Always present — endpoint loads the batchStock relation */
  batch_stock: ExpiredBatchStock[]
}

/**
 * A single lot line in a grouped write-off request.
 */
export interface GroupedWriteOffLine {
  /** Batch UUID (backend resolves to integer id internally) */
  batch_id: string
  /** Positive quantity string, up to 4 decimal places (precision contract) */
  quantity: string
}

/**
 * Request payload for POST /api/v1/batches/write-off-grouped.
 */
export interface GroupedWriteOffPayload {
  /** UUID of the storage location */
  location_id: string
  /** At least one line required */
  lines: GroupedWriteOffLine[]
  /** Write-off reason — maps to MovementReason in the backend */
  reason: 'expiry' | 'damage' | 'other'
  /** Client-supplied idempotency key (max 255 chars) */
  idempotency_key: string
}

/**
 * Per-lot outcome inside a GroupedWriteOffResult.
 *
 * All quantity/cost fields are numeric strings (precision contract):
 * - `quantity`: scale 4 (batch quantity scale)
 * - `unit_cost` / `total_cost`: scale 6 (cost scale)
 */
export interface GroupedWriteOffMovementResult {
  /** Internal integer batch id */
  batch_id: number
  /** UUID of the stock_movements row created for this lot */
  movement_id: string
  /** Quantity written off for this lot (4dp numeric string) */
  quantity: string
  /** Per-unit cost snapshot at write-off time (6dp numeric string) */
  unit_cost: string
  /** unit_cost × quantity (6dp numeric string) */
  total_cost: string
}

/**
 * Response shape for POST /api/v1/batches/write-off-grouped.
 *
 * HTTP 201 on first application; HTTP 200 on idempotent replay.
 * `replayed` is `true` when the result was reconstructed from a persisted
 * idempotency record — no stock was modified on a replay.
 */
export interface GroupedWriteOffResult {
  idempotency_key: string
  /** false on first apply, true when the idempotency record was replayed */
  replayed: boolean
  movements: GroupedWriteOffMovementResult[]
}
