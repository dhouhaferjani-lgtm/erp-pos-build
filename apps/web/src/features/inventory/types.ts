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

export type StockLevel = App.Modules.Inventory.Application.DTOs.StockLevelData

export interface StockLevelsResponse {
  data: StockLevel[]
  meta?: {
    total: number
    current_page: number
    per_page: number
    last_page: number
  }
}
