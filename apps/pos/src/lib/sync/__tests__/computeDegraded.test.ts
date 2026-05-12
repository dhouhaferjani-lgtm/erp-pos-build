/**
 * T1.3 Step 4.3 — degraded tristate heuristic.
 *
 * The `computeDegraded` helper drives the SyncButton's amber dot. It
 * MUST be true when any sync-attention condition holds, AND MUST NOT
 * false-positive on a valid empty catalog (the canonical Phase-4 spec
 * round-1 caution: "Verify the heuristic doesn't false-positive on
 * valid empty catalogs (e.g., a tenant with no operators yet)").
 *
 * Tests pin every clause of the four-OR heuristic plus the explicit
 * empty-catalog negative.
 */
import { describe, it, expect } from 'vitest';
import { computeDegraded } from '../syncService';

const cleanInput = {
  receiptsFailed: 0,
  zReportsFailed: 0,
  paymentConfigPulled: true,
  errors: [] as string[],
};

describe('T1.3 Step 4.3 — computeDegraded', () => {
  it('returns false on a fully clean tick', () => {
    expect(computeDegraded(cleanInput)).toBe(false);
  });

  it('returns true when receiptsFailed > 0', () => {
    expect(computeDegraded({ ...cleanInput, receiptsFailed: 1 })).toBe(true);
  });

  it('returns true when zReportsFailed > 0', () => {
    expect(computeDegraded({ ...cleanInput, zReportsFailed: 1 })).toBe(true);
  });

  it('returns true when paymentConfigPulled is false', () => {
    expect(computeDegraded({ ...cleanInput, paymentConfigPulled: false })).toBe(true);
  });

  it('returns true when errors array is non-empty', () => {
    expect(computeDegraded({ ...cleanInput, errors: ['some error'] })).toBe(true);
  });

  it('returns false on a productsPulled === 0 valid empty catalog (no false-positive)', () => {
    // The heuristic deliberately omits productsPulled from the OR.
    // A tenant with no products yet is a valid empty state, not
    // degraded. This is the canonical Phase-4 round-1 caution.
    // computeDegraded doesn't take productsPulled as input — that's
    // the explicit defense; the test pins the contract by passing
    // ALL the fields it DOES take in their happy-path values and
    // asserting the result is still false.
    expect(computeDegraded(cleanInput)).toBe(false);
  });

  it('multiple conditions still resolve to true', () => {
    expect(
      computeDegraded({
        receiptsFailed: 3,
        zReportsFailed: 1,
        paymentConfigPulled: false,
        errors: ['a', 'b'],
      }),
    ).toBe(true);
  });
});
