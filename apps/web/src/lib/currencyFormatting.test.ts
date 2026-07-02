import { describe, expect, it } from 'vitest'

import { formatCurrency as formatCanonicalCurrency } from './format'
import { formatCurrency as formatDecimalCurrency } from './decimal'
import {
  formatCurrency as formatLegacyCurrency,
  formatCurrencyCompact,
} from './formatCurrency'

function normalizeCurrency(value: string): string {
  return value.replace(/\s+/g, ' ').trim()
}

describe('canonical currency formatting', () => {
  it('renders TND consistently from every public formatter path', () => {
    const expected = '15,600 TND'

    expect(normalizeCurrency(formatCanonicalCurrency('15.6', {
      currency: 'TND',
      locale: 'fr-TN',
    }))).toBe(expected)
    expect(normalizeCurrency(formatLegacyCurrency('15.6', 'TND', 'fr-TN'))).toBe(expected)
    expect(normalizeCurrency(formatDecimalCurrency('15.6', true, 'TND'))).toBe(expected)
  })

  it('uses the same locale-aware number shape for compact TND amounts', () => {
    expect(normalizeCurrency(formatCurrencyCompact('6607.6', 'TND', 'fr-TN'))).toBe('6 607,600')
  })
})
