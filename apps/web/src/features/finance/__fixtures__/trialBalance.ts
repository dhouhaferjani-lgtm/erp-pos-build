import type {
  TrialBalanceData,
  TrialBalanceLine,
} from '../types'

/**
 * Fixture factory for TrialBalanceLine.
 *
 * Typed against the hand-written types in ../types.ts (not the generated
 * DTO re-exports - those are blocked by an upstream plumbing issue, see
 * the audit block at the top of ../types.ts).
 *
 * Key property: if a new required field is added to TrialBalanceLine,
 * TypeScript will fail-compile here until this factory is updated.
 *
 * Note: `level` and `is_parent` are required on the hand-rolled type for
 * hierarchical rendering. Page today doesn't render the hierarchy but the
 * fields must be present for the type to accept the fixture.
 */
export function makeTrialBalanceLine(
  overrides: Partial<TrialBalanceLine> = {},
): TrialBalanceLine {
  return {
    account_code: '1000',
    account_name: 'Cash',
    account_type: 'asset',
    debit: '5000.00',
    credit: '0.00',
    level: 0,
    is_parent: false,
    ...overrides,
  }
}

/**
 * Fixture factory for TrialBalanceData (the full report payload).
 *
 * This is the shape returned by the backend `/reports/trial-balance`
 * endpoint after `apiGet` unwraps `response.data.data`. `getTrialBalance`
 * in ../api.ts and `useTrialBalance` in ../hooks pass this through
 * unchanged, so tests that mock `apiGet` should resolve with this shape.
 */
export function makeTrialBalanceReport(
  overrides: Partial<TrialBalanceData> = {},
): TrialBalanceData {
  const defaultLines = [makeTrialBalanceLine()]
  const lines = overrides.lines ?? defaultLines
  return {
    as_of_date: '2026-04-19',
    lines,
    total_debit: '5000.00',
    total_credit: '5000.00',
    is_balanced: true,
    ...overrides,
  }
}
