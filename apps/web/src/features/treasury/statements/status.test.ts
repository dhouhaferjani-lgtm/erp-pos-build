import { describe, expect, it } from 'vitest'

import { formatAtCurrencyScale, isResolvedLineStatus, remainingForLine } from './status'

describe('statement workspace line status', () => {
  it('counts created-from-line resolutions as complete', () => {
    expect(isResolvedLineStatus('matched')).toBe(true)
    expect(isResolvedLineStatus('resolved_by_creation')).toBe(true)
    expect(isResolvedLineStatus('ignored')).toBe(true)
    expect(isResolvedLineStatus('partial')).toBe(false)
    expect(isResolvedLineStatus('unmatched')).toBe(false)
  })

  it('does not report an ignored line as money still remaining', () => {
    expect(remainingForLine({
      match_status: 'ignored',
      direction: 'out',
      amount: '1.250',
      allocations: [],
    }).toFixed(3)).toBe('0.000')
  })

  it('formats statement metrics at the explicit currency scale', () => {
    expect(formatAtCurrencyScale('10', 'EUR')).toBe('10.00')
    expect(formatAtCurrencyScale('10', 'TND')).toBe('10.000')
  })

  it('keeps a negative remaining amount visible as an invariant breach', () => {
    expect(remainingForLine({
      match_status: 'partial',
      direction: 'in',
      amount: '5.000',
      allocations: [{ repository_movement_id: 'movement-1', match_type: 'manual', movement_direction: 'in', matched_amount: '6.000' }],
    }).toFixed(3)).toBe('-1.000')
  })
})
