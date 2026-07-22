import { describe, expect, it } from 'vitest'
import { suggestSource } from './suggestSource'

describe('suggestSource', () => {
  it('prefers the largest threshold surplus', () => {
    expect(suggestSource([{ location_id: 'a', available: '2', max_quantity: '1' }, { location_id: 'b', available: '8', max_quantity: '3' }], 'a', '2')).toEqual({ locationId: 'b', reason: 'surplus' })
  })
  it('falls back to largest available when thresholds are null', () => {
    expect(suggestSource([{ location_id: 'a', available: '0', max_quantity: null }, { location_id: 'b', available: '4', max_quantity: null }], 'a', '2')).toEqual({ locationId: 'b', reason: 'fallback' })
  })
  it('returns null when no source qualifies', () => {
    expect(suggestSource([{ location_id: 'a', available: '2', max_quantity: '3' }], 'a', '4')).toBeNull()
  })
})
