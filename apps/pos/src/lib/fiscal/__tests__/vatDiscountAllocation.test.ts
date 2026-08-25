/**
 * D-1 — transaction-discount VAT-base allocation (owner ruling 2026-08-25 (a)).
 *
 * The taxable base must EXCLUDE a remise granted on the ticket, ventilated
 * pro-rata per rate. This suite pins the allocator: exact largest-remainder
 * distribution at currency scale, zero-VAT/exempt groups participating, and
 * the discount's own net/VAT split staying inside the group's line sums.
 */

import { describe, expect, it } from 'vitest';

import {
  TransactionDiscountAllocationError,
  allocateTransactionDiscount,
  type VatGroupLineSums,
} from '@/lib/fiscal/vatDiscountAllocation';

const TND = 3;

function group(
  rate: string,
  category: string,
  net: string,
  vat: string,
): VatGroupLineSums {
  return { rate, category, lineNet: net, lineVat: vat };
}

describe('allocateTransactionDiscount', () => {
  it('returns the untouched line sums when the discount is zero', () => {
    const groups = [
      group('19.00', '', '100.000', '19.000'),
      group('7.00', '', '50.000', '3.500'),
    ];

    const out = allocateTransactionDiscount(groups, '0.000', TND);

    expect(out.map((g) => [g.rate, g.netAmount, g.vatAmount, g.discountAllocated])).toEqual([
      // Canonical `vat_breakdown[]` order: lexicographic on `rate|category`,
      // exactly what `buildVatBreakdown` seals ('19.00' sorts before '7.00').
      ['19.00', '100.000', '19.000', '0.000'],
      ['7.00', '50.000', '3.500', '0.000'],
    ]);
  });

  it('ventilates a 50.000 discount pro-rata across 7/13/19 + an exempt group', () => {
    // Gross per group: 7% -> 107.000, 13% -> 226.000, 19% -> 238.000, exempt -> 69.000
    // Σ gross = 640.000
    const groups = [
      group('19.00', '', '200.000', '38.000'),
      group('13.00', '', '200.000', '26.000'),
      group('7.00', '', '100.000', '7.000'),
      group('0.00', 'EXEMPT', '69.000', '0.000'),
    ];

    const out = allocateTransactionDiscount(groups, '50.000', TND);

    const byRate = new Map(out.map((g) => [`${g.rate}|${g.category}`, g]));
    const sumDisc = out.reduce((acc, g) => acc + Number(g.discountAllocated), 0);
    expect(sumDisc.toFixed(3)).toBe('50.000');

    // Every group's gross is reduced by exactly its allocated share.
    for (const g of out) {
      expect(Number(g.grossAmount).toFixed(3)).toBe(
        (Number(g.grossBeforeDiscount) - Number(g.discountAllocated)).toFixed(3),
      );
      expect(Number(g.netAmount) + Number(g.vatAmount)).toBeCloseTo(Number(g.grossAmount), 6);
      expect(Number(g.netAmount)).toBeGreaterThanOrEqual(0);
      expect(Number(g.vatAmount)).toBeGreaterThanOrEqual(0);
    }

    // Exempt group carries base only — never any VAT.
    expect(byRate.get('0.00|EXEMPT')?.vatAmount).toBe('0.000');
  });

  it('distributes the largest-remainder residue so Σ allocated == discount exactly', () => {
    // Three equal groups and a discount that does not divide evenly: 10.000 / 3.
    const groups = [
      group('19.00', '', '100.000', '19.000'),
      group('13.00', '', '105.310', '13.690'),
      group('7.00', '', '111.215', '7.785'),
    ];

    const out = allocateTransactionDiscount(groups, '10.001', TND);
    const sum = out.reduce((acc, g) => acc + Number(g.discountAllocated), 0);

    expect(sum.toFixed(3)).toBe('10.001');
  });

  it('zeroes a group entirely on a 100 % comp (no ±1 ulp residue left behind)', () => {
    const groups = [
      group('19.00', '', '200.005', '38.001'),
      group('7.00', '', '100.003', '7.000'),
    ];
    // Σ gross = 238.006 + 107.003 = 345.009
    const out = allocateTransactionDiscount(groups, '345.009', TND);

    for (const g of out) {
      expect(g.netAmount).toBe('0.000');
      expect(g.vatAmount).toBe('0.000');
      expect(g.grossAmount).toBe('0.000');
    }
  });

  it('refuses a discount larger than the ticket gross', () => {
    const groups = [group('19.00', '', '100.000', '19.000')];

    expect(() => allocateTransactionDiscount(groups, '200.000', TND))
      .toThrow(TransactionDiscountAllocationError);
  });

  it('refuses a positive discount on a zero-gross ticket', () => {
    const groups = [group('19.00', '', '0.000', '0.000')];

    expect(() => allocateTransactionDiscount(groups, '1.000', TND))
      .toThrow(TransactionDiscountAllocationError);
  });

  it('never allocates more than a group can absorb', () => {
    // A tiny 19 % group beside a large exempt group; the residue must not
    // push the small group's allocation past its own gross.
    const groups = [
      group('19.00', '', '0.001', '0.000'),
      group('0.00', 'EXEMPT', '999.999', '0.000'),
    ];

    const out = allocateTransactionDiscount(groups, '500.000', TND);
    for (const g of out) {
      expect(Number(g.discountAllocated)).toBeLessThanOrEqual(Number(g.grossBeforeDiscount));
    }
  });
});
