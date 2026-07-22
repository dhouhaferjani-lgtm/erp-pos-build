import { describe, expect, it } from 'vitest'
import { pairRebalanceRows } from './rebalance'

describe('pairRebalanceRows', () => {
  it('pairs every surplus with every deficit', () => {
    const rows = [{ product_id: 'p', variant_id: null, name: 'Widget', sku: 'W', surpluses: [{ location_id: 'b', available: '8', max_quantity: '3', excess: '5' }], deficits: [{ location_id: 'a', available: '0', min_quantity: '2' }] }]
    expect(pairRebalanceRows(rows)).toHaveLength(1)
    expect(pairRebalanceRows(rows)[0]).toMatchObject({ from: { location_id: 'b' }, to: { location_id: 'a' } })
  })
  it('returns an empty list for no endpoint rows', () => {
    expect(pairRebalanceRows([])).toEqual([])
  })
})
