import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mocks must be hoisted before imports of the module under test (same pattern
// as endOfDayPreview.test.ts).
vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
}));

vi.mock('@/lib/db/repositories/localRefundRecordRepository', () => ({
  getRefundRecordsForShift: vi.fn(),
}));

import {
  combineRefundTotals,
  filterTickets,
  loadLegacyRefundTotalsForPeriod,
  loadLegacyRefundTotalsForShift,
  loadSalesHistoryTickets,
  netOfRefunds,
  paymentSharePercent,
  periodStartIso,
  summarizeRefunds,
} from '../salesHistory';
import type { SalesHistoryTicket } from '../salesHistory';
import { queryAll } from '@/lib/db';
import { getRefundRecordsForShift } from '@/lib/db/repositories/localRefundRecordRepository';

import type Database from '@tauri-apps/plugin-sql';

const mockDb = {} as Database;

/** Two sales (one cash, one split card+voucher) and one cash refund. */
function seedRows() {
  vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
    if ((sql as string).includes('FROM offline_receipts')) {
      return [
        {
          id: 'r3',
          receipt_number: 'T-1044',
          created_at: '2026-08-21 10:05:00',
          operator_name: 'Ines Trabelsi',
          lines: JSON.stringify([{ id: 'l1' }, { id: 'l2' }, { id: 'l3' }]),
          payments_json: JSON.stringify([
            { method_code: 'CARD', amount: '100.000' },
            { method_code: 'VOUCHER', amount: '20.000' },
          ]),
          payment_method_id: 'pm-card',
          total: '120.000',
          receipt_kind: 'sale',
        },
        {
          id: 'r2',
          receipt_number: 'T-1043',
          created_at: '2026-08-21 09:32:00',
          operator_name: 'Yasmine B.',
          lines: JSON.stringify([{ id: 'l1' }]),
          payments_json: JSON.stringify([{ method_code: 'CASH', amount: '50.000' }]),
          payment_method_id: 'pm-cash',
          total: '48.900',
          receipt_kind: 'sale',
        },
        {
          id: 'r1',
          receipt_number: 'T-1042R',
          created_at: '2026-08-21 09:18:00',
          operator_name: 'Yasmine B.',
          lines: JSON.stringify([{ id: 'l1' }, { id: 'l2' }]),
          payments_json: JSON.stringify([{ method_code: 'CASH', amount: '12.500' }]),
          payment_method_id: 'pm-cash',
          total: '-12.500',
          receipt_kind: 'refund',
        },
      ];
    }
    if ((sql as string).includes('FROM payment_methods')) {
      return [
        { id: 'pm-cash', code: 'CASH', name: 'Espèces' },
        { id: 'pm-card', code: 'CARD', name: 'Carte' },
        { id: 'pm-voucher', code: 'VOUCHER', name: 'Bon' },
      ];
    }
    return [];
  });
}

describe('periodStartIso', () => {
  const now = new Date('2026-08-21T15:30:00Z');

  it('returns the shift opening for the shift period', () => {
    expect(periodStartIso('shift', now, '2026-08-21T06:00:00Z')).toBe('2026-08-21T06:00:00Z');
  });

  it('returns null for the shift period when no shift is open', () => {
    expect(periodStartIso('shift', now, null)).toBeNull();
  });

  it('returns local midnight for today and 7 local days back for week', () => {
    const today = periodStartIso('today', now, null);
    const week = periodStartIso('week', now, null);
    expect(today).not.toBeNull();
    expect(week).not.toBeNull();
    const todayDate = new Date(today as string);
    const weekDate = new Date(week as string);
    expect(todayDate.getHours()).toBe(0);
    expect(todayDate.getMinutes()).toBe(0);
    expect(weekDate.getHours()).toBe(0);
    // 6 days earlier => a 7-day inclusive window.
    expect(Math.round((todayDate.getTime() - weekDate.getTime()) / 86_400_000)).toBe(6);
  });
});

describe('loadSalesHistoryTickets', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('maps offline_receipts rows into display tickets', async () => {
    seedRows();

    const tickets = await loadSalesHistoryTickets(mockDb, 'term-1', '2026-08-21T06:00:00Z');

    expect(tickets).toHaveLength(3);
    expect(tickets[0]).toMatchObject({
      id: 'r3',
      receiptNumber: 'T-1044',
      operatorName: 'Ines Trabelsi',
      itemCount: 3,
      isRefund: false,
      total: '120.000',
    });
    // Split tender => more than one distinct code, no single label.
    expect(tickets[0]?.methodCodes).toEqual(['CARD', 'VOUCHER']);
    expect(tickets[0]?.methodLabel).toBeNull();

    // Single tender => the payment method's display name, not the raw code.
    expect(tickets[1]?.methodCodes).toEqual(['CASH']);
    expect(tickets[1]?.methodLabel).toBe('Espèces');

    // Refund rows are kept, flagged, and keep their already-negative total.
    expect(tickets[2]).toMatchObject({ isRefund: true, total: '-12.500' });
  });

  it('binds the period boundary as a SQLite UTC string, never as ISO 8601', async () => {
    seedRows();

    await loadSalesHistoryTickets(mockDb, 'term-1', '2026-08-21T06:00:00Z');

    const receiptCall = vi
      .mocked(queryAll)
      .mock.calls.find((call) => (call[1] as string).includes('FROM offline_receipts'));
    expect(receiptCall).toBeDefined();
    const sql = receiptCall?.[1] as string;
    const params = receiptCall?.[2] as unknown[];
    expect(params).toEqual(['term-1', '2026-08-21 06:00:00']);
    // Voided and training receipts must never reach a manager report — the
    // same filter the Z / end-of-day paths apply.
    expect(sql).toContain('voided = 0');
    expect(sql).toContain('is_training = 0');
  });

  it('falls back to the denormalised primary method when payments_json is empty', async () => {
    vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
      if ((sql as string).includes('FROM offline_receipts')) {
        return [
          {
            id: 'r9',
            receipt_number: 'T-9',
            created_at: '2026-08-21 11:00:00',
            operator_name: 'Yasmine B.',
            lines: '[]',
            payments_json: '[]',
            payment_method_id: 'pm-cash',
            total: '10.000',
            receipt_kind: 'sale',
          },
        ];
      }
      if ((sql as string).includes('FROM payment_methods')) {
        return [{ id: 'pm-cash', code: 'CASH', name: 'Espèces' }];
      }
      return [];
    });

    const tickets = await loadSalesHistoryTickets(mockDb, 'term-1', '2026-08-21T06:00:00Z');

    expect(tickets[0]?.methodCodes).toEqual(['CASH']);
    expect(tickets[0]?.methodLabel).toBe('Espèces');
    expect(tickets[0]?.itemCount).toBe(0);
  });
});

describe('summarizeRefunds', () => {
  it('counts refund tickets and reports their POSITIVE magnitude', () => {
    const tickets: SalesHistoryTicket[] = [
      { ...baseTicket(), id: 'a', isRefund: false, total: '48.900' },
      { ...baseTicket(), id: 'b', isRefund: true, total: '-12.500' },
      { ...baseTicket(), id: 'c', isRefund: true, total: '-3.250' },
    ];

    expect(summarizeRefunds(tickets, 3)).toEqual({ count: 2, amount: '15.750' });
  });

  it('reports a scale-formatted zero when there are no refunds', () => {
    expect(summarizeRefunds([{ ...baseTicket(), total: '10.000' }], 3)).toEqual({
      count: 0,
      amount: '0.000',
    });
  });
});

describe('legacy refund folding (pre-v4 refunds never write offline_receipts)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('reads legacy refunds for a period by terminal and created_at, bound as SQLite UTC', async () => {
    vi.mocked(queryAll).mockResolvedValue([{ total: '-12.500' }, { total: '-3.250' }]);

    const totals = await loadLegacyRefundTotalsForPeriod(
      mockDb,
      'term-1',
      '2026-08-21T06:00:00Z',
      3,
    );

    expect(totals).toEqual({ count: 2, amount: '15.750' });
    const [, sql, params] = vi.mocked(queryAll).mock.calls[0] as [unknown, string, unknown[]];
    expect(sql).toContain('local_refund_records');
    // created_at (the local write instant), NOT settled_at: settled_at is server
    // ISO 8601 with a `T`, which cannot be compared against a SQLite UTC bound.
    expect(sql).toContain('created_at');
    expect(sql).not.toContain('settled_at');
    expect(params).toEqual(['term-1', '2026-08-21 06:00:00']);
  });

  it('reads legacy refunds for a shift through the shift-keyed repository', async () => {
    vi.mocked(getRefundRecordsForShift).mockResolvedValue([
      { total: '-12.500' },
      { total: '-3.250' },
    ] as never);

    await expect(loadLegacyRefundTotalsForShift(mockDb, 'shift-1', 3)).resolves.toEqual({
      count: 2,
      amount: '15.750',
    });
    expect(getRefundRecordsForShift).toHaveBeenCalledWith(mockDb, 'shift-1');
  });

  it('normalises the legacy sign convention with bcabs', async () => {
    // Legacy rows store a NEGATIVE total; a v4 row that ever arrived positive
    // must not add itself back into the magnitude either.
    vi.mocked(queryAll).mockResolvedValue([{ total: '-10.000' }, { total: '4.000' }]);

    await expect(
      loadLegacyRefundTotalsForPeriod(mockDb, 'term-1', '2026-08-21T06:00:00Z', 3),
    ).resolves.toEqual({ count: 2, amount: '14.000' });
  });

  it('combines the two disjoint refund sources additively', () => {
    expect(
      combineRefundTotals({ count: 1, amount: '12.500' }, { count: 2, amount: '15.750' }, 3),
    ).toEqual({ count: 3, amount: '28.250' });
  });
});

describe('netOfRefunds', () => {
  it('subtracts the refund magnitude from gross to give the O-28 headline', () => {
    expect(netOfRefunds('4820.500', '12.500', 3)).toBe('4808.000');
  });

  it('is a no-op when nothing was refunded', () => {
    expect(netOfRefunds('4820.500', '0.000', 3)).toBe('4820.500');
  });

  it('can go negative when a period holds only refunds', () => {
    expect(netOfRefunds('0.000', '12.500', 3)).toBe('-12.500');
  });
});

describe('filterTickets', () => {
  const tickets: SalesHistoryTicket[] = [
    { ...baseTicket(), id: 'a', receiptNumber: 'T-1042', operatorName: 'Ines', methodCodes: ['CASH'] },
    { ...baseTicket(), id: 'b', receiptNumber: 'T-1043', operatorName: 'Yasmine', methodCodes: ['CARD'] },
    {
      ...baseTicket(),
      id: 'c',
      receiptNumber: 'T-1044',
      operatorName: 'Ines',
      methodCodes: ['CARD', 'VOUCHER'],
    },
  ];

  it('returns everything for the "all" method filter and an empty search', () => {
    expect(filterTickets(tickets, 'all', '  ').map((t) => t.id)).toEqual(['a', 'b', 'c']);
  });

  it('matches a single-tender code without matching a split tender', () => {
    expect(filterTickets(tickets, 'CARD', '').map((t) => t.id)).toEqual(['b']);
  });

  it('matches split tenders under the MIXED pseudo-method', () => {
    expect(filterTickets(tickets, 'MIXED', '').map((t) => t.id)).toEqual(['c']);
  });

  it('searches receipt number and cashier name, case-insensitively', () => {
    expect(filterTickets(tickets, 'all', 'ines').map((t) => t.id)).toEqual(['a', 'c']);
    expect(filterTickets(tickets, 'all', 't-1043').map((t) => t.id)).toEqual(['b']);
  });
});

describe('paymentSharePercent', () => {
  it('returns an integer percentage string of the absolute total', () => {
    expect(paymentSharePercent('3100.000', '4820.500')).toBe('64');
    expect(paymentSharePercent('-12.500', '4820.500')).toBe('0');
  });

  it('returns 0 rather than dividing by zero', () => {
    expect(paymentSharePercent('10.000', '0')).toBe('0');
  });
});

function baseTicket(): SalesHistoryTicket {
  return {
    id: 'x',
    receiptNumber: 'T-0',
    createdAt: '2026-08-21 09:00:00',
    operatorName: 'Yasmine',
    itemCount: 1,
    methodCodes: ['CASH'],
    methodLabel: 'Espèces',
    isRefund: false,
    total: '0.000',
  };
}
