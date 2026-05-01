import { describe, it, expect } from 'vitest'
import { generateRandomPin } from './UsersPage'

describe('generateRandomPin', () => {
  it('returns a 4-digit numeric string by default', () => {
    const pin = generateRandomPin()
    expect(pin).toMatch(/^\d{4}$/)
  })

  it('honours the requested length', () => {
    expect(generateRandomPin(4)).toHaveLength(4)
    expect(generateRandomPin(5)).toHaveLength(5)
    expect(generateRandomPin(6)).toHaveLength(6)
  })

  it('rejects lengths outside 4-6 digits', () => {
    expect(() => generateRandomPin(3)).toThrow()
    expect(() => generateRandomPin(7)).toThrow()
  })

  it('produces non-deterministic output across calls', () => {
    const samples = new Set<string>()
    for (let i = 0; i < 25; i += 1) {
      samples.add(generateRandomPin(6))
    }
    // 25 random 6-digit values should virtually never collide to a single value
    expect(samples.size).toBeGreaterThan(1)
  })
})
