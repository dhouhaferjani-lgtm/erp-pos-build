/**
 * Stock Adjustment feature — types.
 *
 * DO NOT add hand-written domain types here. Domain shapes are GENERATED from
 * the PHP DTOs (`php artisan typescript:transform`) and re-exported below, per
 * CLAUDE rule 7 — types always flow from the backend. The only hand-written
 * shapes are frontend-only concerns the backend has no opinion about: list
 * filters, the paginated envelope, and the create/update request bodies.
 */
import type { OffsetPaginationMeta } from '@/types/pagination'

export type StockAdjustment = App.Modules.Inventory.Application.DTOs.StockAdjustmentData
export type StockAdjustmentLine = App.Modules.Inventory.Application.DTOs.StockAdjustmentLineData
export type StockAdjustmentStatus = App.Modules.Inventory.Domain.Enums.StockAdjustmentStatus
export type MovementReason = App.Modules.Inventory.Domain.Enums.MovementReason
export type StockLevel = App.Modules.Inventory.Application.DTOs.StockLevelData

/** Frontend-only: the list screen's filter state. */
export interface StockAdjustmentListFilters {
  status?: StockAdjustmentStatus | 'all'
  location_id?: string
  page?: number
  per_page?: number
}

/** Frontend-only: the paginated list envelope. */
export interface StockAdjustmentListResponse {
  data: StockAdjustment[]
  meta: OffsetPaginationMeta
}

/**
 * Frontend-only: the request body.
 *
 * `delta_quantity` and `observed_before` are STRINGS and signed — never numbers.
 * A `parseFloat`/`Number()` round-trip on a `decimal(15,4)` is exactly the
 * breach rule 19 exists to prevent, and negation is done at string level.
 */
export interface CreateStockAdjustmentLineInput {
  product_id: string
  variant_id?: string | null | undefined
  batch_uuid?: string | null | undefined
  reason_code: AdjustmentReason
  delta_quantity: string
  observed_before: string
  line_note?: string | null | undefined
}

export interface CreateStockAdjustmentInput {
  location_id: string
  note?: string | null | undefined
  idempotency_key?: string | null | undefined
  post_immediately?: boolean | undefined
  acknowledge_stale?: boolean | undefined
  ignore_reservations?: boolean | undefined
  lines: CreateStockAdjustmentLineInput[]
}

/** Frontend-only: the PATCH body (draft re-anchor). `location_id` is prohibited. */
export interface UpdateStockAdjustmentInput {
  note?: string | null | undefined
  lines?: CreateStockAdjustmentLineInput[] | undefined
}

/**
 * The FOUR reasons a stock-adjustment LINE may carry.
 *
 * The generated `MovementReason` union is the FULL 17-case vocabulary; the
 * manual subset lives only in `MovementReason::manualAdjustmentCases()`, whose
 * sole HTTP consumer was deleted with the raw adjust endpoint, and no meta route
 * exposes it. `satisfies readonly MovementReason[]` keeps this list bound to the
 * generated union, so a backend rename is a typecheck failure rather than a
 * runtime 422; `__tests__/reasons.test.ts` pins the SET as the frontend mirror
 * of the backend's enum test.
 */
export const ADJUSTMENT_REASONS = [
  'adjustment_positive',
  'adjustment_negative',
  'damage',
  'write_off',
] as const satisfies readonly MovementReason[]

export type AdjustmentReason = (typeof ADJUSTMENT_REASONS)[number]

/**
 * The reasons selectable for a given product.
 *
 * `damage` / `write_off` are refused on a batch-tracked product (the batch
 * write-off posts the COGS entry the adjustment document does not), so they are
 * filtered OUT of the picker rather than left to surface as a 422 surprise.
 */
export function reasonsForProduct(requiresBatchTracking: boolean): readonly AdjustmentReason[] {
  if (!requiresBatchTracking) {
    return ADJUSTMENT_REASONS
  }

  return ADJUSTMENT_REASONS.filter(
    (reason) => reason !== 'damage' && reason !== 'write_off',
  )
}

/** The sign a reason requires, mirroring MovementReason::getMovementType(). */
export function reasonDirection(reason: AdjustmentReason): 'in' | 'out' {
  return reason === 'adjustment_positive' ? 'in' : 'out'
}

export const STOCK_ADJUSTMENT_STATUSES = ['draft', 'posted', 'cancelled'] as const satisfies
  readonly StockAdjustmentStatus[]

/**
 * Guards, not casts.
 *
 * The generated DTOs type `status` and `reason_code` as plain `string` (the PHP
 * DTOs expose the enum's backing value), so every consumer needs a narrowing
 * step. A cast would silently accept a value the backend added and this frontend
 * has no label for; a guard makes that a visible fallback instead.
 */
export function isStockAdjustmentStatus(value: string): value is StockAdjustmentStatus {
  return (STOCK_ADJUSTMENT_STATUSES as readonly string[]).includes(value)
}

export function isAdjustmentReason(value: string): value is AdjustmentReason {
  return (ADJUSTMENT_REASONS as readonly string[]).includes(value)
}

/**
 * Returns NULL for a status this frontend does not know.
 *
 * Deliberately not a `'draft'` fallback: `draft` is the state that ENABLES Post
 * and Cancel, so mapping an unrecognised backend state onto it would offer two
 * write actions for a document whose real state forbids them.
 */
export function toStockAdjustmentStatus(value: string): StockAdjustmentStatus | null {
  return isStockAdjustmentStatus(value) ? value : null
}
