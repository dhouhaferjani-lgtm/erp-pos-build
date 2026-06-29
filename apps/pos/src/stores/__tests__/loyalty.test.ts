import { describe, it, expect } from 'vitest'
import { hasModule } from '../productStore'

describe('loyalty module gate', () => {
  it('hasModule detects Loyalty', () => {
    expect(hasModule({ all_enabled_modules: ['Loyalty'] } as never, 'Loyalty')).toBe(true)
    expect(hasModule({ all_enabled_modules: ['Inventory'] } as never, 'Loyalty')).toBe(false)
  })
})
