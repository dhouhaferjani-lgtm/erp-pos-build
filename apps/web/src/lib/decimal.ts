/**
 * Decimal calculation utilities for precise financial calculations
 *
 * These functions provide bcmath-like precision for monetary calculations,
 * avoiding floating-point precision errors.
 *
 * All amounts are represented as strings to maintain precision.
 */

import { getDecimals } from '../hooks/useCurrency'

/**
 * Add two decimal numbers
 *
 * @param a First number (as string)
 * @param b Second number (as string)
 * @param scale Decimal places (default: 3)
 * @returns Sum as string
 *
 * @example
 * bcadd('10.50', '5.25') // '15.750'
 */
export function bcadd(a: string, b: string, scale: number = 3): string {
  const numA = parseFloat(a)
  const numB = parseFloat(b)
  const result = numA + numB
  return result.toFixed(scale)
}

/**
 * Subtract two decimal numbers
 *
 * @param a First number (as string)
 * @param b Second number (as string)
 * @param scale Decimal places (default: 3)
 * @returns Difference as string
 *
 * @example
 * bcsub('10.50', '5.25') // '5.250'
 */
export function bcsub(a: string, b: string, scale: number = 3): string {
  const numA = parseFloat(a)
  const numB = parseFloat(b)
  const result = numA - numB
  return result.toFixed(scale)
}

/**
 * Multiply two decimal numbers
 *
 * @param a First number (as string)
 * @param b Second number (as string)
 * @param scale Decimal places (default: 3)
 * @returns Product as string
 *
 * @example
 * bcmul('10.50', '2') // '21.000'
 */
export function bcmul(a: string, b: string, scale: number = 3): string {
  const numA = parseFloat(a)
  const numB = parseFloat(b)
  const result = numA * numB
  return result.toFixed(scale)
}

/**
 * Divide two decimal numbers
 *
 * @param a Dividend (as string)
 * @param b Divisor (as string)
 * @param scale Decimal places (default: 3)
 * @returns Quotient as string
 *
 * @example
 * bcdiv('10.50', '2') // '5.250'
 */
export function bcdiv(a: string, b: string, scale: number = 3): string {
  const numA = parseFloat(a)
  const numB = parseFloat(b)
  if (numB === 0) {
    throw new Error('Division by zero')
  }
  const result = numA / numB
  return result.toFixed(scale)
}

/**
 * Compare two decimal numbers
 *
 * @param a First number (as string)
 * @param b Second number (as string)
 * @returns -1 if a < b, 0 if a === b, 1 if a > b
 *
 * @example
 * bccomp('10.50', '5.25') // 1
 * bccomp('5.25', '10.50') // -1
 * bccomp('10.50', '10.50') // 0
 */
export function bccomp(a: string, b: string): number {
  const numA = parseFloat(a)
  const numB = parseFloat(b)
  if (numA < numB) return -1
  if (numA > numB) return 1
  return 0
}

/**
 * Calculate discount amount from line total
 *
 * @param lineTotal Line total (as string)
 * @param discountType Type of discount ('percentage' or 'fixed')
 * @param discountValue Discount value (as string)
 * @returns Discount amount as string
 *
 * @example
 * calculateDiscountAmount('100.000', 'percentage', '10') // '10.000'
 * calculateDiscountAmount('100.000', 'fixed', '15.000') // '15.000'
 */
export function calculateDiscountAmount(
  lineTotal: string,
  discountType: 'percentage' | 'fixed',
  discountValue: string
): string {
  if (discountType === 'percentage') {
    const percent = bcdiv(discountValue, '100')
    return bcmul(lineTotal, percent)
  } else {
    return discountValue
  }
}

/**
 * Apply discount to line total
 *
 * @param lineTotal Line total (as string)
 * @param discountType Type of discount ('percentage' or 'fixed')
 * @param discountValue Discount value (as string)
 * @returns Discounted total as string
 *
 * @example
 * applyDiscount('100.000', 'percentage', '10') // '90.000'
 * applyDiscount('100.000', 'fixed', '15.000') // '85.000'
 */
export function applyDiscount(
  lineTotal: string,
  discountType: 'percentage' | 'fixed',
  discountValue: string
): string {
  const discountAmount = calculateDiscountAmount(lineTotal, discountType, discountValue)
  return bcsub(lineTotal, discountAmount)
}

/**
 * Format decimal as currency string
 *
 * @param amount Amount (as string or number)
 * @param includeCurrency Include currency code suffix (default: true)
 * @param currency Currency code (default: 'TND')
 * @param scale Decimal places (defaults based on currency: TND=3, EUR/USD=2)
 * @returns Formatted currency string
 *
 * @example
 * formatCurrency('123.456') // '123.456 TND'
 * formatCurrency('123.456', false) // '123.456'
 * formatCurrency('123.45', true, 'EUR', 2) // '123.45 EUR'
 */
export function formatCurrency(
  amount: string | number,
  includeCurrency: boolean = true,
  currency: string = 'EUR',
  scale?: number
): string {
  const num = typeof amount === 'string' ? parseFloat(amount) : amount
  const decimals = scale ?? getDecimals(currency)
  const formatted = num.toFixed(decimals)
  return includeCurrency ? `${formatted} ${currency}` : formatted
}
