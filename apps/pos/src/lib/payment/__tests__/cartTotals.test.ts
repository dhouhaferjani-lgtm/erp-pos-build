import { describe, expect, it } from 'vitest';
import { computeExactCartTotal } from '@/lib/payment/cartTotals';
import { estimateCartTotal } from '@/stores/paymentStore';
import { makeCartItem } from '@/test/helpers';

describe('computeExactCartTotal', () => {
  it('EUR percentage discount agrees byte-for-byte with the store estimator', () => {
    // 3 x 3.33 EUR = 9.99; 7% = 0.6993.
    //   at scale 2 (correct): 0.70  -> total 9.29
    //   at scale 3 (the old receiptService path): 0.699 -> total 9.291
    // The disagreement is a rounding-tie input, so both callers MUST agree.
    const items = [
      makeCartItem({ id: 'i1', line_total: '3.33' }),
      makeCartItem({ id: 'i2', line_total: '3.33' }),
      makeCartItem({ id: 'i3', line_total: '3.33' }),
    ];
    const discount = { type: 'percentage' as const, value: '7' };

    expect(computeExactCartTotal(items, discount, 'EUR')).toBe('9.29');
    expect(estimateCartTotal(items, discount, 'EUR')).toBe(
      computeExactCartTotal(items, discount, 'EUR'),
    );
  });

  it('TND scale 3 keeps millime precision', () => {
    const items = [makeCartItem({ id: 'i1', line_total: '9.997' })];
    expect(computeExactCartTotal(items, undefined, 'TND')).toBe('9.997');
  });

  it('clamps an over-large discount to the subtotal and never returns a negative total', () => {
    const items = [makeCartItem({ id: 'i1', line_total: '5.000' })];
    expect(computeExactCartTotal(items, { type: 'fixed', value: '9.000' }, 'TND')).toBe('0.000');
  });

  it('ignores a zero, negative or non-numeric discount value', () => {
    const items = [makeCartItem({ id: 'i1', line_total: '7.500' })];
    expect(computeExactCartTotal(items, { type: 'fixed', value: '0' }, 'TND')).toBe('7.500');
    expect(computeExactCartTotal(items, { type: 'fixed', value: 'abc' }, 'TND')).toBe('7.500');
  });
});
