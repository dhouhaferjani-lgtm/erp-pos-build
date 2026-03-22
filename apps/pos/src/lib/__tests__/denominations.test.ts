import { describe, it, expect } from 'vitest';
import { getDenominations } from '../denominations';

describe('getDenominations', () => {
  it('returns EUR bills >= total, max 3', () => {
    expect(getDenominations('EUR', 15.95)).toEqual([20, 50, 100]);
  });

  it('returns TND bills >= total', () => {
    expect(getDenominations('TND', 8)).toEqual([10, 20, 50]);
  });

  it('returns empty when total exceeds all bills', () => {
    expect(getDenominations('EUR', 200)).toEqual([]);
  });

  it('uses default for unknown currency', () => {
    expect(getDenominations('XYZ', 5)).toEqual([5, 10, 20]);
  });
});
