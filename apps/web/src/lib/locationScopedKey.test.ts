import { describe, it, expect } from 'vitest'
import { locationScopedKey } from './locationScopedKey'

describe('locationScopedKey', () => {
  it('keeps the resource literal leading for bare-prefix invalidation across scopes', () => {
    const a = locationScopedKey(['stock-levels', 'search'], 'all')
    const b = locationScopedKey(['stock-levels', 'search'], ['loc-b', 'loc-a'])
    expect(a[0]).toBe('stock-levels')
    expect(b[0]).toBe('stock-levels')
  })

  it('sorts the scope array for a deterministic key', () => {
    const k = locationScopedKey(['x'], ['loc-b', 'loc-a']) as unknown[]
    expect(k).toContainEqual({ locScope: ['loc-a', 'loc-b'] })
  })

  it("encodes 'all' as a literal", () => {
    const k = locationScopedKey(['x'], 'all') as unknown[]
    expect(k).toContainEqual({ locScope: 'all' })
  })
})
