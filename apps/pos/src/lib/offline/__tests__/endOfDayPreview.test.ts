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

  it('normalizes an ISO shiftOpenedAt to SQLite UTC format in the receipts query', async () => {
    // offline_receipts.created_at is `datetime('now')` format (space
    // separator, UTC); binding the raw ISO string (T separator) would
    // lexicographically exclude every same-day receipt.
    vi.mocked(queryAll).mockResolvedValue([]);

    await buildEndOfDayPreview(mockDb, 'term-1', '2026-04-23T08:00:00Z', '100', 'EUR');

    const receiptsCall = vi.mocked(queryAll).mock.calls.find(
      ([, sql]) => (sql as string).includes('FROM offline_receipts'),
    );
    expect(receiptsCall).toBeDefined();
    expect((receiptsCall![2] as unknown[])[1]).toBe('2026-04-23 08:00:00');
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

describe('buildEndOfDayPreview — cash drawer movements (H2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('folds drawer deposits (+) and payouts (−) into expected_cash when shiftId is given', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql, params) => {
      const s = sql as string;
      if (s.includes('offline_cash_drawer_ops')) {
        const shiftId = (params as unknown[] | undefined)?.[0];
        return (
          shiftId === 'shift-1'
            ? [
                { id: 'd1', type: 'deposit', amount: '20.00', shift_id: 'shift-1' },
                { id: 'd2', type: 'payout', amount: '5.00', shift_id: 'shift-1' },
              ]
            : []
        ) as unknown as never[];
      }
      if (s.includes('offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '50.00',
            subtotal: '42.02',
            tax_amount: '7.98',
            change_due: '0.00',
            payments_json: JSON.stringify([
              { payment_method_id: 'pm-cash', amount: '50.00', method_code: 'CASH' },
            ]),
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '7.98', line_total: '42.02' }]),
            created_at: '2026-06-10T10:00:00Z',
          },
        ] as unknown as never[];
      }
      if (s.includes('payment_methods')) {
        return [{ id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 }] as unknown as never[];
      }
      return [] as never[];
    });

    const preview = await buildEndOfDayPreview(
      mockDb,
      'term-1',
      '2026-06-10T08:00:00Z',
      '100',
      'EUR',
      'shift-1',
    );

    // opening 100 + net cash 50 + deposit 20 − payout 5 = 165.00
    expect(preview.expected_cash).toBe('165.00');
  });
});

describe('buildEndOfDayPreview — review fixes (refunds, training, legacy fallback)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('subtracts cash refund impact from expected_cash so the preview matches the signed Z (v3-refund-chain-integration §7.3a: refund impact now rides IN offline_receipts as a receipt_kind=refund row, not the deleted local_refund_records mechanism)', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      const s = sql as string;
      if (s.includes('offline_cash_drawer_ops') || s.includes('local_account_payment_records')) {
        return [] as never[];
      }
      if (s.includes('offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '50.00',
            subtotal: '42.02',
            tax_amount: '7.98',
            change_due: '0.00',
            payment_method_id: 'pm-cash',
            payments_json: JSON.stringify([
              { payment_method_id: 'pm-cash', amount: '50.00', method_code: 'CASH' },
            ]),
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '7.98', line_total: '42.02' }]),
            created_at: '2026-06-10T10:00:00Z',
            receipt_kind: 'sale',
          },
          {
            id: 'r2',
            // Negative-signed (§7.2) — irrelevant to this assertion (sale-only
            // gross/net/tax totals aren't checked here) but kept spec-accurate.
            total: '-10.00',
            subtotal: '-8.40',
            tax_amount: '-1.60',
            change_due: null,
            payment_method_id: 'pm-cash',
            // POSITIVE magnitude on every row, sale or refund (§7.2a) — the
            // isRefund branch in the aggregation loop is what subtracts it.
            payments_json: JSON.stringify([
              { payment_method_id: 'pm-cash', amount: '10.00', method_code: 'CASH' },
            ]),
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '-1.60', line_total: '-8.40' }]),
            created_at: '2026-06-10T10:05:00Z',
            receipt_kind: 'refund',
          },
        ] as unknown as never[];
      }
      if (s.includes('payment_methods')) {
        return [{ id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 }] as unknown as never[];
      }
      return [] as never[];
    });

    const preview = await buildEndOfDayPreview(
      mockDb, 'term-1', '2026-06-10T08:00:00Z', '100', 'EUR', 'shift-1',
    );

    // opening 100 + net cash 50 − cash refund 10 = 140.00
    expect(preview.expected_cash).toBe('140.00');
  });

  // Wave-2 review fix (TREASURY CRITICAL) — a MIXED shift with one LEGACY
  // refund (local_refund_records, restored — the default/only mechanism
  // for every terminal that has not yet completed its v4 capability
  // rollout) and one v4 refund (offline_receipts receipt_kind='refund')
  // must reduce expected_cash by BOTH exactly once each.
  it('wave-2: a MIXED shift (one legacy refund + one v4 refund) reduces expected_cash by both exactly once each', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql, params) => {
      const s = sql as string;
      if (s.includes('offline_cash_drawer_ops') || s.includes('local_account_payment_records')) {
        return [] as never[];
      }
      if (s.includes('local_refund_records')) {
        const shiftId = (params as unknown[] | undefined)?.[0];
        return (
          shiftId === 'shift-1' ? [{ id: 'legacy-1', shift_id: 'shift-1', cash_impact: '7.25' }] : []
        ) as unknown as never[];
      }
      if (s.includes('offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '50.00',
            subtotal: '42.02',
            tax_amount: '7.98',
            change_due: '0.00',
            payment_method_id: 'pm-cash',
            payments_json: JSON.stringify([
              { payment_method_id: 'pm-cash', amount: '50.00', method_code: 'CASH' },
            ]),
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '7.98', line_total: '42.02' }]),
            created_at: '2026-06-10T10:00:00Z',
            receipt_kind: 'sale',
          },
          {
            id: 'r2',
            total: '-10.00',
            subtotal: '-8.40',
            tax_amount: '-1.60',
            change_due: null,
            payment_method_id: 'pm-cash',
            payments_json: JSON.stringify([
              { payment_method_id: 'pm-cash', amount: '10.00', method_code: 'CASH' },
            ]),
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '-1.60', line_total: '-8.40' }]),
            created_at: '2026-06-10T10:05:00Z',
            receipt_kind: 'refund',
          },
        ] as unknown as never[];
      }
      if (s.includes('payment_methods')) {
        return [{ id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 }] as unknown as never[];
      }
      return [] as never[];
    });

    const preview = await buildEndOfDayPreview(
      mockDb, 'term-1', '2026-06-10T08:00:00Z', '100', 'EUR', 'shift-1',
    );

    // opening 100 + net cash 50 − v4 CASH refund 10.00 (netted in
    // cashTenderedSum) − legacy cash_impact 7.25 (restored standalone
    // cashRefundImpact term) = 132.75.
    expect(preview.expected_cash).toBe('132.75');
  });

  it('excludes training receipts from the preview (is_training = 0 in the query)', async () => {
    vi.mocked(queryAll).mockResolvedValue([] as never[]);
    await buildEndOfDayPreview(mockDb, 'term-1', '2026-06-10T08:00:00Z', '100', 'EUR', 'shift-1');

    const receiptsCall = vi
      .mocked(queryAll)
      .mock.calls.find((c) => String(c[1]).includes('FROM offline_receipts'));
    expect(receiptsCall).toBeDefined();
    expect(String(receiptsCall![1])).toMatch(/is_training\s*=\s*0/);
  });

  it('legacy fallback: a receipt without payments_json is attributed to its primary method at net total', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      const s = sql as string;
      if (s.includes('offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '50.00',
            subtotal: '42.02',
            tax_amount: '7.98',
            change_due: '5.00', // already netted into total — must NOT be subtracted again
            payment_method_id: 'pm-cash',
            payments_json: null,
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '7.98', line_total: '42.02' }]),
            created_at: '2026-06-10T10:00:00Z',
          },
        ] as unknown as never[];
      }
      if (s.includes('payment_methods')) {
        return [{ id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 }] as unknown as never[];
      }
      return [] as never[];
    });

    const preview = await buildEndOfDayPreview(mockDb, 'term-1', '2026-06-10T08:00:00Z', '100', 'EUR');

    const cash = preview.payment_methods.find((p) => p.payment_method_code === 'CASH')!;
    expect(cash.total_amount).toBe('50.00'); // net total, not 45
    expect(preview.expected_cash).toBe('150.00'); // 100 + 50 (no shiftId → no drawer/refunds)
  });

  it('counts a payment row even when its method_code is missing from payment_methods (signed-Z parity)', async () => {
    // The signed Z (zReportService aggregateReportData) keys per-method totals
    // on the raw payments_json method_code; it never drops a tender because the
    // local payment_methods table no longer lists that method (e.g. deactivated
    // and removed by sync mid-shift). The preview must not undercount vs the Z.
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      const s = sql as string;
      if (s.includes('offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '30.00',
            subtotal: '25.21',
            tax_amount: '4.79',
            change_due: '2.00',
            payment_method_id: 'pm-cash',
            payments_json: JSON.stringify([
              { method_code: 'CASH', amount: '22.00' },
              { method_code: 'CHEQUE', amount: '10.00' }, // no longer in payment_methods
            ]),
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '4.79', line_total: '25.21' }]),
            created_at: '2026-06-10T10:00:00Z',
          },
        ] as unknown as never[];
      }
      if (s.includes('payment_methods')) {
        // CHEQUE was deactivated and removed by sync after the sale
        return [{ id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 }] as unknown as never[];
      }
      return [] as never[];
    });

    const preview = await buildEndOfDayPreview(mockDb, 'term-1', '2026-06-10T08:00:00Z', '100', 'EUR');

    const cheque = preview.payment_methods.find((p) => p.payment_method_code === 'CHEQUE')!;
    expect(cheque).toBeDefined();
    expect(cheque.total_amount).toBe('10.00');
    const cash = preview.payment_methods.find((p) => p.payment_method_code === 'CASH')!;
    expect(cash.total_amount).toBe('20.00'); // 22 tendered − 2 change
    expect(preview.expected_cash).toBe('120.00'); // 100 + 22 − 2
  });

  it('still nets change and counts cash when CASH itself is missing from payment_methods (signed-Z parity)', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      const s = sql as string;
      if (s.includes('offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '10.00',
            subtotal: '8.40',
            tax_amount: '1.60',
            change_due: '5.00',
            payment_method_id: 'pm-cash',
            payments_json: JSON.stringify([{ method_code: 'CASH', amount: '15.00' }]),
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '1.60', line_total: '8.40' }]),
            created_at: '2026-06-10T10:00:00Z',
          },
        ] as unknown as never[];
      }
      if (s.includes('payment_methods')) {
        return [] as never[]; // lookup table empty — must not zero the drawer math
      }
      return [] as never[];
    });

    const preview = await buildEndOfDayPreview(mockDb, 'term-1', '2026-06-10T08:00:00Z', '100', 'EUR');

    const cash = preview.payment_methods.find((p) => p.payment_method_code === 'CASH')!;
    expect(cash).toBeDefined();
    expect(cash.total_amount).toBe('10.00'); // 15 tendered − 5 change
    expect(preview.expected_cash).toBe('110.00'); // 100 + 15 − 5
  });

  it('legacy fallback buckets an unknown primary method as UNKNOWN instead of dropping it (signed-Z parity)', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      const s = sql as string;
      if (s.includes('offline_receipts')) {
        return [
          {
            id: 'r1',
            total: '40.00',
            subtotal: '33.61',
            tax_amount: '6.39',
            change_due: '0',
            payment_method_id: 'pm-gone',
            payments_json: null,
            lines: JSON.stringify([{ tax_rate: '19', tax_amount: '6.39', line_total: '33.61' }]),
            created_at: '2026-06-10T10:00:00Z',
          },
        ] as unknown as never[];
      }
      if (s.includes('payment_methods')) {
        return [{ id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 }] as unknown as never[];
      }
      return [] as never[];
    });

    const preview = await buildEndOfDayPreview(mockDb, 'term-1', '2026-06-10T08:00:00Z', '100', 'EUR');

    // The signed Z buckets this under 'UNKNOWN' (paymentMethodMap miss) — the
    // preview must mirror that, not silently drop the sale from the breakdown.
    const unknown = preview.payment_methods.find((p) => p.payment_method_code === 'UNKNOWN')!;
    expect(unknown).toBeDefined();
    expect(unknown.total_amount).toBe('40.00');
    expect(preview.expected_cash).toBe('100.00'); // not cash → drawer unchanged
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

// ── Cash rounding + tolerance (spec §4.3 / §8.1, Task 10) ───────────────────
describe('buildEndOfDayPreview — cash rounding + tolerance (Task 10)', () => {
    interface SeedRow {
      total: string;
      cash_rounding_adjustment?: string | null;
      tolerance_shortfall?: string | null;
    }

    /** `tolerance_auto_accepts.accept_count` for `shift-1`; null = no row. */
    let autoAcceptCount: number | null = null;

    function mockShift(rows: SeedRow[]): void {
      autoAcceptCount = null;
      installMocks(rows);
    }

    function installMocks(rows: SeedRow[]): void {
      vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
        const s = sql as string;
        if (s.includes('tolerance_auto_accepts')) {
          return (autoAcceptCount === null
            ? []
            : [{ accept_count: autoAcceptCount }]) as unknown as never[];
        }
        if (s.includes('FROM offline_receipts')) {
          return rows.map((row, idx) => ({
            id: `r${String(idx)}`,
            total: row.total,
            subtotal: row.total,
            tax_amount: '0.00',
            payments_json: JSON.stringify([
              { payment_method_id: 'pm-cash', amount: row.total, method_code: 'CASH' },
            ]),
            lines: JSON.stringify([]),
            created_at: '2026-07-27 10:00:00',
            change_due: '0.00',
            payment_method_id: 'pm-cash',
            cash_rounding_adjustment: row.cash_rounding_adjustment ?? null,
            tolerance_shortfall: row.tolerance_shortfall ?? null,
          })) as unknown as never[];
        }
        if (s.includes('FROM payment_methods')) {
          return [{ id: 'pm-cash', code: 'CASH', name: 'Cash', is_physical: 1 }] as unknown as never[];
        }
        return [] as unknown as never[];
      });
    }

    const build = () =>
      buildEndOfDayPreview(mockDb, 'term-1', '2026-07-27T08:00:00Z', '0', 'EUR', 'shift-1');

    it('selects the Task 9 receipt columns (an implicit column list would drop them)', async () => {
      mockShift([]);

      await build();

      const receiptsCall = vi.mocked(queryAll).mock.calls.find(
        ([, sql]) => (sql as string).includes('FROM offline_receipts'),
      );
      expect(String(receiptsCall![1])).toContain('cash_rounding_adjustment');
      expect(String(receiptsCall![1])).toContain('tolerance_shortfall');
    });

    it('aggregates cash_rounding_adjustment into a signed cash_rounding_summary', async () => {
      mockShift([
        { total: '10.00', cash_rounding_adjustment: '0.02' },
        { total: '9.95', cash_rounding_adjustment: '-0.03' },
        { total: '5.00' },
      ]);

      const preview = await build();

      expect(preview.cash_rounding_summary).toEqual({
        totalAdjustment: '-0.01',
        receiptCount: 2,
      });
    });

    it('emits a null cash_rounding_summary when nothing rounded', async () => {
      mockShift([{ total: '10.00' }]);

      const preview = await build();

      expect(preview.cash_rounding_summary).toBeNull();
    });

    it('derives tolerance_summary from the receipt column, not the dead payments_json field', async () => {
      mockShift([
        { total: '9.95', tolerance_shortfall: '0.05' },
        { total: '10.00' },
      ]);

      const preview = await build();

      expect(preview.tolerance_summary).toEqual({
        totalAmount: '0.05',
        writeoffCount: 1,
        currencyCode: 'EUR',
      });
    });

    it('reports the DURABLE §8.1 budget spend, not the receipt-derived write-off count', async () => {
      // The two numbers answer different questions. `writeoffCount` counts the
      // shift's non-voided, non-training receipts carrying a shortfall;
      // `tolerance_auto_accept_count` is the authority the gate itself reads
      // (paymentStore -> getToleranceAutoAcceptCount), and it also charges
      // training-mode and later-voided sales. Surfacing the receipt count as
      // the budget figure would tell a cashier they had headroom the gate has
      // already spent.
      mockShift([{ total: '9.95', tolerance_shortfall: '0.05' }]);
      autoAcceptCount = 4;

      const preview = await build();

      expect(preview.tolerance_auto_accept_count).toBe(4);
      expect(preview.tolerance_summary?.writeoffCount).toBe(1);
    });

    it('reads the budget by shift_id (no timestamp bind, so no SQLite TEXT boundary)', async () => {
      mockShift([]);
      autoAcceptCount = 2;

      await build();

      const budgetCall = vi.mocked(queryAll).mock.calls.find(
        ([, sql]) => (sql as string).includes('tolerance_auto_accepts'),
      );
      expect(budgetCall).toBeDefined();
      expect((budgetCall![2] as unknown[])).toEqual(['shift-1']);
      expect(String(budgetCall![1])).not.toMatch(/updated_at/);
    });

    it('reports UNKNOWN (null), not zero, when the preview is built without a shift id', async () => {
      // Zero would render as full headroom, but the GATE reads a null shift as
      // the budget FULLY SPENT (paymentStore's fail-closed inversion) — so the
      // screen would promise budget the next short tender is refused. Null is
      // the only reading that cannot mislead in either direction.
      mockShift([]);
      autoAcceptCount = 7;

      const preview = await buildEndOfDayPreview(
        mockDb, 'term-1', '2026-07-27T08:00:00Z', '0', 'EUR',
      );

      expect(preview.tolerance_auto_accept_count).toBeNull();
    });
});
