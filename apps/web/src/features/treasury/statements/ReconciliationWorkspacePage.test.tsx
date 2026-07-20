import { describe, expect, it } from 'vitest'

import type { BankStatementDetail, BankStatementLine } from './api'
import { getWorkspaceCompletionState } from './ReconciliationWorkspacePage'

function statement(lines: BankStatementDetail['lines']): BankStatementDetail {
  return {
    id: 'statement-1',
    payment_repository_id: 'repository-1',
    currency: 'TND',
    period_start: '2026-07-01',
    period_end: '2026-07-31',
    opening_balance: '100.000',
    closing_balance: '110.000',
    status: 'reconciling',
    parser_profile_id: null,
    imported_at: '2026-07-31T12:00:00Z',
    lines_count: lines.length,
    lines,
  }
}

const line = (match_status: 'unmatched' | 'matched' | 'ignored', direction: 'in' | 'out', amount: string): BankStatementLine => ({
  id: `${match_status}-${amount}`,
  line_number: 1,
  value_date: '2026-07-31',
  booking_date: null,
  direction,
  amount,
  reference: null,
  label: 'Test line',
  match_status,
  ignore_reason: match_status === 'ignored' ? 'informational' : null,
  ignore_text: match_status === 'ignored' ? 'Reviewed' : null,
  location_id: null,
  allocations: match_status === 'matched' ? [{ repository_movement_id: 'movement-1', matched_amount: amount, match_type: 'manual', movement_direction: direction }] : [],
  executions: [],
})

describe('ReconciliationWorkspacePage completion controls', () => {
  it('keeps completion unavailable while any line is unresolved', () => {
    const state = getWorkspaceCompletionState(statement([
      line('matched', 'in', '10.000'),
      line('unmatched', 'out', '2.000'),
    ]), true)

    expect(state.resolved).toBe(1)
    expect(state.canComplete).toBe(false)
  })

  it('treats ignored lines as resolved and excludes their money from remaining', () => {
    const state = getWorkspaceCompletionState(statement([
      line('matched', 'in', '10.000'),
      line('ignored', 'out', '2.000'),
    ]), true)

    expect(state.resolved).toBe(2)
    expect(state.remainingTotal).toBe('0.000')
    expect(state.ignoredTotal).toBe('-2.000')
    expect(state.canComplete).toBe(true)
  })

  it('keeps completion unavailable when the user lacks reconciliation permission', () => {
    const state = getWorkspaceCompletionState(statement([line('matched', 'in', '10.000')]), false)

    expect(state.canComplete).toBe(false)
  })
})
