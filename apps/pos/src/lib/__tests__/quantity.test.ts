import { describe, it, expect } from 'vitest';
import { clampQuantityDecimals, formatQuantity } from '@/lib/quantity';

describe('clampQuantityDecimals', () => {
  it('returns the value when a valid integer in [0, 4]', () => {
    expect(clampQuantityDecimals(0)).toBe(0);
    expect(clampQuantityDecimals(3)).toBe(3);
    expect(clampQuantityDecimals(4)).toBe(4);
  });

  it('clamps out-of-range integers into [0, 4]', () => {
    expect(clampQuantityDecimals(-2)).toBe(0);
    expect(clampQuantityDecimals(9)).toBe(4);
  });

  it('falls back to 4 for null, undefined, or non-integers', () => {
    expect(clampQuantityDecimals(null)).toBe(4);
    expect(clampQuantityDecimals(undefined)).toBe(4);
    expect(clampQuantityDecimals(2.5)).toBe(4);
  });
});

describe('formatQuantity', () => {
  it('renders whole numbers at zero decimals', () => {
    expect(formatQuantity('1.0000', 0)).toBe('1');
    expect(formatQuantity('7', 0)).toBe('7');
  });

  it('pads to the unit precision', () => {
    expect(formatQuantity('1.0000', 3)).toBe('1.000');
  });

  it('falls back to scale-4 when decimalPlaces is null', () => {
    expect(formatQuantity('2.5', null)).toBe('2.5000');
  });
});
