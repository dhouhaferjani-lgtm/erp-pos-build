import { describe, expect, it } from 'vitest'

import { isResolvedLineStatus } from './status'

describe('statement workspace line status', () => {
  it('counts created-from-line resolutions as complete', () => {
    expect(isResolvedLineStatus('matched')).toBe(true)
    expect(isResolvedLineStatus('resolved_by_creation')).toBe(true)
    expect(isResolvedLineStatus('ignored')).toBe(true)
    expect(isResolvedLineStatus('partial')).toBe(false)
    expect(isResolvedLineStatus('unmatched')).toBe(false)
  })
})
