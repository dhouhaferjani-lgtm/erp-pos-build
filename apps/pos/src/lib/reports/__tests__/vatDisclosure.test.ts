import { describe, it, expect } from 'vitest';
import { deriveVatDisclosure } from '@/lib/reports/vatDisclosure';

/**
 * B-6(ii) / Option A1 — device-side VAT disclosure derivation.
 *
 * The helper takes ONLY signed/stored fields (`tax_amount` + `vat_breakdown`)
 * and never touches an accumulator, a payload builder or a persisted shape.
 * That is deliberate: `aggregateReportData()`'s return value IS the object
 * `computeZReportHash()` hashes and `local_z_reports.report_data` stores
 * (`zReportService.ts:445-450`, `:504`), so a new key on it would change the
 * LEGACY Z fiscal hash. Deriving instead of accumulating keeps every byte fixed.
 */
describe('deriveVatDisclosure', () => {
  it('reports no refund VAT when the headline and the table already agree', () => {
    const result = deriveVatDisclosure(
      {
        tax_amount: '19.000',
        vat_breakdown: [{ vat_amount: '19.000' }],
      },
      3,
    );

    expect(result.salesVat).toBe('19.000');
    expect(result.refundVat).toBe('0.000');
    expect(result.netVat).toBe('19.000');
    expect(result.hasRefundVat).toBe(false);
  });

  it('derives refund VAT as the wedge between the sale-only headline and the net table', () => {
    // The signed table is already NET (the refund branch SUBTRACTS from it),
    // while `tax_amount` is SALE-ONLY. Their difference IS the refund VAT —
    // this is exactly the contradiction the F-4 tripwire's
    // `refunds_count == 0` gate has to paper over today.
    const result = deriveVatDisclosure(
      {
        tax_amount: '57.000',
        vat_breakdown: [{ vat_amount: '47.500' }],
      },
      3,
    );

    expect(result.salesVat).toBe('57.000');
    expect(result.refundVat).toBe('9.500');
    expect(result.netVat).toBe('47.500');
    expect(result.hasRefundVat).toBe(true);
  });

  it('sums every rate group, including a zero-rated one', () => {
    const result = deriveVatDisclosure(
      {
        tax_amount: '57.000',
        vat_breakdown: [
          { vat_amount: '0.000' },
          { vat_amount: '30.000' },
          { vat_amount: '17.500' },
        ],
      },
      3,
    );

    expect(result.netVat).toBe('47.500');
    expect(result.refundVat).toBe('9.500');
  });

  it('carries the currency scale rather than decimal.ts default of 3', () => {
    // A scale-2 currency (EUR) must not gain a phantom third decimal.
    const result = deriveVatDisclosure(
      {
        tax_amount: '12.34',
        vat_breakdown: [{ vat_amount: '10.00' }],
      },
      2,
    );

    expect(result.salesVat).toBe('12.34');
    expect(result.refundVat).toBe('2.34');
    expect(result.netVat).toBe('10.00');
  });

  it('treats an empty breakdown as a zero net table, not as "no data"', () => {
    // A shift with a headline but no rate groups is degenerate; the disclosure
    // must still add up rather than silently show the sale-only figure as net.
    const result = deriveVatDisclosure({ tax_amount: '5.000', vat_breakdown: [] }, 3);

    expect(result.netVat).toBe('0.000');
    expect(result.refundVat).toBe('5.000');
    expect(result.hasRefundVat).toBe(true);
  });

  it('never returns a negative refund magnitude when the table exceeds the headline', () => {
    // Legacy/edge corpus: if the net table is somehow LARGER than the sale-only
    // headline the wedge is negative, which is not a refund. Surface it as
    // unreconciled with a zero magnitude rather than printing "VAT on refunds:
    // -3.000", which reads as a refund that increased VAT.
    const result = deriveVatDisclosure(
      {
        tax_amount: '10.000',
        vat_breakdown: [{ vat_amount: '13.000' }],
      },
      3,
    );

    expect(result.refundVat).toBe('0.000');
    expect(result.hasRefundVat).toBe(false);
    expect(result.isReconciled).toBe(false);
  });

  /**
   * GATE r1 F-1/B-1 — `isReconciled` must come from the RAW wedge, so that
   * `hasRefundVat` and `isReconciled` are INDEPENDENT. Before the fix
   * `isReconciled` was computed from the CLAMPED `refundVat`, which made
   * `isReconciled === false` imply `hasRefundVat === false` and rendered the
   * whole unreconciled branch unreachable in the component.
   */
  it('keeps hasRefundVat and isReconciled independent (F-1)', () => {
    const anomaly = deriveVatDisclosure(
      { tax_amount: '10.000', vat_breakdown: [{ vat_amount: '13.000' }] },
      3,
    );
    // The anomaly state: no refund magnitude to show, but NOT reconciled.
    expect(anomaly.hasRefundVat).toBe(false);
    expect(anomaly.isReconciled).toBe(false);

    const clean = deriveVatDisclosure(
      { tax_amount: '10.000', vat_breakdown: [{ vat_amount: '10.000' }] },
      3,
    );
    // Equal figures: also no refund, but this one IS reconciled. The two flags
    // must be able to disagree — that is what makes the warning reachable.
    expect(clean.hasRefundVat).toBe(false);
    expect(clean.isReconciled).toBe(true);
  });

  /**
   * GATE r1 F-4/M-1 — when an AUTHORITATIVE refund-VAT magnitude is supplied
   * (the EOD preview's era-safe `bcabs`-then-add accumulator), it is used
   * INSTEAD of the wedge, and `isReconciled` then genuinely compares two
   * independent sources. This is the only way the device can reach
   * `hasRefundVat === true && isReconciled === false`.
   */
  it('prefers an authoritative refund_vat_amount over the wedge (F-4)', () => {
    const result = deriveVatDisclosure(
      {
        tax_amount: '57.000',
        vat_breakdown: [{ vat_amount: '47.500' }],
        // Deliberately DISAGREES with the wedge (9.500) so the assertion
        // discriminates between the two paths — the gate called the previous
        // fixture out for yielding the same number either way.
        refund_vat_amount: '9.000',
      },
      3,
    );

    expect(result.refundVat).toBe('9.000');
    expect(result.hasRefundVat).toBe(true);
    // 57.000 − 9.000 = 48.000 ≠ 47.500 ⇒ the two sources disagree.
    expect(result.isReconciled).toBe(false);
  });

  it('reconciles when the authoritative accumulator agrees with the table', () => {
    const result = deriveVatDisclosure(
      {
        tax_amount: '57.000',
        vat_breakdown: [{ vat_amount: '47.500' }],
        refund_vat_amount: '9.500',
      },
      3,
    );

    expect(result.refundVat).toBe('9.500');
    expect(result.hasRefundVat).toBe(true);
    expect(result.isReconciled).toBe(true);
  });

  it('treats a zero authoritative accumulator as authoritative, not as absent', () => {
    // A shift the accumulator says had NO refund VAT, beside a table that is
    // nonetheless smaller than the headline: a real disagreement, not a refund.
    const result = deriveVatDisclosure(
      {
        tax_amount: '57.000',
        vat_breakdown: [{ vat_amount: '47.500' }],
        refund_vat_amount: '0.000',
      },
      3,
    );

    expect(result.refundVat).toBe('0.000');
    expect(result.hasRefundVat).toBe(false);
    expect(result.isReconciled).toBe(false);
  });

  it('flags a consistent shift as reconciled', () => {
    const result = deriveVatDisclosure(
      { tax_amount: '57.000', vat_breakdown: [{ vat_amount: '47.500' }] },
      3,
    );

    expect(result.isReconciled).toBe(true);
  });

  /**
   * GATE r1 M-2 — a MISSING `vat_amount` key is now a compile error
   * (`vat_breakdown: ReadonlyArray<{ vat_amount: string }>`), because every real
   * caller already supplies it and the optional widening only disarmed the type
   * system: a caller whose breakdown used different keys (the web's own
   * `{rate,net,vat,gross}` shape) would have typechecked and silently produced
   * `netVat = 0` / `refundVat = salesVat` — "VAT on refunds −57.00" on a
   * refund-free shift.
   *
   * `numericOrZero` remains, but now guards malformed VALUES only, which is what
   * it can actually be right about.
   */
  it('tolerates a malformed vat_amount value without throwing on a fiscal screen', () => {
    const result = deriveVatDisclosure(
      {
        tax_amount: '19.000',
        vat_breakdown: [{ vat_amount: '19.000' }, { vat_amount: '' }, { vat_amount: 'n/a' }],
      },
      3,
    );

    expect(result.netVat).toBe('19.000');
    expect(result.refundVat).toBe('0.000');
    expect(result.isReconciled).toBe(true);
  });
});
