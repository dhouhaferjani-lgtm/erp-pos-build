import type { LedgerData } from '../api'
import type { LedgerLine } from '../types'

/**
 * Fixture factory for LedgerLine.
 *
 * Typed against the hand-written type in ../types.ts (not the generated
 * DTO re-exports - those are blocked by an upstream plumbing issue, see
 * the audit block at the top of ../types.ts).
 *
 * Key property: if a new required field is added to LedgerLine,
 * TypeScript will fail-compile here until this factory is updated.
 */
export function makeLedgerLine(
  overrides: Partial<LedgerLine> = {},
): LedgerLine {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    date: '2026-01-15',
    entry_number: 'JE-001',
    description: 'Cash sale',
    account_code: '1000',
    account_name: 'Cash',
    debit: '1000.00',
    credit: '0.00',
    balance: '1000.00',
    source_type: 'invoice',
    source_id: '00000000-0000-4000-8000-00000000000f',
    ...overrides,
  }
}

/**
 * Fixture factory for LedgerData (the full report payload).
 *
 * This is the shape returned by the backend `/ledger` endpoint after
 * `apiGet` unwraps `response.data.data`. `getLedger` in ../api.ts and
 * `useLedger` in ../hooks pass this through unchanged, so tests that mock
 * `apiGet` should resolve with this shape (not a bare LedgerLine array).
 *
 * Note: the LedgerData wrapper type lives in ../api.ts (not ../types.ts)
 * because it's the response envelope assembled in the API layer, while
 * types.ts holds the domain row type (LedgerLine).
 */
export function makeLedgerReport(
  overrides: Partial<LedgerData> = {},
): LedgerData {
  const defaultLines = [makeLedgerLine()]
  const lines = overrides.lines ?? defaultLines
  return {
    lines,
    opening_balance: '0.00',
    closing_balance: '1000.00',
    total_debits: '1000.00',
    total_credits: '0.00',
    date_from: null,
    date_to: '2026-04-19',
    account_filter: null,
    partner_filter: null,
    ...overrides,
  }
}
