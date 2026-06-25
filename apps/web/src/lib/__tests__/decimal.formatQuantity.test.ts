import { describe, expect, it } from 'vitest'
import { formatQuantity } from '../decimal'

describe('formatQuantity', () => {
  it('formats a decimal string to 4 places without float', () => {
    expect(formatQuantity('5')).toBe('5.0000')
    expect(formatQuantity('5.5', 2)).toBe('5.50')
  })
})
