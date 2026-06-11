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
              { payment_method_id: 'pm-cash', amount: '10.00', method_code: 'CASH' },
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
              { payment_method_id: 'pm-card', amount: '20.00', method_code: 'CARD' },
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
              { payment_method_id: 'pm-cash', amount: '10.00', method_code: 'CASH' },
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

describe('buildEndOfDayPreview — writer-shape regression (B1)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  // B1: the reader must consume payments_json in the SHAPE THE WRITER ACTUALLY
  // PERSISTS (receiptService.ts): each payment row carries `method_code` (not
  // `payment_method_code`), and `change_due` lives at the RECEIPT level (the
  // offline_receipts.change_due column), not inside the payment row. The old
  // tests fed the reader's (buggy) expected shape, so they passed while
  // production silently skipped every payment and stamped expected_cash =
  // opening float on every shift close.
  it('counts CASH tendered and subtracts receipt-level change_due (over-tender)', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      if ((sql as string).includes('FROM offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '100.00',
            subtotal: '84.03',
            tax_amount: '15.97',
            // Customer tendered 105.00 cash on a 100.00 sale → 5.00 change.
            change_due: '5.00',
            // Real writer shape: method_code, amount = physically tendered.
            payments_json: JSON.stringify([
              { payment_method_id: 'pm-cash', amount: '105.00', method_code: 'CASH' },
            ]),
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '15.97', line_total: '84.03' }]),
            created_at: '2026-06-10T10:00:00Z',
          },
        ] as unknown as never[];
      }
      if ((sql as string).includes('FROM payment_methods')) {
        return [{ id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 }] as unknown as never[];
      }
      return [] as never[];
    });

    const preview = await buildEndOfDayPreview(mockDb, 'term-1', '2026-06-10T08:00:00Z', '100', 'EUR');

    const cash = preview.payment_methods.find((p) => p.payment_method_code === 'CASH')!;
    expect(cash).toBeDefined();
    // Net cash retained in the drawer (tendered 105 − change 5), per
    // NF525/DSFinV-K — change is netted into the cash figure, not gross tender.
    expect(cash.total_amount).toBe('100.00');
    expect(cash.transaction_count).toBe(1);
    // expected_cash = 100 (opening) + 100.00 (net cash in drawer) = 200.00
    expect(preview.expected_cash).toBe('200.00');
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
              { payment_method_id: 'pm-card', amount: '30.00', method_code: 'CARD' },
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
