import { describe, it, expect, vi } from 'vitest';
import { buildEndOfDayPreview } from '../endOfDayPreview';

vi.mock('@/lib/db', () => ({ queryAll: vi.fn() }));
import { queryAll } from '@/lib/db';

describe('buildEndOfDayPreview — cash-tendered formula (TND multi-receipt with tolerance)', () => {
  it('uses pos_receipt_payments.amount via payments_json, not receipt.total', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      if (sql.includes('FROM offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '100.120',
            subtotal: '84.740',
            tax_amount: '15.380',
            payments_json: JSON.stringify([
              { payment_method_code: 'CASH', amount: '100.100', change_due: '0.000', tolerance_writeoff: '0.020' },
            ]),
            lines: JSON.stringify([]),
            created_at: '2026-04-24T10:00:00Z',
          },
          {
            id: 'r2',
            total: '50.000',
            subtotal: '42.370',
            tax_amount: '7.630',
            payments_json: JSON.stringify([
              { payment_method_code: 'CASH', amount: '50.000', change_due: '0.000', tolerance_writeoff: '0.000' },
            ]),
            lines: JSON.stringify([]),
            created_at: '2026-04-24T10:05:00Z',
          },
        ] as unknown as never[];
      }
      if (sql.includes('FROM payment_methods')) {
        return [{ id: 'pm-cash', code: 'CASH', is_physical: 1 }] as unknown as never[];
      }
      return [] as never[];
    });

    const preview = await buildEndOfDayPreview(
      {} as never,
      'term-1',
      '2026-04-24T08:00:00Z',
      '100.000',
      'TND',
    );

    // expected_cash = 100.000 + 100.100 + 50.000 − 0 − 0 = 250.100
    expect(preview.expected_cash).toBe('250.100');
    expect(preview.sales_count).toBe(2);
    expect(preview.gross_sales).toBe('150.120');
    expect(preview.net_sales).toBe('127.110');
    expect(preview.tax_amount).toBe('23.010');
    expect(preview.tolerance_summary?.totalAmount).toBe('0.020');
    expect(preview.tolerance_summary?.writeoffCount).toBe(1);
    expect(preview.tolerance_summary?.currencyCode).toBe('TND');
  });

  it('returns empty preview when there are no receipts', async () => {
    vi.mocked(queryAll).mockResolvedValue([] as never[]);
    const preview = await buildEndOfDayPreview(
      {} as never, 'term-1', '2026-04-24T08:00:00Z', '50.000', 'TND'
    );
    expect(preview.sales_count).toBe(0);
    expect(preview.expected_cash).toBe('50.000');
    expect(preview.tolerance_summary).toBeNull();
  });
});
