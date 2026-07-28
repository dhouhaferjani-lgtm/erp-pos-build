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

/**
 * Remainder of `a / b` at `scale`. Used by the v3 aggregate bind
 * (`total mod denomination == 0`). Callers MUST assert `b > 0` BEFORE calling
 * — the assert ORDER is normative (spec §4.1): reaching a zero divisor must be
 * impossible, not merely caught.
 */
export function bcmod(a: string, b: string, scale: number = 3): string {
  const divisor = safeBig(b);
  if (divisor.eq(0)) {
    throw new Error('Modulo by zero');
  }
  return safeBig(a).mod(divisor).toFixed(scale);
}

export function bccomp(a: string, b: string): number {
  return safeBig(a).cmp(safeBig(b));
}

/**
 * Sum an array of decimal strings exactly (Big.js), returning a string at
 * `scale`. Avoids the float drift of `arr.reduce((s, x) => s + parseFloat(x))`
 * when aggregating many line totals.
 */
export function bcsum(values: readonly string[], scale: number = 3): string {
  let acc = new Big(0);
  for (const value of values) {
    acc = acc.plus(safeBig(value));
  }
  return acc.toFixed(scale);
}

/** Absolute value of a decimal string, formatted at `scale`. */
export function bcabs(value: string, scale: number = 3): string {
  return safeBig(value).abs().toFixed(scale);
}

export function bcformat(value: string | number, scale: number): string {
  return new Big(value).toFixed(scale);
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
