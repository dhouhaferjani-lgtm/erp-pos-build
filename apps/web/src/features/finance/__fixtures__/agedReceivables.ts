import type {
  AgedReceivablesData,
  AgedReceivablesLine,
} from '../types'

/**
 * Fixture factory for AgedReceivablesLine.
 *
 * Typed against the hand-written types in ../types.ts (not the generated
 * DTO re-exports - those are blocked by an upstream plumbing issue, see
 * the audit block at the top of ../types.ts).
 *
 * Key property: if a new required field is added to AgedReceivablesLine,
 * TypeScript will fail-compile here until this factory is updated.
 */
export function makeAgedReceivablesLine(
  overrides: Partial<AgedReceivablesLine> = {},
): AgedReceivablesLine {
  return {
    customer_id: '00000000-0000-4000-8000-000000000001',
    customer_name: 'ACME Corp',
    current: '1000.00',
    days_30: '500.00',
    days_60: '200.00',
    days_90: '100.00',
    over_90: '50.00',
    total: '1850.00',
    ...overrides,
  }
}

/**
 * Fixture factory for AgedReceivablesData (the full report payload).
 *
 * This is the shape returned by the backend `/reports/aged-receivables`
 * endpoint after `apiGet` unwraps `response.data.data`. `getAgedReceivables`
 * in ../api.ts and `useAgedReceivables` in ../hooks pass this through
 * unchanged, so tests that mock `apiGet` should resolve with this shape.
 */
export function makeAgedReceivablesReport(
  overrides: Partial<AgedReceivablesData> = {},
): AgedReceivablesData {
  const defaultLines = [makeAgedReceivablesLine()]
  const lines = overrides.lines ?? defaultLines
  return {
    as_of_date: '2026-04-19',
    lines,
    total_current: '1000.00',
    total_days_30: '500.00',
    total_days_60: '200.00',
    total_days_90: '100.00',
    total_over_90: '50.00',
    grand_total: '1850.00',
    ...overrides,
  }
}
