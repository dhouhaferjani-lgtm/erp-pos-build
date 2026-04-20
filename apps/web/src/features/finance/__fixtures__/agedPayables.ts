import type {
  AgedPayablesData,
  AgedPayablesLine,
} from '../types'

/**
 * Fixture factory for AgedPayablesLine.
 *
 * Typed against the hand-written types in ../types.ts (not the generated
 * DTO re-exports - those are blocked by an upstream plumbing issue, see
 * the audit block at the top of ../types.ts).
 *
 * Key property: if a new required field is added to AgedPayablesLine,
 * TypeScript will fail-compile here until this factory is updated.
 */
export function makeAgedPayablesLine(
  overrides: Partial<AgedPayablesLine> = {},
): AgedPayablesLine {
  return {
    vendor_id: '00000000-0000-4000-8000-000000000001',
    vendor_name: 'Supplier Co',
    current: '2000.00',
    days_30: '1000.00',
    days_60: '500.00',
    days_90: '200.00',
    over_90: '100.00',
    total: '3800.00',
    ...overrides,
  }
}

/**
 * Fixture factory for AgedPayablesData (the full report payload).
 *
 * This is the shape returned by the backend `/reports/aged-payables`
 * endpoint after `apiGet` unwraps `response.data.data`. `getAgedPayables`
 * in ../api.ts and `useAgedPayables` in ../hooks pass this through
 * unchanged, so tests that mock `apiGet` should resolve with this shape.
 */
export function makeAgedPayablesReport(
  overrides: Partial<AgedPayablesData> = {},
): AgedPayablesData {
  const defaultLines = [makeAgedPayablesLine()]
  const lines = overrides.lines ?? defaultLines
  return {
    as_of_date: '2026-04-19',
    lines,
    total_current: '2000.00',
    total_days_30: '1000.00',
    total_days_60: '500.00',
    total_days_90: '200.00',
    total_over_90: '100.00',
    grand_total: '3800.00',
    ...overrides,
  }
}
