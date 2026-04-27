import { describe, expect, it } from 'vitest'
import { computeDiscountAmount, isDiscountAboveTolerance } from '../discountValidation'
import type { ToleranceSettings } from '@/types/treasury'

const FR_SETTINGS: ToleranceSettings = {
  enabled: true,
  percentage: '0.0050', // 0.5%
  max_amount: '0.5000', // €0.50 absolute
  source: 'country',
}

const TN_SETTINGS: ToleranceSettings = {
  enabled: true,
  percentage: '0.0050',
  max_amount: '0.1000', // 0.100 TND absolute
  source: 'country',
}

describe('isDiscountAboveTolerance', () => {
  it('rejects discount strictly below absolute margin', () => {
    expect(isDiscountAboveTolerance('0.40', '100.00', FR_SETTINGS)).toBe(false)
  })

  it('rejects discount EQUAL to margin (strict inequality)', () => {
    expect(isDiscountAboveTolerance('0.50', '100.00', FR_SETTINGS)).toBe(false)
  })

  it('accepts discount strictly above margin', () => {
    expect(isDiscountAboveTolerance('0.51', '100.00', FR_SETTINGS)).toBe(true)
  })

  it('uses percentage threshold when it dominates absolute', () => {
    // FR: 0.5% of €1000 = €5.00 dominates €0.50 absolute
    expect(isDiscountAboveTolerance('4.00', '1000.00', FR_SETTINGS)).toBe(false)
    expect(isDiscountAboveTolerance('5.01', '1000.00', FR_SETTINGS)).toBe(true)
  })

  it('handles TND scale-3 parity correctly', () => {
    expect(isDiscountAboveTolerance('0.050', '10.000', TN_SETTINGS)).toBe(false)
    expect(isDiscountAboveTolerance('0.150', '10.000', TN_SETTINGS)).toBe(true)
  })

  it('returns true for zero discount (no UX flag)', () => {
    expect(isDiscountAboveTolerance('0', '100.00', FR_SETTINGS)).toBe(true)
    expect(isDiscountAboveTolerance('0.00', '100.00', FR_SETTINGS)).toBe(true)
  })

  it('returns true when settings are undefined or disabled', () => {
    expect(isDiscountAboveTolerance('0.10', '100.00', undefined)).toBe(true)
    expect(isDiscountAboveTolerance('0.10', '100.00', null)).toBe(true)
    expect(isDiscountAboveTolerance('0.10', '100.00', { ...FR_SETTINGS, enabled: false })).toBe(true)
  })

  it('returns true for empty discount input', () => {
    expect(isDiscountAboveTolerance('', '100.00', FR_SETTINGS)).toBe(true)
  })
})

describe('computeDiscountAmount', () => {
  it('multiplies subtotal by percentage / 100 at scale 4', () => {
    expect(computeDiscountAmount('100.00', '5')).toBe('5.0000')
    expect(computeDiscountAmount('100.00', '10')).toBe('10.0000')
  })

  it('handles fractional percentages', () => {
    expect(computeDiscountAmount('100.00', '0.5')).toBe('0.5000')
  })

  it('handles empty subtotal as zero', () => {
    expect(computeDiscountAmount('', '10')).toBe('0.0000')
  })

  it('handles empty percentage as zero', () => {
    expect(computeDiscountAmount('100.00', '')).toBe('0.0000')
  })
})
