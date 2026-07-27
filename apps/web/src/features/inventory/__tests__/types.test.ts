import { describe, it, expectTypeOf } from 'vitest'
import type { StockLevel, StockLevelsResponse } from '../types'
import type { OffsetPaginationMeta } from '@/types/pagination'

/**
 * Contract-lock tests for features/inventory/types.ts.
 *
 * IMPORTANT: These assertions are validated by `tsc --noEmit`, NOT by
 * the vitest runtime. `expectTypeOf` is a zero-runtime proxy that
 * always "passes" at `vitest run` time — the failure signal is a
 * compile error when the types don't match. Running `pnpm typecheck`
 * (part of preflight) is the actual guard. The `describe` / `it`
 * wrappers exist only to give each assertion a readable label in
 * IDEs and error output.
 *
 * These pin the structural agreement between the generated
 * App.Modules.Inventory.Application.DTOs.StockLevelData namespace
 * and the re-exported `StockLevel` type. If the backend DTO
 * regresses (e.g. someone re-declares a shadow interface that types
 * monetary fields as `number`), typecheck fails here FIRST.
 *
 * See the 2026-04-19 types pipeline overhaul: a prior hand-written
 * shadow declared `available: number` while the wire emits string,
 * which caused lexical-order low-stock alerts to fire on
 * `"10" <= "9"` and mis-flag correctly-stocked items. These tests
 * prevent that class of drift from re-entering the codebase.
 */
describe('StockLevel type contract', () => {
  it('monetary and quantity fields are strings, not numbers', () => {
    expectTypeOf<StockLevel['quantity']>().toEqualTypeOf<string>()
    expectTypeOf<StockLevel['reserved']>().toEqualTypeOf<string>()
    expectTypeOf<StockLevel['available']>().toEqualTypeOf<string>()
    expectTypeOf<StockLevel['incoming']>().toEqualTypeOf<string>()
    expectTypeOf<StockLevel['projected_available']>().toEqualTypeOf<string>()
    expectTypeOf<StockLevel['min_quantity']>().toEqualTypeOf<string | null>()
    expectTypeOf<StockLevel['max_quantity']>().toEqualTypeOf<string | null>()
  })

  it('product identification fields match the wire (controller returns them from the JOIN)', () => {
    expectTypeOf<StockLevel['product_id']>().toEqualTypeOf<string>()
    expectTypeOf<StockLevel['product_name']>().toEqualTypeOf<string | null>()
  })

  it('location name is nullable (relation can be soft-deleted)', () => {
    expectTypeOf<StockLevel['location_id']>().toEqualTypeOf<string>()
    expectTypeOf<StockLevel['location_name']>().toEqualTypeOf<string | null>()
  })

  it('is_below_minimum is boolean — a computed flag, not a string', () => {
    expectTypeOf<StockLevel['is_below_minimum']>().toEqualTypeOf<boolean>()
  })
})

describe('StockLevelsResponse envelope', () => {
  it('wraps a StockLevel array', () => {
    expectTypeOf<StockLevelsResponse['data']>().toEqualTypeOf<StockLevel[]>()
  })

  it('exposes optional pagination meta with numeric fields (not strings)', () => {
    // `meta` is optional (controller omits on single-record responses) but
    // when present MUST carry numeric counters for the pager component.
    expectTypeOf<StockLevelsResponse['meta']>().toEqualTypeOf<
      OffsetPaginationMeta | undefined
    >()
  })
})
