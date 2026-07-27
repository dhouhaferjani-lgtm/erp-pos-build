import { describe, expect, it } from 'vitest'
import { pairRebalanceRows } from './rebalance'

describe('pairRebalanceRows', () => {
  it('pairs every surplus with every deficit', () => {
    const rows = [{ product_id: 'p', variant_id: null, name: 'Widget', sku: 'W', quantity_decimals: 3, surpluses: [{ location_id: 'b', available: '8.0000', max_quantity: '3.0000', excess: '5.0000' }], deficits: [{ location_id: 'a', available: '0.0000', min_quantity: '2.0000' }] }]
    expect(pairRebalanceRows(rows)).toHaveLength(1)
    expect(pairRebalanceRows(rows)[0]).toMatchObject({ from: { location_id: 'b' }, to: { location_id: 'a' } })
    expect(pairRebalanceRows(rows)[0]?.quantity).toBe('5.0000')
  })
  it('returns an empty list for no endpoint rows', () => {
    expect(pairRebalanceRows([])).toEqual([])
  })
})
