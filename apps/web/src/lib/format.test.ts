import { describe, expect, it } from 'vitest'

import { formatPercent, formatPercentage } from './format'

describe('formatPercent', () => {
  it('trims percent values to at most two decimal places without fixed zeros', () => {
    expect(formatPercent('19.0000')).toBe('19%')
    expect(formatPercent('19.5000')).toBe('19.5%')
    expect(formatPercent('19.2500')).toBe('19.25%')
    expect(formatPercent('0.0000')).toBe('0%')
    expect(formatPercent(null)).toBe('0%')
  })

  it('keeps the legacy formatPercentage export on the safe implementation', () => {
    expect(formatPercentage('19.0000')).toBe('19%')
  })
})
