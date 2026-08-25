/**
 * D-1 — transaction-discount VAT-base allocation (owner ruling 2026-08-25 (a)).
 *
 * The taxable base must EXCLUDE a remise granted on the ticket, ventilated
 * pro-rata per rate. This suite pins the allocator: exact largest-remainder
 * distribution at currency scale, zero-VAT/exempt groups participating, and
 * the discount's own net/VAT split staying inside the group's line sums.
 */

import { describe, expect, it } from 'vitest';

import { bcadd, bccomp, bcdiv, bcformat, bcmul, bcsub, bcsum } from '@/lib/decimal';
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
    expect(bcsum(out.map((g) => g.discountAllocated), TND)).toBe('50.000');

    // Every group's gross is reduced by exactly its allocated share.
    for (const g of out) {
      expect(g.grossAmount).toBe(bcsub(g.grossBeforeDiscount, g.discountAllocated, TND));
      expect(bcadd(g.netAmount, g.vatAmount, TND)).toBe(g.grossAmount);
      expect(bccomp(g.netAmount, '0')).toBeGreaterThanOrEqual(0);
      expect(bccomp(g.vatAmount, '0')).toBeGreaterThanOrEqual(0);
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

    expect(bcsum(out.map((g) => g.discountAllocated), TND)).toBe('10.001');
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

/**
   * D-1 gate r2 finding 1 — the SERVER now bounds each group's share within
   * `[exact − 1 ulp, exact + 2 ulp]` of `discount × gross_r / Σ gross`
   * (`FiscalPayloadConstraintValidator::assertRemiseAllocationInBand()`).
   *
   * A band the honest DEVICE can fall outside of would quarantine real sales,
   * so this is the parity proof from the authoring side: 1 000 pseudo-random
   * ventilations must all land inside the band the server enforces, and Σ must
   * still be the remise exactly. The PHP twin
   * (`TransactionDiscountVatAllocatorTest`) runs the same proof on the server
   * allocator.
   */
  it('a thousand random ventilations all land inside the server\'s band', () => {
    // Deterministic LCG so a failure reproduces exactly.
    let seed = 20260825;
    const rnd = (n: number): number => {
      seed = (seed * 1103515245 + 12345) % 2147483648;
      return seed % n;
    };
    const RATES = ['0.00', '7.00', '13.00', '19.00'];
    const ULP = '0.001';

    for (let i = 0; i < 1000; i++) {
      const groups: VatGroupLineSums[] = [];
      let ticketGross = '0.000';
      for (const rate of RATES.slice(0, 1 + rnd(4))) {
        const net = `${rnd(900)}.${String(rnd(1000)).padStart(3, '0')}`;
        const vat = bcdiv(bcmul(net, rate, 7), '100', TND);
        groups.push({ rate, category: '', lineNet: bcformat(net, TND), lineVat: vat });
        ticketGross = bcadd(ticketGross, bcadd(net, vat, TND), TND);
      }
      if (bccomp(ticketGross, '0') === 0) continue;

      const discount = bcdiv(bcmul(ticketGross, String(1 + rnd(100)), 7), '100', TND);
      if (bccomp(discount, '0') === 0) continue;

      const out = allocateTransactionDiscount(groups, discount, TND);

      for (const group of out) {
        const exact = bcdiv(bcmul(discount, group.grossBeforeDiscount, 7), ticketGross, 7);
        const lower = bcsub(exact, ULP, 7);
        const upper = bcadd(exact, bcmul(ULP, '2', TND), 7);
        expect(bccomp(group.discountAllocated, lower)).toBeGreaterThanOrEqual(0);
        expect(bccomp(group.discountAllocated, upper)).toBeLessThanOrEqual(0);
      }

      expect(bcsum(out.map((g) => g.discountAllocated), TND)).toBe(bcformat(discount, TND));
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
      expect(bccomp(g.discountAllocated, g.grossBeforeDiscount)).toBeLessThanOrEqual(0);
    }
  });
});
