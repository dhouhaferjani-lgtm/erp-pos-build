import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mocks must be hoisted before imports of the modules under test.
vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
}));

vi.mock('@/lib/currency', () => ({
  getCurrencyDecimals: vi.fn().mockReturnValue(2),
}));

import { buildEndOfDayPreview } from '../endOfDayPreview';
import { queryAll } from '@/lib/db';

import type Database from '@tauri-apps/plugin-sql';

const mockDb = {} as Database;

describe('buildEndOfDayPreview', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('aggregates receipts into totals, VAT breakdown, payments, and expected cash', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      if ((sql as string).includes('FROM offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '10.00',
            subtotal: '8.40',
            tax_amount: '1.60',
            payments_json: JSON.stringify([
              { payment_method_code: 'CASH', amount: '10.00', change_due: '0.00', tolerance_writeoff: '0.00' },
            ]),
            lines: JSON.stringify([
              { tax_rate: '19', tax_amount: '1.60', line_total: '8.40' },
            ]),
            created_at: '2026-04-23T10:00:00Z',
          },
          {
            id: 'r2',
            total: '20.00',
            subtotal: '16.81',
            tax_amount: '3.19',
            payments_json: JSON.stringify([
              { payment_method_code: 'CARD', amount: '20.00', change_due: '0.00', tolerance_writeoff: '0.00' },
            ]),
            lines: JSON.stringify([
              { tax_rate: '19', tax_amount: '3.19', line_total: '16.81' },
            ]),
            created_at: '2026-04-23T10:05:00Z',
          },
        ];
      }
      if ((sql as string).includes('FROM payment_methods')) {
        return [
          { id: 'pm-cash', code: 'CASH', is_physical: 1 },
          { id: 'pm-card', code: 'CARD', is_physical: 0 },
        ];
      }
      return [];
    });

    const preview = await buildEndOfDayPreview(mockDb, 'term-1', '2026-04-23T08:00:00Z', '100', 'EUR');

    expect(preview.sales_count).toBe(2);
    expect(preview.gross_sales).toBe('30.00');
    expect(preview.net_sales).toBe('25.21');
    expect(preview.tax_amount).toBe('4.79');
    expect(preview.opening_cash).toBe('100.00');
    // expected cash = 100 (opening) + 10 (CASH tendered only)
    expect(preview.expected_cash).toBe('110.00');
    expect(preview.variance).toBeNull();
    expect(preview.tolerance_summary).toBeNull();

    // VAT breakdown: one rate (19%), combined from both receipts
    expect(preview.vat_breakdown).toHaveLength(1);
    const vatRow = preview.vat_breakdown[0]!;
    expect(vatRow.tax_rate).toBe(19);
    expect(vatRow.net_amount).toBe('25.21');
    expect(vatRow.vat_amount).toBe('4.79');
    expect(vatRow.gross_amount).toBe('30.00');

    // Payment methods: CASH + CARD
    expect(preview.payment_methods).toHaveLength(2);

    const cashMethod = preview.payment_methods.find((p) => p.payment_method_code === 'CASH')!;
    const cardMethod = preview.payment_methods.find((p) => p.payment_method_code === 'CARD')!;
    expect(cashMethod).toBeDefined();
    expect(cardMethod).toBeDefined();
    expect(cashMethod.total_amount).toBe('10.00');
    expect(cardMethod.total_amount).toBe('20.00');
    expect(cashMethod.is_physical).toBe(true);
    expect(cardMethod.is_physical).toBe(false);
  });

  it('returns a preview with sales_count=0 when there are no receipts (no throw)', async () => {
    vi.mocked(queryAll).mockResolvedValue([]);

    const preview = await buildEndOfDayPreview(mockDb, 'term-1', '2026-04-23T08:00:00Z', '100', 'EUR');

    expect(preview.sales_count).toBe(0);
    expect(preview.gross_sales).toBe('0.00');
    expect(preview.net_sales).toBe('0.00');
    expect(preview.tax_amount).toBe('0.00');
    expect(preview.opening_cash).toBe('100.00');
    // No cash sales → expected = opening
    expect(preview.expected_cash).toBe('100.00');
    expect(preview.vat_breakdown).toHaveLength(0);
    expect(preview.payment_methods).toHaveLength(0);
    expect(preview.tolerance_summary).toBeNull();
  });

  it('exposes payment_method_name on methods touched by transactions', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      if ((sql as string).includes('FROM offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '10.00',
            subtotal: '8.40',
            tax_amount: '1.60',
            payments_json: JSON.stringify([
              { payment_method_code: 'CASH', amount: '10.00', change_due: '0.00', tolerance_writeoff: '0.00' },
            ]),
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '1.60', line_total: '8.40' }]),
            created_at: '2026-04-24T10:00:00Z',
          },
        ];
      }
      if ((sql as string).includes('FROM payment_methods')) {
        return [
          { id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 },
          { id: 'pm-card', code: 'CARD', name: 'Card', is_physical: 0 },
        ];
      }
      return [];
    });

    const preview = await buildEndOfDayPreview(mockDb, 'term-1', '2026-04-24T08:00:00Z', '50', 'EUR');

    const cash = preview.payment_methods.find((p) => p.payment_method_code === 'CASH')!;
    expect(cash).toBeDefined();
    expect(cash.payment_method_name).toBe('Cash');
  });
});

describe('buildEndOfDayPreview — physical-method seeding (D2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('includes enabled physical methods with zero rows when no transactions touched them', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      if ((sql as string).includes('FROM offline_receipts')) return [];
      if ((sql as string).includes('FROM payment_methods')) {
        return [
          { id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 },
          { id: 'pm-cheque', code: 'CHEQUE', name: 'Cheques', is_physical: 1 },
          { id: 'pm-card', code: 'CARD', name: 'Card', is_physical: 0 },
        ];
      }
      return [];
    });

    const preview = await buildEndOfDayPreview(
      mockDb,
      'term-1',
      '2026-04-24T08:00:00Z',
      '100.000',
      'EUR',
    );

    const codes = preview.payment_methods.map((p) => p.payment_method_code).sort();
    expect(codes).toEqual(['CASH', 'CHEQUE']); // physical only, both present
    const cash = preview.payment_methods.find((p) => p.payment_method_code === 'CASH')!;
    expect(cash.transaction_count).toBe(0);
    expect(cash.payment_method_name).toBe('Cash');
  });

  it('seeds zero-transaction physical methods alongside methods that did have transactions', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      if ((sql as string).includes('FROM offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '30.00',
            subtotal: '25.21',
            tax_amount: '4.79',
            payments_json: JSON.stringify([
              { payment_method_code: 'CARD', amount: '30.00', change_due: '0.00', tolerance_writeoff: '0.00' },
            ]),
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '4.79', line_total: '25.21' }]),
            created_at: '2026-04-24T10:00:00Z',
          },
        ];
      }
      if ((sql as string).includes('FROM payment_methods')) {
        return [
          { id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 },
          { id: 'pm-card', code: 'CARD', name: 'Card Terminal', is_physical: 1 },
          { id: 'pm-online', code: 'ONLINE', name: 'Online', is_physical: 0 },
        ];
      }
      return [];
    });

    const preview = await buildEndOfDayPreview(
      mockDb,
      'term-1',
      '2026-04-24T08:00:00Z',
      '0',
      'EUR',
    );

    const codes = preview.payment_methods.map((p) => p.payment_method_code).sort();
    expect(codes).toEqual(['CARD', 'CASH']); // both physical; ONLINE excluded
    const cash = preview.payment_methods.find((p) => p.payment_method_code === 'CASH')!;
    expect(cash.transaction_count).toBe(0);
    expect(cash.total_amount).toBe('0.00');
    expect(cash.payment_method_name).toBe('Cash');
    const card = preview.payment_methods.find((p) => p.payment_method_code === 'CARD')!;
    expect(card.transaction_count).toBe(1);
    expect(card.total_amount).toBe('30.00');
    expect(card.payment_method_name).toBe('Card Terminal');
  });
});
