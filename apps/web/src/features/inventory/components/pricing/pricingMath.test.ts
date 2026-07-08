import { describe, expect, it } from 'vitest'

import {
  coefficientFromCostAndPrice,
  marginFromCost,
  priceHtFromMargin,
  priceHtFromTtc,
  priceTtcFromHt,
  resolveMarginState,
} from './pricingMath'

describe('pricingMath', () => {
  it('keeps sale price as HT and derives TTC without mutating the HT value', () => {
    expect(priceTtcFromHt('100.000', '19.00', 3)).toBe('119.000')
    expect(priceHtFromTtc('119.000', '19.00', 3)).toBe('100.000')
  })

  it('calculates markup-on-cost margins with decimal helpers', () => {
    expect(marginFromCost('80.000', '100.000')).toBe('25.00')
    expect(priceHtFromMargin('80.000', '25.00', 3)).toBe('100.000')
    expect(coefficientFromCostAndPrice('80.000', '100.000')).toBe('1.2500')
  })

  it('classifies margin state against target and minimum thresholds', () => {
    expect(resolveMarginState('80.000', '100.000', '30.00', '15.00')).toEqual({
      level: 'warning',
      marginPercent: '25.00',
    })
    expect(resolveMarginState('80.000', '90.000', '30.00', '15.00')).toEqual({
      level: 'danger',
      marginPercent: '12.50',
    })
  })
})
