import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mocks must be hoisted before imports of the modules under test.
vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: () => ({
      companies: [{ id: 'company-1', currency: 'EUR' }],
      companyId: 'company-1',
    }),
  },
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
            payment_method_id: 'pm-cash',
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
            payment_method_id: 'pm-card',
            lines: JSON.stringify([
              { tax_rate: '19', tax_amount: '3.19', line_total: '16.81' },
            ]),
            created_at: '2026-04-23T10:05:00Z',
          },
        ];
      }
      if ((sql as string).includes('FROM payment_methods')) {
        return [
          { id: 'pm-cash', code: 'CASH' },
          { id: 'pm-card', code: 'CARD' },
        ];
      }
      return [];
    });

    const preview = await buildEndOfDayPreview(mockDb, 'term-1', '2026-04-23T08:00:00Z', 100);

    expect(preview.sales_count).toBe(2);
    expect(preview.gross_sales).toBe('30.00');
    expect(preview.net_sales).toBe('25.21');
    expect(preview.tax_amount).toBe('4.79');
    expect(preview.opening_cash).toBe('100.00');
    // expected cash = 100 (opening) + 10 (CASH sale only)
    expect(preview.expected_cash).toBe('110.00');
    expect(preview.variance).toBeNull();

    // VAT breakdown: one rate (19%), combined from both receipts
    expect(preview.vat_breakdown).toHaveLength(1);
    expect(preview.vat_breakdown[0].tax_rate).toBe(19);
    expect(preview.vat_breakdown[0].net_amount).toBe('25.21');
    expect(preview.vat_breakdown[0].vat_amount).toBe('4.79');
    expect(preview.vat_breakdown[0].gross_amount).toBe('30.00');

    // Payment methods: CASH + CARD
    expect(preview.payment_methods).toHaveLength(2);

    const cashMethod = preview.payment_methods.find((p) => p.payment_type === 'CASH');
    const cardMethod = preview.payment_methods.find((p) => p.payment_type === 'CARD');
    expect(cashMethod).toBeDefined();
    expect(cardMethod).toBeDefined();
    expect(cashMethod?.total_amount).toBe('10.00');
    expect(cardMethod?.total_amount).toBe('20.00');
  });

  it('returns a preview with sales_count=0 when there are no receipts (no throw)', async () => {
    vi.mocked(queryAll).mockResolvedValue([]);

    const preview = await buildEndOfDayPreview(mockDb, 'term-1', '2026-04-23T08:00:00Z', 100);

    expect(preview.sales_count).toBe(0);
    expect(preview.gross_sales).toBe('0.00');
    expect(preview.net_sales).toBe('0.00');
    expect(preview.tax_amount).toBe('0.00');
    expect(preview.opening_cash).toBe('100.00');
    // No cash sales → expected = opening
    expect(preview.expected_cash).toBe('100.00');
    expect(preview.vat_breakdown).toHaveLength(0);
    expect(preview.payment_methods).toHaveLength(0);
  });
});
