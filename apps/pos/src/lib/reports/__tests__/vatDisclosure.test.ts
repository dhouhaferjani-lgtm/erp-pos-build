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

  it('flags a consistent shift as reconciled', () => {
    const result = deriveVatDisclosure(
      { tax_amount: '57.000', vat_breakdown: [{ vat_amount: '47.500' }] },
      3,
    );

    expect(result.isReconciled).toBe(true);
  });

  it('tolerates a missing or malformed vat_amount without throwing on a fiscal screen', () => {
    const result = deriveVatDisclosure(
      {
        tax_amount: '19.000',
        vat_breakdown: [{ vat_amount: '19.000' }, {} as { vat_amount?: string }],
      },
      3,
    );

    expect(result.netVat).toBe('19.000');
    expect(result.refundVat).toBe('0.000');
  });
});
