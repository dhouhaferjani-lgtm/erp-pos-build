import { describe, it, expect } from 'vitest';
import { buildReportDataForHash } from '../zReportService';

const shift = {
  id: 'shift-1',
  terminal_id: 'term-1',
  opening_cash: '10.00',
  opened_at: '2026-04-24T08:00:00Z',
  currency_code: 'EUR',
};

describe('buildReportDataForHash', () => {
  it('stamps schema_version: 2', () => {
    const data = buildReportDataForHash(shift, [], {
      gross_sales: '0.00', net_sales: '0.00', tax_amount: '0.00',
    });
    expect(data.schema_version).toBe(2);
  });

  it('normalizes opening_cash to scale 4', () => {
    const data = buildReportDataForHash(shift, [], {
      gross_sales: '0.00', net_sales: '0.00', tax_amount: '0.00',
    });
    expect(data.opening_cash).toBe('10.0000');
  });

  it('normalizes each cash_counts row to scale 4 and preserves currency_code', () => {
    const data = buildReportDataForHash(shift, [{
      payment_method_id: 'pm-1',
      currency_code: 'EUR',
      expected_amount: '830.00',
      actual_amount: '830.00',
      variance_amount: '0.00',
    }], { gross_sales: '0.00', net_sales: '0.00', tax_amount: '0.00' });
    const row = data.cash_counts[0]!;
    expect(row.expected_amount).toBe('830.0000');
    expect(row.actual_amount).toBe('830.0000');
    expect(row.variance_amount).toBe('0.0000');
    expect(row.currency_code).toBe('EUR');
    expect(row.payment_method_id).toBe('pm-1');
  });

  it('emits JSON with canonical key order (schema_version first, cash_counts last at top level)', () => {
    const data = buildReportDataForHash(shift, [], {
      gross_sales: '0.00', net_sales: '0.00', tax_amount: '0.00',
    });
    const keys = Object.keys(data);
    expect(keys[0]).toBe('schema_version');
    expect(keys[keys.length - 1]).toBe('cash_counts');
    // Strict order:
    expect(keys).toEqual([
      'schema_version', 'opening_cash', 'gross_sales', 'net_sales', 'tax_amount', 'cash_counts'
    ]);
  });

  it('cash_counts row has canonical key order (payment_method_id, currency_code, expected_amount, actual_amount, variance_amount)', () => {
    const data = buildReportDataForHash(shift, [{
      payment_method_id: 'pm-1',
      currency_code: 'EUR',
      expected_amount: '100.00',
      actual_amount: '100.00',
      variance_amount: '0.00',
    }], { gross_sales: '0.00', net_sales: '0.00', tax_amount: '0.00' });
    expect(Object.keys(data.cash_counts[0]!)).toEqual([
      'payment_method_id', 'currency_code', 'expected_amount', 'actual_amount', 'variance_amount'
    ]);
  });
});
