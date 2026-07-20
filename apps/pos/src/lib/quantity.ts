import { bcadd } from '@/lib/decimal';

/**
 * Clamp a server-supplied `quantity_decimals` into the storable range [0, 4].
 * Non-integers, null, and undefined fall back to the canonical scale-4 default.
 */
export function clampQuantityDecimals(decimalPlaces: number | null | undefined): number {
  if (typeof decimalPlaces !== 'number' || !Number.isInteger(decimalPlaces)) return 4;
  return Math.min(Math.max(decimalPlaces, 0), 4);
}

/**
 * Pad/round a server-rounded quantity string to the unit precision.
 *
 * Precondition: `value` is already rounded to its canonical precision on the
 * server. `bcadd` (Big.js `toFixed`) pads AND rounds half-up, so this is a
 * display-only pad — never the place to make a rounding decision.
 */
export function formatQuantity(value: string, decimalPlaces: number | null | undefined): string {
  return bcadd(value, '0', clampQuantityDecimals(decimalPlaces));
}
