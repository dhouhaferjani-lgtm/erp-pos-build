/**
 * Inventory feature types — re-exports from generated backend DTOs.
 *
 * DO NOT add hand-written domain types here. If a new field is needed
 * on the wire, add it to the PHP DTO and regenerate:
 *   cd apps/api && php artisan typescript:transform
 *
 * Frontend-only shapes (pagination envelopes, request params) ARE
 * allowed but must NOT re-declare a backend DTO.
 */

import type { OffsetPaginationMeta } from '@/types/pagination'

export type StockLevel = App.Modules.Inventory.Application.DTOs.StockLevelData

export interface StockLevelsResponse {
  data: StockLevel[]
  meta?: OffsetPaginationMeta
}

export type StockMovementReferenceType = App.Shared.Domain.Enums.StockMovementReferenceType

/**
 * A `GET /api/v1/stock-movements` row, exactly as the controller emits it.
 * EXPORTED so tests bind their fixtures to this shape instead of re-declaring a
 * narrower copy — a fixture missing a field the page reads is how a green suite
 * hides a runtime break (gate r2, N7).
 */
export interface StockMovement {
  id: string
  product_id: string
  product_name: string
  location_id: string
  location_name: string
  movement_type: string
  /** MovementReason value e.g. 'write_off', 'expiry', 'damage', or null */
  reason: string | null
  quantity: string
  quantity_decimals: number
  quantity_before: string
  quantity_after: string
  reference: string
  /** Document-linkage morph type (StockMovementReferenceType value) or null. */
  reference_type: StockMovementReferenceType | null
  /** Raw linkage FK — the counting/document UUID this movement points at, or null. */
  reference_id: string | null
  source_document_id: string | null
  source_document_type: string | null
  notes: string | null
  user_id: string
  user_name: string | null
  /** UUID of the original movement this row corrects; null if this is not a reversal. */
  reverses_movement_id: string | null
  /** True when another movement has already reversed this row. */
  is_reversed: boolean
  created_at: string
}

/** The endpoint's unconditionally paginated envelope. Exported with {@link StockMovement}. */
export interface StockMovementsResponse {
  data: StockMovement[]
  meta: OffsetPaginationMeta
}
