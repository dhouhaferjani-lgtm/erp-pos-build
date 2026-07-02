import { describe, it, expect } from 'vitest'
import { bcadd, bcsub, bcmul, bcdiv, bccomp, formatCurrency, calculateDiscountAmount, applyDiscount } from './decimal'

describe('decimal precision', () => {
  describe('bcadd', () => {
    it('adds 0.1 + 0.2 without IEEE 754 error', () => {
      expect(bcadd('0.1', '0.2', 3)).toBe('0.300')
    })
    it('adds 0.1 + 0.7 without IEEE 754 error', () => {
      expect(bcadd('0.1', '0.7', 3)).toBe('0.800')
    })
    it('handles TND price addition', () => {
      expect(bcadd('5.000', '3.500', 3)).toBe('8.500')
    })
    it('handles large values', () => {
      expect(bcadd('999999.999', '0.001', 3)).toBe('1000000.000')
    })
    it('handles negative values', () => {
      expect(bcadd('-5.000', '3.000', 3)).toBe('-2.000')
    })
    it('handles empty string as zero', () => {
      expect(bcadd('', '5.000', 3)).toBe('5.000')
      expect(bcadd('5.000', '', 3)).toBe('5.000')
    })
  })

  describe('bcsub', () => {
    it('subtracts without IEEE 754 error', () => {
      expect(bcsub('1.000', '0.100', 3)).toBe('0.900')
    })
    it('handles TND price subtraction', () => {
      expect(bcsub('10.000', '5.500', 3)).toBe('4.500')
    })
  })

  describe('bcmul', () => {
    it('multiplies without IEEE 754 error', () => {
      expect(bcmul('0.1', '0.2', 4)).toBe('0.0200')
    })
    it('multiplies unit price by quantity (POS hot path)', () => {
      expect(bcmul('9.990', '2', 3)).toBe('19.980')
      expect(bcmul('4.990', '3', 3)).toBe('14.970')
    })
    it('handles 1.005 * 100 correctly', () => {
      expect(bcmul('1.005', '100', 2)).toBe('100.50')
    })
  })

  describe('bcdiv', () => {
    it('divides without IEEE 754 error', () => {
      expect(bcdiv('10.000', '3', 3)).toBe('3.333')
    })
    it('throws on division by zero', () => {
      expect(() => bcdiv('10.000', '0', 3)).toThrow('Division by zero')
    })
    it('handles percentage calculation', () => {
      expect(bcdiv('19', '100', 4)).toBe('0.1900')
    })
  })

  describe('bccomp', () => {
    it('compares equal values', () => { expect(bccomp('10.500', '10.500')).toBe(0) })
    it('compares with different scale', () => { expect(bccomp('10.5', '10.500')).toBe(0) })
    it('returns 1 when a > b', () => { expect(bccomp('10.500', '5.250')).toBe(1) })
    it('returns -1 when a < b', () => { expect(bccomp('5.250', '10.500')).toBe(-1) })
  })

  describe('calculateDiscountAmount', () => {
    it('calculates percentage discount on TND price', () => {
      expect(calculateDiscountAmount('100.000', 'percentage', '10')).toBe('10.000')
    })
    it('returns fixed discount as-is', () => {
      expect(calculateDiscountAmount('100.000', 'fixed', '15.000')).toBe('15.000')
    })
  })

  describe('applyDiscount', () => {
    it('applies percentage discount', () => {
      expect(applyDiscount('100.000', 'percentage', '10')).toBe('90.000')
    })
    it('applies fixed discount', () => {
      expect(applyDiscount('100.000', 'fixed', '15.000')).toBe('85.000')
    })
  })

  describe('formatCurrency', () => {
    it('formats TND with 3 decimals', () => {
      expect(formatCurrency('5.000', false, 'TND')).toBe('5,000')
    })
    it('formats EUR with 2 decimals', () => {
      expect(formatCurrency('19.99', false, 'EUR', 2)).toBe('19,99')
    })
    it('handles empty string input', () => {
      expect(formatCurrency('', false, 'TND')).toBe('0,000')
    })
  })

  describe('safeBig crash-proofing', () => {
    // safeBig is internal to decimal.ts but every public helper routes
    // through it. These tests exercise the crash-proof fallback that
    // turns unparseable input into Big(0) — without it, user-pasted
    // garbage reaching any of the ~100 bcsub/bcadd/bccomp call sites
    // in the app would throw and trip React's error boundary.
    const GARBAGE_INPUTS = ['abc', '1.2.3', '5abc', '  5  ', '0x10', 'NaN', 'Infinity']

    it('bcadd returns the other operand when garbage is passed as zero', () => {
      for (const input of GARBAGE_INPUTS) {
        expect(bcadd(input, '5.000', 3)).toBe('5.000')
        expect(bcadd('5.000', input, 3)).toBe('5.000')
      }
    })

    it('bcsub treats garbage as zero', () => {
      for (const input of GARBAGE_INPUTS) {
        expect(bcsub('5.000', input, 3)).toBe('5.000')
        expect(bcsub(input, '5.000', 3)).toBe('-5.000')
      }
    })

    it('bcmul treats garbage as zero', () => {
      for (const input of GARBAGE_INPUTS) {
        expect(bcmul(input, '5.000', 3)).toBe('0.000')
      }
    })

    it('bccomp treats garbage as zero', () => {
      for (const input of GARBAGE_INPUTS) {
        expect(bccomp(input, '0')).toBe(0)
        expect(bccomp(input, '1')).toBe(-1)
        expect(bccomp('1', input)).toBe(1)
      }
    })

    it('formatCurrency treats garbage as zero', () => {
      for (const input of GARBAGE_INPUTS) {
        expect(formatCurrency(input, false, 'EUR', 2)).toBe('0,00')
      }
    })
  })
})
