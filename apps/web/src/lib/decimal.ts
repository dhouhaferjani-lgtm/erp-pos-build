/**
 * Decimal calculation utilities for precise financial calculations.
 *
 * Uses big.js for arbitrary-precision arithmetic, eliminating
 * IEEE 754 floating-point errors (e.g., 0.1 + 0.2 !== 0.3).
 *
 * All amounts are represented as strings to maintain precision.
 */

import Big from 'big.js'
import { getDecimals, getLocale } from './currencyMeta'
import { formatCurrency as formatLocaleCurrency } from './format'

// Round half-up (matches PHP round() and PostgreSQL behavior)
Big.RM = 1

/**
 * Safely construct a Big from potentially empty/falsy/malformed input.
 *
 * Empty, whitespace-only, or unparseable input all resolve to `new Big(0)`
 * rather than throwing. This is the single choke point for every decimal
 * operation in the app — hardening it here protects all ~100 call sites
 * across POS, Inventory, Treasury etc. from crashing when user-pasted
 * garbage (e.g. "1.2.3", "5abc", "  5  ") reaches an arithmetic helper.
 *
 * Callers that need stricter validation should validate BEFORE calling
 * into this module; the silent-zero fallback is a safety net, not a
 * preferred input path.
 */
function safeBig(value: string): Big {
  if (!value || value.trim() === '') return new Big(0)
  try {
    return new Big(value)
  } catch {
    return new Big(0)
  }
}

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
  return safeBig(a).plus(safeBig(b)).toFixed(scale)
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
  return safeBig(a).minus(safeBig(b)).toFixed(scale)
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
  return safeBig(a).times(safeBig(b)).toFixed(scale)
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
  const divisor = safeBig(b)
  if (divisor.eq(0)) {
    throw new Error('Division by zero')
  }
  return safeBig(a).div(divisor).toFixed(scale)
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
  return safeBig(a).cmp(safeBig(b))
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
 * @param currency Currency code (default: 'EUR')
 * @param scale Decimal places (defaults based on currency: TND=3, EUR/USD=2)
 * @returns Formatted currency string
 *
 * @example
 * formatCurrency('123.456', true, 'TND') // '123,456 TND'
 * formatCurrency('123.456', false, 'TND') // '123,456'
 * formatCurrency('123.45', true, 'EUR', 2) // '123,45 EUR'
 */
export function formatCurrency(
  amount: string | number,
  includeCurrency: boolean = true,
  currency: string = 'EUR',
  scale?: number
): string {
  const decimals = scale ?? getDecimals(currency)
  return formatLocaleCurrency(amount, {
    currency,
    locale: getLocale(currency),
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
    includeCurrency,
  })
}

/**
 * Format decimal as quantity string
 *
 * @param amount Amount (as string or number)
 * @param scale Decimal places (default: 4)
 * @returns Formatted quantity string
 *
 * @example
 * formatQuantity('5') // '5.0000'
 * formatQuantity('5.5', 2) // '5.50'
 */
export function formatQuantity(amount: string | number, scale: number = 4): string {
  let big: Big
  try {
    big = typeof amount === 'number' ? new Big(amount) : safeBig(amount)
  } catch {
    big = new Big(0)
  }
  return big.toFixed(scale)
}
