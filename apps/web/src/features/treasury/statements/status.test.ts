import { describe, expect, it } from 'vitest'

import { isResolvedLineStatus, remainingForLine } from './status'

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
})
