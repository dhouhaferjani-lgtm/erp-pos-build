/**
 * Decimal calculation utilities for precise financial calculations.
 *
 * Uses big.js for arbitrary-precision arithmetic, eliminating
 * IEEE 754 floating-point errors (e.g., 0.1 + 0.2 !== 0.3).
 *
 * All amounts are represented as strings to maintain precision.
 */

import Big from 'big.js';
import { getCurrencyDecimals } from '@/lib/currency';

// Round half-up (matches PHP round() and PostgreSQL behavior)
Big.RM = 1;

/** Safely construct a Big from potentially empty/falsy input */
function safeBig(value: string): Big {
  if (!value || value.trim() === '') return new Big(0);
  return new Big(value);
}

export function bcadd(a: string, b: string, scale: number = 3): string {
  return safeBig(a).plus(safeBig(b)).toFixed(scale);
}

export function bcsub(a: string, b: string, scale: number = 3): string {
  return safeBig(a).minus(safeBig(b)).toFixed(scale);
}

export function bcmul(a: string, b: string, scale: number = 3): string {
  return safeBig(a).times(safeBig(b)).toFixed(scale);
}

export function bcdiv(a: string, b: string, scale: number = 3): string {
  const divisor = safeBig(b);
  if (divisor.eq(0)) {
    throw new Error('Division by zero');
  }
  return safeBig(a).div(divisor).toFixed(scale);
}

export function bccomp(a: string, b: string): number {
  return safeBig(a).cmp(safeBig(b));
}

export function calculateDiscountAmount(
  lineTotal: string,
  discountType: 'percentage' | 'fixed',
  discountValue: string,
): string {
  if (discountType === 'percentage') {
    const percent = bcdiv(discountValue, '100');
    return bcmul(lineTotal, percent);
  } else {
    return discountValue;
  }
}

export function applyDiscount(
  lineTotal: string,
  discountType: 'percentage' | 'fixed',
  discountValue: string,
): string {
  const discountAmount = calculateDiscountAmount(lineTotal, discountType, discountValue);
  return bcsub(lineTotal, discountAmount);
}

export function formatCurrency(
  amount: string | number,
  includeCurrency: boolean = true,
  currency: string = 'EUR',
  scale?: number,
): string {
  const decimals = scale ?? getCurrencyDecimals(currency);
  let big: Big;
  try {
    big = typeof amount === 'number' ? new Big(amount) : safeBig(String(amount));
  } catch {
    big = new Big(0);
  }
  const formatted = big.toFixed(decimals);
  return includeCurrency ? `${formatted} ${currency}` : formatted;
}
