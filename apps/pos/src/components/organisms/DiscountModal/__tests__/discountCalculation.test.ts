import { describe, it, expect } from 'vitest';

describe('transaction discount calculation', () => {
  function computeTransactionDiscount(
    subtotal: number,
    type: 'percentage' | 'fixed',
    value: string,
  ): number {
    if (type === 'percentage') {
      return (subtotal * parseFloat(value)) / 100;
    }
    return parseFloat(value);
  }

  it('converts percentage to absolute amount', () => {
    expect(computeTransactionDiscount(100, 'percentage', '20')).toBe(20);
  });

  it('converts 50% on small order correctly', () => {
    expect(computeTransactionDiscount(8.80, 'percentage', '50')).toBeCloseTo(4.40);
  });

  it('passes fixed amount through unchanged', () => {
    expect(computeTransactionDiscount(100, 'fixed', '15')).toBe(15);
  });

  it('handles 100% discount', () => {
    expect(computeTransactionDiscount(25.50, 'percentage', '100')).toBeCloseTo(25.50);
  });
});
