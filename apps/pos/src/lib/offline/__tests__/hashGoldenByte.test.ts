import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { buildReportDataForHash } from '../zReportService';

describe('hashGoldenByte — PHP ↔ JS JSON byte equivalence', () => {
  it('POS side produces bytes identical to the shared fixture expectation', () => {
    // Fixture lives at apps/pos/tests/fixtures/z-report-v2-eur.json relative to apps/pos root.
    const fixturePath = join(__dirname, '../../../../tests/fixtures/z-report-v2-eur.json');
    const fixture = JSON.parse(readFileSync(fixturePath, 'utf-8'));

    const reportData = buildReportDataForHash(
      fixture.shift,
      fixture.cash_counts,
      fixture.totals,
    );

    const expected = '{"schema_version":2,"opening_cash":"10.0000","gross_sales":"1432.5000","net_sales":"1203.7800","tax_amount":"228.7200","cash_counts":[{"payment_method_id":"11111111-1111-1111-1111-111111111111","currency_code":"EUR","expected_amount":"830.0000","actual_amount":"830.0000","variance_amount":"0.0000"}]}';

    expect(JSON.stringify(reportData)).toBe(expected);
  });
});
