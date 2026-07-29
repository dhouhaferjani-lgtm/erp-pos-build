/**
 * Legacy-shape hash stability pin for zReportHashService (spec §4.3, Task 10).
 *
 * `cash_rounding_summary` is normalized ADDITIVELY: per-key and isset-guarded,
 * NOT behind a `schema_version` bump. Bumping the version would re-normalize
 * `refunds_amount` (and the voucher keys) through the schema≥3 ladder in
 * `ZReportHashService.php` and break parity with the server for every existing
 * v2-shape Z.
 *
 * The frozen snapshot below was written against the implementation BEFORE the
 * additive block was added and committed separately, so a re-run after the
 * change is the parity proof: if the snapshot still matches, a report_data with
 * no `cash_rounding_summary` key hashes byte-identically forever.
 */

import { describe, expect, it } from 'vitest';
import { computeZReportHash, normalizeForHash } from '@/lib/fiscal/zReportHashService';

const legacyReportData = {
  schema_version: 2,
  sales_count: 3,
  gross_sales: '100.5',
  net_sales: '90.5',
  tax_amount: '10.0',
  refunds_count: 0,
  refunds_amount: '0.0',
  voided_count: 0,
  vat_breakdown: [],
  payment_methods: [{ payment_type: 'CASH', total_amount: '100.5', transaction_count: 3 }],
  opening_cash: '50.0',
  expected_cash: '150.5',
  variance: null,
  tolerance_summary: { totalAmount: '0.0', currencyCode: 'TND', writeoffCount: 0 },
};

describe('zReportHashService — additive normalization is legacy-safe', () => {
  it('a report_data WITHOUT cash_rounding_summary hashes to the frozen value', async () => {
    const hash = await computeZReportHash({
      previousHash: 'GENESIS',
      zNumber: 1,
      terminalId: '11111111-1111-1111-1111-111111111111',
      generatedAt: '2026-07-27T18:00:00+00:00',
      reportData: legacyReportData as never,
    });
    // Frozen on the pre-change implementation; ANY drift here means a
    // legacy-shape Z would re-hash differently after this change.
    expect(hash).toMatchSnapshot();
  });

  it('normalizes cash_rounding_summary.total_adjustment to scale 3 when present', () => {
    const out = normalizeForHash({
      ...legacyReportData,
      cash_rounding_summary: { total_adjustment: '-0.02', receipt_count: 4 },
    });
    expect((out['cash_rounding_summary'] as Record<string, unknown>)['total_adjustment'])
      .toBe('-0.020');
  });

  it('leaves a report_data without the key untouched', () => {
    const out = normalizeForHash({ ...legacyReportData });
    expect(out).not.toHaveProperty('cash_rounding_summary');
  });
});
