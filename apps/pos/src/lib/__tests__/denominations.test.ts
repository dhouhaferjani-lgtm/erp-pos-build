import { describe, it, expect } from 'vitest';
import { getDenominations } from '../denominations';

describe('getDenominations', () => {
  it('returns EUR bills >= total, max 3', () => {
    expect(getDenominations('EUR', '15.95')).toEqual([20, 50, 100]);
  });

  it('returns TND bills >= total', () => {
    expect(getDenominations('TND', '8.000')).toEqual([10, 20, 50]);
  });

  it('returns round-up multiples of the largest bill when total exceeds all bills', () => {
    // EUR largest bill is 100; total 200 → 200, 300, 400.
    expect(getDenominations('EUR', '200.00')).toEqual([200, 300, 400]);
  });

  it('rounds up to the next multiple of the largest bill when total is not a multiple', () => {
    // TND largest bill is 50; total 123 → ceil(123/50)*50 = 150 → 150, 200, 250.
    expect(getDenominations('TND', '123.000')).toEqual([150, 200, 250]);
  });

  it('rounds a FRACTIONAL total up to the next multiple of the largest bill', () => {
    // EUR largest bill is 100; 250.75 → ceil = 300 → 300, 400, 500. The only
    // case exercising the decimal-domain ceiling on a non-integer total.
    expect(getDenominations('EUR', '250.75')).toEqual([300, 400, 500]);
  });

  it('uses default for unknown currency', () => {
    expect(getDenominations('XYZ', '5.00')).toEqual([5, 10, 20]);
  });
});
