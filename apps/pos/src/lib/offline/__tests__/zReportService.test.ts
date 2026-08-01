/**
 * Tests for generateZReport — cash count persistence (Task 11).
 *
 * Uses the same vi.mock hoisting pattern as the other offline tests.
 * All DB calls and store reads are mocked; only the pure transformation
 * and orchestration logic is exercised.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setWriter, __resetWriteGateForTesting } from '@/lib/db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

// ─── Hoist mocks before any imports ──────────────────────────────────────────

const fiscalMocks = vi.hoisted(() => ({
  engine: { append: vi.fn() },
}));

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
}));

vi.mock('@/lib/currency', () => ({
  getCurrencyDecimals: vi.fn().mockReturnValue(2),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      companyId: 'co-1',
      companies: [{ id: 'co-1', currency: 'EUR' }],
    }),
  },
}));

vi.mock('@/lib/fiscal/zReportHashService', () => ({
  computeZReportHash: vi.fn().mockResolvedValue('mock-fiscal-hash'),
}));

vi.mock('@/lib/fiscal/instance', () => ({
  getFiscalEventEngine: vi.fn().mockResolvedValue(fiscalMocks.engine),
}));

vi.mock('@/lib/fiscal/zSessionAuthoring', () => ({
  appendZSessionCloseAndZReport: vi.fn().mockResolvedValue({
    sessionCloseEvent: { id: 'session-close-event-1' },
    sessionCloseUuid: 'session-close-uuid-1',
    zReportEvent: { id: 'z-report-event-1' },
  }),
}));

vi.mock('@/lib/db/repositories/zReportRepository', () => ({
  insertZReport: vi.fn().mockResolvedValue(undefined),
  getZReportByShift: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn().mockResolvedValue({
    terminal_id: 'term-1',
    terminal_code: 'T001',
    location_code: 'MAIN',
    genesis_seed: 'seed',
    last_hash: 'last-hash',
    hash_sequence: 5,
  }),
  getZChainState: vi.fn().mockResolvedValue({
    z_last_hash: 'prev-z-hash',
    z_hash_sequence: 2,
    z_number: 2,
    cumulative_sales: '500.00',
    cumulative_tax: '80.00',
    cumulative_refunds: '0.00',
    perpetual_grand_total: '500.00',
    receipt_count_lifetime: 10,
  }),
  advanceZChain: vi.fn().mockResolvedValue(undefined),
  updateGrandTotals: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/zReportCountRepository', () => ({
  insertZReportCounts: vi.fn().mockResolvedValue(undefined),
}));

// ─── Import the modules under test ───────────────────────────────────────────

import { generateZReport } from '../zReportService';
import { queryAll } from '@/lib/db';
import { insertZReport } from '@/lib/db/repositories/zReportRepository';
import { insertZReportCounts } from '@/lib/db/repositories/zReportCountRepository';
import { advanceZChain, updateGrandTotals } from '@/lib/db/repositories/terminalStateRepository';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { appendZSessionCloseAndZReport } from '@/lib/fiscal/zSessionAuthoring';

import type Database from '@tauri-apps/plugin-sql';

// ─── Test helpers ─────────────────────────────────────────────────────────────

function makeMockDb(): Database {
  return {
    execute: vi.fn().mockResolvedValue(undefined),
  } as unknown as Database;
}

/** One CASH receipt: total=50, subtotal=42, tax=8 */
function makeReceiptRows() {
  return [
    {
      id: 'r1',
      receipt_number: 'T001-0001',
      terminal_id: 'term-1',
      operator_id: 'op-1',
      operator_name: 'Alice',
      payment_method_id: 'pm-cash',
      total: '50.00',
      subtotal: '42.00',
      tax_amount: '8.00',
      discount_amount: '0.00',
      transaction_discount_amount: null,
      transaction_discount_reason: null,
      tendered_amount: '50.00',
      change_due: '0.00',
      lines: JSON.stringify([
        { name: 'Widget', quantity: 1, unit_price: '50.00', line_total: '42.00', tax_rate: '19', tax_amount: '8.00', discount_amount: null },
      ]),
      fiscal_hash: 'h1',
      previous_hash: 'genesis',
      hash_sequence: 1,
      currency: 'EUR',
      idempotency_key: 'idem-r1',
      status: 'pending',
      retry_count: 0,
      payments_json: null as string | null,
      consumption_mode: null,
      table_id: null,
      server_receipt_id: null,
      payment_repository_id: 'repo-1',
      terminal_code: 'T001',
      created_at: '2026-04-23T10:00:00+00:00',
      synced_at: null,
      sync_error: null,
    },
  ];
}

/**
 * v3-refund-chain-integration spec §7.1/§7.3 (Revision 4) — a v4 refund's
 * `offline_receipts` row (`receipt_kind: 'refund'`), replacing the former
 * `local_refund_records` mirror entirely. Two CASH-destination refunds
 * (10.00 + 5.50) — the OLD mechanism's third fixture row (a
 * `store_voucher`-destination refund with zero drawer impact) has no v4
 * equivalent: launch's v4 refund contract is CASH-ONLY (§3.4/§3.7, single
 * `'cash'` literal); a non-cash refund destination is out of this
 * feature's scope (§16 roadmap), so every v4 refund now genuinely does
 * move drawer cash equal to its own magnitude — there is no longer a
 * "refund that doesn't affect expected_cash" case to fixture.
 */
function makeRefundOfflineReceiptRow(id: string, total: string, hashSequence: number, createdAt: string) {
  const positiveAmount = total.startsWith('-') ? total.slice(1) : total;
  return {
    id,
    receipt_number: `T001-01${id.slice(-2)}`,
    terminal_id: 'term-1',
    operator_id: 'op-1',
    operator_name: 'Alice',
    payment_method_id: 'pm-cash',
    total,
    subtotal: total,
    tax_amount: '0.00',
    discount_amount: '0.00',
    transaction_discount_amount: null as string | null,
    transaction_discount_reason: null as string | null,
    tendered_amount: null as string | null,
    change_due: null as string | null,
    lines: JSON.stringify([
      { name: 'Refunded Widget', quantity: -1, unit_price: positiveAmount, line_total: total, tax_rate: '0', tax_amount: '0.00', discount_amount: null },
    ]),
    fiscal_hash: `h-${id}`,
    previous_hash: 'genesis',
    hash_sequence: hashSequence,
    currency: 'EUR',
    idempotency_key: `idem-${id}`,
    status: 'pending',
    retry_count: 0,
    payments_json: JSON.stringify([
      {
        method_code: 'CASH',
        amount: positiveAmount,
        payment_method_id: 'pm-cash',
        repository_id: 'repo-1',
        card_last_four: null,
        transaction_reference: null,
        instrument_type: null,
        instrument_serial: null,
      },
    ]),
    consumption_mode: null,
    table_id: null,
    server_receipt_id: null,
    payment_repository_id: 'repo-1',
    terminal_code: 'T001',
    created_at: createdAt,
    synced_at: null,
    sync_error: null,
    receipt_kind: 'refund' as const,
  };
}

function makeRefundOfflineReceiptRows() {
  return [
    makeRefundOfflineReceiptRow('rr-1', '-10.00', 2, '2026-04-23T11:00:00+00:00'),
    makeRefundOfflineReceiptRow('rr-2', '-5.50', 3, '2026-04-23T11:30:00+00:00'),
  ];
}

/** Cash drawer ops mirrored locally (offline_cash_drawer_ops): deposit=+drawer, payout=−drawer. */
type DrawerOpRow = { id: string; type: 'deposit' | 'payout'; amount: string; shift_id: string };

/** Account-payment records mirrored locally (cash account collections raise the drawer). */
type AccountPaymentRow = { id: string; shift_id: string; method_code: string; cash_impact: string };

/** Wave-2 review fix — the LEGACY refund path's own record-at-settle
 *  mirror (local_refund_records), restored alongside the v4
 *  offline_receipts mechanism above (disjoint sources, see
 *  zReportService.ts's own comment at the fold site). */
type LegacyRefundRecordRow = {
  id: string;
  receipt_number: string;
  original_receipt_number: string;
  shift_id: string;
  terminal_id: string;
  destination: string;
  total: string;
  cash_impact: string;
  currency: string;
  settled_at: string;
};

function makeLegacyRefundRecord(
  id: string,
  total: string,
  shiftId: string,
  overrides: Partial<LegacyRefundRecordRow> = {},
): LegacyRefundRecordRow {
  const positiveAmount = total.startsWith('-') ? total.slice(1) : total;
  return {
    id,
    receipt_number: `T001-99${id.slice(-2)}`,
    original_receipt_number: 'T001-0001',
    shift_id: shiftId,
    terminal_id: 'term-1',
    destination: 'cash',
    total,
    cash_impact: positiveAmount,
    currency: 'EUR',
    settled_at: '2026-04-23T11:15:00+00:00',
    ...overrides,
  };
}

/** Setup queryAll to return receipts / payment_methods / refund records / drawer ops / account payments by SQL shape */
function mockQueryAll(
  db: Database,
  // v3-refund-chain-integration spec §7.1/§7.3 — v4 refund rows come
  // from `offline_receipts` (merge `makeRefundOfflineReceiptRows()` into
  // this SAME array); LEGACY refund records are a separate, disjoint
  // source (`legacyRefundRecords` below).
  receipts: Array<ReturnType<typeof makeReceiptRows>[number] | ReturnType<typeof makeRefundOfflineReceiptRow>>,
  drawerOps: DrawerOpRow[] = [],
  accountPayments: AccountPaymentRow[] = [],
  anchor: { shift_id: string; opening_hash_sequence: number } | null = null,
  legacyRefundRecords: LegacyRefundRecordRow[] = [],
) {
  vi.mocked(queryAll).mockImplementation(async (_db, sql, params) => {
    const s = sql as string;
    if (s.includes('shift_receipt_anchors')) {
      const shiftId = (params as unknown[] | undefined)?.[0];
      return (anchor && anchor.shift_id === shiftId ? [anchor] : []) as unknown as never[];
    }
    if (s.includes('local_refund_records')) {
      const shiftId = (params as unknown[] | undefined)?.[0];
      return legacyRefundRecords.filter((r) => r.shift_id === shiftId) as unknown as never[];
    }
    if (s.includes('local_account_payment_records')) {
      const shiftId = (params as unknown[] | undefined)?.[0];
      return accountPayments.filter((a) => a.shift_id === shiftId) as unknown as never[];
    }
    if (s.includes('offline_cash_drawer_ops')) {
      const shiftId = (params as unknown[] | undefined)?.[0];
      return drawerOps.filter((o) => o.shift_id === shiftId) as unknown as never[];
    }
    if (s.includes('FROM offline_receipts') || s.includes('offline_receipts')) {
      return receipts;
    }
    if (s.includes('FROM payment_methods') || s.includes('payment_methods')) {
      return [
        { id: 'pm-cash', code: 'CASH' },
        { id: 'pm-card', code: 'CARD' },
      ];
    }
    return [];
  });
  return db;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

describe('generateZReport', () => {
  let db: Database;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();
    __resetWriteGateForTesting();
    setWriter(db as unknown as SqlSurface);
  });

  describe('without cash counts (backwards-compatible)', () => {
    it('normalizes an ISO shiftOpenedAt to SQLite UTC format in the created_at fallback query', async () => {
      // No anchor row → generateZReport falls back to the created_at window.
      // offline_receipts.created_at is `datetime('now')` format (space
      // separator); binding the raw ISO string (T separator) would
      // lexicographically exclude every same-day receipt.
      mockQueryAll(db, makeReceiptRows());

      await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      const receiptsCall = vi.mocked(queryAll).mock.calls.find(
        ([, sql]) => (sql as string).includes('FROM offline_receipts'),
      );
      expect(receiptsCall).toBeDefined();
      expect((receiptsCall![2] as unknown[])[1]).toBe('2026-04-23 08:00:00');
    });

    it('generates a Z-report with schema_version absent (or 1) when no cashCounts provided', async () => {
      mockQueryAll(db, makeReceiptRows());

      const report = await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      // No cashCounts opted in — report_data should not have schema_version 2
      expect(report.report_data.schema_version).not.toBe(2);
      // cash_counts should be absent on the LocalZReport
      expect(report.cash_counts).toBeUndefined();
    });

    it('does NOT call insertZReportCounts when no cashCounts are provided', async () => {
      mockQueryAll(db, makeReceiptRows());

      await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      expect(insertZReportCounts).not.toHaveBeenCalled();
    });

    it('does NOT author fiscal close events until fiscal session context is supplied', async () => {
      mockQueryAll(db, makeReceiptRows());

      await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      expect(getFiscalEventEngine).not.toHaveBeenCalled();
      expect(appendZSessionCloseAndZReport).not.toHaveBeenCalled();
    });

    it('rejects cutover Z-report generation before legacy rows when fiscal close context is missing', async () => {
      mockQueryAll(db, makeReceiptRows());

      await expect(
        generateZReport(
          db,
          'term-1',
          'shift-1',
          '2026-04-23T08:00:00+00:00',
          '100.00',
          { requireFiscalEvents: true },
        ),
      ).rejects.toThrow(/fiscal event close context/);

      expect(insertZReport).not.toHaveBeenCalled();
      expect(advanceZChain).not.toHaveBeenCalled();
      expect(getFiscalEventEngine).not.toHaveBeenCalled();
      expect(appendZSessionCloseAndZReport).not.toHaveBeenCalled();
    });

    it('M3: bounds the receipt window by hash_sequence (rollback-safe) when a shift anchor exists', async () => {
      mockQueryAll(db, makeReceiptRows(), [], [], {
        shift_id: 'shift-1',
        opening_hash_sequence: 7,
      });

      await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      const receiptsCall = vi
        .mocked(queryAll)
        .mock.calls.find((c) => String(c[1]).includes('FROM offline_receipts'));
      expect(receiptsCall).toBeDefined();
      // Uses the monotonic sequence bound, not the wall-clock created_at bound.
      expect(String(receiptsCall![1])).toMatch(/hash_sequence\s*>\s*\$2/);
      expect(String(receiptsCall![1])).not.toMatch(/created_at\s*>=/);
      expect((receiptsCall![2] as unknown[])[1]).toBe(7);
    });

    it('M3: falls back to the wall-clock window when no shift anchor exists (legacy shift)', async () => {
      mockQueryAll(db, makeReceiptRows()); // no anchor

      await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      const receiptsCall = vi
        .mocked(queryAll)
        .mock.calls.find((c) => String(c[1]).includes('FROM offline_receipts'));
      expect(String(receiptsCall![1])).toMatch(/created_at\s*>=\s*\$2/);
    });

    it('T2.7: filters offline_receipts WHERE is_training = 0 (training rows excluded from Z totals)', async () => {
      // Mirrors the server-side `Terminal::scopeProduction()` exclusion that
      // NF525 / ReportGenerationService apply on the canonical reporting path.
      // Without the filter, training receipts created during a shift would
      // inflate local Z totals + corrupt the local Z-chain.
      mockQueryAll(db, makeReceiptRows());

      await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      const calls = vi.mocked(queryAll).mock.calls;
      const receiptsCall = calls.find((call) => {
        const sql = String(call[1]);
        return sql.includes('FROM offline_receipts');
      });
      expect(receiptsCall).toBeDefined();
      expect(String(receiptsCall![1])).toMatch(/is_training\s*=\s*0/);
    });
  });

  describe('split tenders + net cash (H1)', () => {
    // NF525/DSFinV-K: the per-method Z total is the NET amount allocated to each
    // method (change netted into the cash figure), and split tenders attribute
    // each payment to its own method — NOT the whole receipt total to the
    // primary payment_method_id. See the 2026-06-11 cash-reconciliation research.
    function makeSplitTenderReceipt() {
      const base = makeReceiptRows()[0]!;
      return [
        {
          ...base,
          total: '100.00',
          subtotal: '84.03',
          tax_amount: '15.97',
          // Cash 65 tendered + card 40 = 105 tendered on a 100 sale → 5 change
          // on the cash portion. Net cash in drawer = 65 − 5 = 60; card = 40.
          change_due: '5.00',
          payment_method_id: 'pm-cash',
          payments_json: JSON.stringify([
            { payment_method_id: 'pm-cash', amount: '65.00', method_code: 'CASH' },
            { payment_method_id: 'pm-card', amount: '40.00', method_code: 'CARD' },
          ]),
          lines: JSON.stringify([
            { name: 'Widget', quantity: 1, unit_price: '100.00', line_total: '84.03', tax_rate: '19', tax_amount: '15.97', discount_amount: null },
          ]),
        },
      ];
    }

    it('attributes each tender to its own method and nets change into the cash figure', async () => {
      mockQueryAll(db, makeSplitTenderReceipt());

      const report = await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      const methods = report.report_data.payment_methods;
      const cash = methods.find((m) => m.payment_type === 'CASH')!;
      const card = methods.find((m) => m.payment_type === 'CARD')!;
      expect(cash).toBeDefined();
      expect(card).toBeDefined();
      // Net cash retained (tendered 65 − change 5), NOT the whole 100 receipt total.
      expect(cash.total_amount).toBe('60.00');
      expect(card.total_amount).toBe('40.00');
      // expected_cash = opening 100 + net cash 60 = 160 (no refunds).
      expect(report.report_data.expected_cash).toBe('160.00');
    });

    it('legacy fallback: receipt.total is already net cash — does NOT subtract change again', async () => {
      // A legacy receipt without payments_json. receipt.total is the sale value;
      // for a fully-cash receipt the drawer gains tendered − change = total, so
      // the cash figure IS receipt.total — subtracting change_due would double-net.
      const legacy = [
        {
          ...makeReceiptRows()[0]!,
          total: '50.00',
          change_due: '5.00',
          payment_method_id: 'pm-cash',
          payments_json: null,
        },
      ];
      mockQueryAll(db, legacy);

      const report = await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      const cash = report.report_data.payment_methods.find((m) => m.payment_type === 'CASH')!;
      expect(cash.total_amount).toBe('50.00'); // NOT 45.00
      expect(report.report_data.expected_cash).toBe('150.00'); // 100 + 50
    });
  });

  describe('cash drawer movements in expected_cash (H2)', () => {
    // NF525/DSFinV-K: paid-ins (deposits) and payouts are cash-balance events
    // folded into theoretical/expected cash — deposit raises it, payout lowers
    // it. The device is source of truth, so expected_cash must mirror the drawer.
    it('adds drawer deposits and subtracts payouts from expected_cash', async () => {
      const drawerOps = [
        { id: 'd1', type: 'deposit' as const, amount: '20.00', shift_id: 'shift-1' },
        { id: 'd2', type: 'payout' as const, amount: '5.00', shift_id: 'shift-1' },
      ];
      mockQueryAll(db, makeReceiptRows(), drawerOps);

      const report = await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      // opening 100 + cash sales 50 + deposit 20 − payout 5 = 165.00
      expect(report.report_data.expected_cash).toBe('165.00');
    });

    it('ignores drawer ops from a different shift', async () => {
      const drawerOps = [
        { id: 'd1', type: 'deposit' as const, amount: '99.00', shift_id: 'OTHER-shift' },
      ];
      mockQueryAll(db, makeReceiptRows(), drawerOps);

      const report = await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      // No ops for shift-1 → opening 100 + cash sales 50 = 150.00
      expect(report.report_data.expected_cash).toBe('150.00');
    });
  });

  describe('cash account payments in expected_cash (H2)', () => {
    // NF525/DSFinV-K: cash received against a customer credit account is drawer
    // cash (a cash-balance event), folded into expected_cash — NOT a sales
    // payment-method total. Non-cash account payments move no till cash.
    it('adds CASH account-payment collections to expected_cash', async () => {
      const accountPayments = [
        { id: 'ap1', shift_id: 'shift-1', method_code: 'CASH', cash_impact: '30.00' },
      ];
      mockQueryAll(db, makeReceiptRows(), [], accountPayments);

      const report = await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      // opening 100 + cash sales 50 + cash account-payment 30 = 180.00
      expect(report.report_data.expected_cash).toBe('180.00');
    });

    it('does NOT add non-cash account payments (cash_impact 0) to expected_cash', async () => {
      const accountPayments = [
        { id: 'ap1', shift_id: 'shift-1', method_code: 'CARD', cash_impact: '0' },
      ];
      mockQueryAll(db, makeReceiptRows(), [], accountPayments);

      const report = await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      // opening 100 + cash sales 50 + 0 = 150.00
      expect(report.report_data.expected_cash).toBe('150.00');
    });

    it('closes a shift whose only activity is a cash account payment (no receipts/refunds)', async () => {
      const accountPayments = [
        { id: 'ap1', shift_id: 'shift-1', method_code: 'CASH', cash_impact: '30.00' },
      ];
      mockQueryAll(db, [], [], accountPayments);

      const report = await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      // opening 100 + cash account-payment 30 = 130.00 (no sales/refunds)
      expect(report.report_data.expected_cash).toBe('130.00');
    });
  });

  describe('with cash counts', () => {
    const cashCounts = [
      { payment_method_id: 'pm-cash', currency_code: 'EUR', actual_amount: '148.00' },
    ];

    it('stamps report_data.schema_version = 2 when cashCounts are provided', async () => {
      mockQueryAll(db, makeReceiptRows());

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts },
      );

      expect(report.report_data.schema_version).toBe(2);
    });

    it('stamps report_data.cash_counts with computed variances', async () => {
      mockQueryAll(db, makeReceiptRows());

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts },
      );

      expect(Array.isArray(report.report_data.cash_counts)).toBe(true);
      const counts = report.report_data.cash_counts ?? [];
      expect(counts).toHaveLength(1);
      const row = counts[0]!;
      expect(row.payment_method_id).toBe('pm-cash');
      // expected_cash for CASH = opening (100) + cash_sales (50) = 150.00
      expect(row.expected_amount).toBe('150.00');
      expect(row.actual_amount).toBe('148.00');
      // variance = actual - expected = -2.00
      expect(row.variance_amount).toBe('-2.00');
    });

    it('stamps report_data.tolerance_summary with zero-shape (3dp, integer count)', async () => {
      mockQueryAll(db, makeReceiptRows());

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts },
      );

      expect(report.report_data.tolerance_summary).toEqual({
        totalAmount: '0.000',
        currencyCode: 'EUR',
        writeoffCount: 0,
      });
    });

    it('exposes cash_counts on the returned LocalZReport object', async () => {
      mockQueryAll(db, makeReceiptRows());

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts },
      );

      expect(Array.isArray(report.cash_counts)).toBe(true);
      expect(report.cash_counts).toHaveLength(1);
    });

    it('calls insertZReportCounts with one row per cash-count input', async () => {
      mockQueryAll(db, makeReceiptRows());

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts },
      );

      expect(insertZReportCounts).toHaveBeenCalledOnce();
      const [calledDb, rows] = vi.mocked(insertZReportCounts).mock.calls[0]!;
      expect(calledDb).toBe(db);
      expect(rows).toHaveLength(1);
      const row = rows[0]!;
      expect(row.z_report_id).toBe(report.id);
      expect(row.payment_method_id).toBe('pm-cash');
      expect(row.currency_code).toBe('EUR');
      expect(row.expected_amount).toBe('150.00');
      expect(row.actual_amount).toBe('148.00');
      expect(row.variance_amount).toBe('-2.00');
      expect(row.variance_direction).toBe('under');
      expect(typeof row.transaction_count).toBe('number');
    });

    it('stores shift_fields with variance_severity null when no fraudSettings provided', async () => {
      mockQueryAll(db, makeReceiptRows());

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        {
          cashCounts,
          blindCountUsed: true,
          varianceReason: 'Operator error',
          managerUserId: 'mgr-uuid-1',
        },
      );

      expect(report.shift_fields).toEqual({
        blind_count_used: true,
        // No fraudSettings provided — severity cannot be computed; stays null.
        variance_severity: null,
        variance_reason: 'Operator error',
        manager_override_by: 'mgr-uuid-1',
      });
      expect(report.manager_user_id).toBe('mgr-uuid-1');
    });

    it('computes variance_severity when fraudSettings thresholds are provided', async () => {
      mockQueryAll(db, makeReceiptRows());

      // actual_amount = 148.00, expected_cash = 150.00 → variance = -2.00 (under)
      // soft = 1.00, hard = 20.00 → |variance| 2.00 > soft 1.00 and ≤ hard 20.00 → 'warning'
      const fraudSettings = {
        cash_variance_over_soft: '1.00',
        cash_variance_over_hard: '20.00',
        cash_variance_under_soft: '1.00',
        cash_variance_under_hard: '20.00',
      };

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts, fraudSettings },
      );

      expect(report.shift_fields?.variance_severity).toBe('warning');
    });

    it('computes variance_severity as balanced when variance is zero', async () => {
      mockQueryAll(db, makeReceiptRows());

      // expected_cash = 150.00, actual_amount = 150.00 → variance = 0.00 → 'balanced'
      const balancedCounts = [
        { payment_method_id: 'pm-cash', currency_code: 'EUR', actual_amount: '150.00' },
      ];
      const fraudSettings = {
        cash_variance_over_soft: '1.00',
        cash_variance_over_hard: '20.00',
        cash_variance_under_soft: '1.00',
        cash_variance_under_hard: '20.00',
      };

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts: balancedCounts, fraudSettings },
      );

      expect(report.shift_fields?.variance_severity).toBe('balanced');
    });

    it('computes variance_severity as critical when |variance| exceeds hard threshold', async () => {
      mockQueryAll(db, makeReceiptRows());

      // expected_cash = 150.00, actual_amount = 120.00 → variance = -30.00 (under)
      // hard = 20.00 → |variance| 30.00 > hard 20.00 → 'critical'
      const largeMissCounts = [
        { payment_method_id: 'pm-cash', currency_code: 'EUR', actual_amount: '120.00' },
      ];
      const fraudSettings = {
        cash_variance_over_soft: '1.00',
        cash_variance_over_hard: '20.00',
        cash_variance_under_soft: '1.00',
        cash_variance_under_hard: '20.00',
      };

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts: largeMissCounts, fraudSettings },
      );

      expect(report.shift_fields?.variance_severity).toBe('critical');
    });

    it('sets variance_direction to "over" when actual > expected', async () => {
      mockQueryAll(db, makeReceiptRows());

      const overCounts = [
        { payment_method_id: 'pm-cash', currency_code: 'EUR', actual_amount: '155.00' },
      ];

      await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts: overCounts },
      );

      const [, rows] = vi.mocked(insertZReportCounts).mock.calls[0]!;
      expect(rows[0]!.variance_direction).toBe('over');
    });

    it('sets variance_direction to "balanced" when actual equals expected', async () => {
      mockQueryAll(db, makeReceiptRows());

      // expected_cash for CASH = opening (100) + cash_sales (50) = 150.00
      const balancedCounts = [
        { payment_method_id: 'pm-cash', currency_code: 'EUR', actual_amount: '150.00' },
      ];

      await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts: balancedCounts },
      );

      const [, rows] = vi.mocked(insertZReportCounts).mock.calls[0]!;
      expect(rows[0]!.variance_direction).toBe('balanced');
      expect(rows[0]!.variance_amount).toBe('0.00');
    });

    it('persists in transaction: calls BEGIN + insertZReport + insertZReportCounts + advanceZChain + COMMIT', async () => {
      mockQueryAll(db, makeReceiptRows());

      await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts },
      );

      const executeCalls = vi.mocked(db.execute).mock.calls.map((c) => c[0]);
      // Single-writer gate opens IMMEDIATE (takes the write lock up front).
      expect(executeCalls).toContain('BEGIN IMMEDIATE TRANSACTION');
      expect(executeCalls).toContain('COMMIT');
      expect(insertZReport).toHaveBeenCalledOnce();
      expect(insertZReportCounts).toHaveBeenCalledOnce();
      expect(advanceZChain).toHaveBeenCalledOnce();
      expect(updateGrandTotals).toHaveBeenCalledOnce();
    });

    it('spec §7.3 — folds refund offline_receipts rows into refunds totals and expected_cash', async () => {
      mockQueryAll(db, [...makeReceiptRows(), ...makeRefundOfflineReceiptRows()]);

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts: [{ payment_method_id: 'pm-cash', currency_code: 'EUR', actual_amount: '134.50' }] },
      );

      // 2 cash refunds (10.00 + 5.50) -- every v4 refund is cash-only at
      // launch (§3.4/§3.7), so every refund row now genuinely has a cash
      // impact equal to its own magnitude.
      expect(report.report_data.refunds_count).toBe(2);
      // refunds_amount is a POSITIVE magnitude (server semantics — see
      // ZReportV3AggregationTest / GrandtotalService netDelta formula).
      expect(report.report_data.refunds_amount).toBe('15.50');
      // expected_cash = opening 100 + cash sales 50 − CASH refunds 15.50,
      // now computed via aggregateReportData()'s own receipt_kind-branched
      // paymentByType subtraction (§7.3's simplification -- no separate
      // cashRefundImpact term any more).
      expect(report.report_data.expected_cash).toBe('134.50');
      expect(report.expected_cash).toBe('134.50');
      // The CASH cash-count row compares against the refund-adjusted expected.
      const cashRow = (report.report_data.cash_counts ?? [])[0]!;
      expect(cashRow.expected_amount).toBe('134.50');
      expect(cashRow.variance_amount).toBe('0.00');

      // Grand totals flow through the EXISTING formulas with real values:
      // cumulative_refunds 0 + 15.50; perpetual 500 + (50 − 15.50).
      expect(report.grand_totals.cumulative_refunds).toBe('15.500');
      expect(report.grand_totals.perpetual_grand_total).toBe('534.500');
      expect(updateGrandTotals).toHaveBeenCalledWith(db, 'term-1', '50.00', '8.00', '15.50', 1);
    });

    // Wave-2 review fix (TREASURY CRITICAL) — a MIXED shift with one
    // LEGACY refund (local_refund_records, the default/only mechanism for
    // every terminal that has not yet completed its v4 capability
    // rollout) and one v4 refund (offline_receipts receipt_kind='refund')
    // must reduce expected_cash by BOTH exactly once each — disjoint
    // sources, plain sum, never a double-count.
    it('wave-2: a MIXED shift (one legacy refund + one v4 refund) reduces expected_cash by both exactly once each', async () => {
      mockQueryAll(
        db,
        [...makeReceiptRows(), makeRefundOfflineReceiptRow('rr-v4', '-10.00', 2, '2026-04-23T11:00:00+00:00')],
        [],
        [],
        null,
        [makeLegacyRefundRecord('legacy-1', '-7.25', 'shift-1')],
      );

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
      );

      // One from each source.
      expect(report.report_data.refunds_count).toBe(2);
      // 7.25 (legacy) + 10.00 (v4) — a plain, non-overlapping sum.
      expect(report.report_data.refunds_amount).toBe('17.25');
      // opening 100 + cash sales 50 − v4 CASH refund 10.00 (netted inside
      // aggregateReportData's paymentByType) − legacy cash_impact 7.25
      // (the standalone cashRefundImpact term, restored) = 132.75.
      expect(report.report_data.expected_cash).toBe('132.75');
      expect(report.expected_cash).toBe('132.75');
    });

    it('spec §7.3 — a shift with no refund rows keeps the zero totals (unchanged behavior)', async () => {
      mockQueryAll(db, makeReceiptRows());

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
      );

      expect(report.report_data.refunds_count).toBe(0);
      expect(report.report_data.refunds_amount).toBe('0.00');
      expect(report.report_data.expected_cash).toBe('150.00');
      expect(report.grand_totals.cumulative_refunds).toBe('0.000');
      expect(report.grand_totals.perpetual_grand_total).toBe('550.000');
    });

    it('spec §7.3 — a refund row outside the current shift window (M3 hash_sequence bound) is not counted', async () => {
      // Superseding the OLD "refunds recorded under a different shift_id"
      // test: refund rows are no longer selected by their own shift_id-
      // filtered query (local_refund_records is gone) -- they are just
      // regular offline_receipts rows now, subject to the SAME
      // hash_sequence/created_at window every other receipt uses (M3).
      // A refund from a PRIOR shift is excluded by that window, exactly
      // like a stale sale row would be.
      const outOfWindowRefund = makeRefundOfflineReceiptRow('rr-old', '-9.99', 1, '2026-04-22T09:00:00+00:00');
      mockQueryAll(db, [...makeReceiptRows(), outOfWindowRefund], [], [], {
        shift_id: 'shift-1',
        opening_hash_sequence: 1, // window excludes hash_sequence <= 1
      });

      await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
      );

      const receiptsCall = vi.mocked(queryAll).mock.calls.find(
        ([, sql]) => (sql as string).includes('FROM offline_receipts'),
      );
      expect(receiptsCall).toBeDefined();
      // The real query filters in SQL (hash_sequence > anchor); this mock
      // returns the full unfiltered fixture array regardless, so assert
      // the SAME window predicate the M3 sale-side tests already pin,
      // proving no separate refund-only filter exists any more.
      expect((receiptsCall![2] as unknown[])[1]).toBe(1);
    });

    it('spec §7.3 — the signed close input receives the aggregated refund totals', async () => {
      mockQueryAll(db, [...makeReceiptRows(), ...makeRefundOfflineReceiptRows()]);

      await generateZReport(
        db,
        'term-1',
        'shift-1',
        '2026-04-23T08:00:00+00:00',
        '100.00',
        {
          cashCounts: [{ payment_method_id: 'pm-cash', currency_code: 'EUR', actual_amount: '134.50' }],
          tenantId: 'tenant-1',
          fiscalShiftId: '33333333-3333-4333-8333-333333333333',
          fiscalSessionId: '44444444-4444-4444-8444-444444444444',
          terminalLabel: 'T001',
          operatorId: '22222222-2222-4222-8222-222222222222',
          operatorName: 'Alice',
          isTraining: false,
        },
      );

      expect(appendZSessionCloseAndZReport).toHaveBeenCalledOnce();
      const [, , closeInput] = vi.mocked(appendZSessionCloseAndZReport).mock.calls[0]!;
      expect(closeInput).toMatchObject({
        expectedCash: '134.50',
        reportTotals: {
          sales_count: 1,
          gross_sales: '50.00',
          net_sales: '42.00',
          tax_amount: '8.00',
          refunds_count: 2,
          refunds_amount: '15.50',
          voided_count: 0,
        },
        grandTotalsAfter: {
          cumulative_refunds: '15.500',
          perpetual_grand_total: '534.500',
        },
      });
    });

    it('authors SESSION_CLOSE and Z_REPORT fiscal events when fiscal session context is supplied', async () => {
      mockQueryAll(db, makeReceiptRows());

      const report = await generateZReport(
        db,
        'term-1',
        'shift-1',
        '2026-04-23T08:00:00+00:00',
        '100.00',
        {
          cashCounts,
          tenantId: 'tenant-1',
          fiscalShiftId: '33333333-3333-4333-8333-333333333333',
          fiscalSessionId: '44444444-4444-4444-8444-444444444444',
          terminalLabel: 'T001',
          operatorId: '22222222-2222-4222-8222-222222222222',
          operatorName: 'Alice',
          isTraining: false,
        },
      );

      expect(getFiscalEventEngine).toHaveBeenCalledWith('co-1', db);
      expect(appendZSessionCloseAndZReport).toHaveBeenCalledOnce();
      const [calledDb, calledEngine, closeInput] = vi.mocked(appendZSessionCloseAndZReport).mock.calls[0]!;
      expect(calledDb).toBe(db);
      expect(calledEngine).toBe(fiscalMocks.engine);
      expect(closeInput).toMatchObject({
        tenantId: 'tenant-1',
        companyId: 'co-1',
        terminalId: 'term-1',
        terminalLabel: 'T001',
        shiftId: '33333333-3333-4333-8333-333333333333',
        sessionId: '44444444-4444-4444-8444-444444444444',
        businessDate: '2026-04-23',
        operatorId: '22222222-2222-4222-8222-222222222222',
        operatorName: 'Alice',
        currencyCode: 'EUR',
        currencyScale: 2,
        zReportUuid: report.id,
        zNumber: 3,
        formattedZNumber: 'Z0003',
        expectedCash: '150.00',
        countedCash: '148.00',
        varianceAmount: '-2.00',
        varianceDirection: 'under',
        reportTotals: {
          sales_count: 1,
          gross_sales: '50.00',
          net_sales: '42.00',
          tax_amount: '8.00',
        },
        operationalEventRange: {
          first_receipt_hash: 'h1',
          first_receipt_sequence: 1,
          last_receipt_hash: 'h1',
          last_receipt_sequence: 1,
          receipt_count: 1,
        },
      });
      expect(closeInput.cashCountLines).toHaveLength(1);
      expect(closeInput.grandTotalsBefore).toMatchObject({
        cumulative_sales: '500.00',
        receipt_count_lifetime: 10,
      });
      expect(closeInput.grandTotalsAfter).toMatchObject({
        cumulative_sales: '550.000',
        receipt_count_lifetime: 11,
      });
      expect(closeInput.legacyReportReference).toMatchObject({
        fiscal_hash: 'mock-fiscal-hash',
        hash_sequence: 3,
        local_z_report_id: report.id,
        shift_id: 'shift-1',
      });
    });
  });

  describe('refund-only shift (Codex r1 M1)', () => {
    it('spec §7.1/§7.3 — generates a Z-report when the shift has ONLY refund offline_receipts rows (no sale rows)', async () => {
      // Real flow: open shift → process only a v4 refund (its OWN
      // offline_receipts row, receipt_kind='refund') → close shift. The
      // empty-receipts guard must not fire before refund rows are
      // considered, otherwise a compliant refund transaction has no local
      // signed Z closure path. Refund rows are ALREADY part of the same
      // `receipts` array queried above (spec §7.1's ruling), so no
      // separate guard is needed any more than for a sale-only shift.
      mockQueryAll(db, makeRefundOfflineReceiptRows());

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
      );

      // Sales side is all-zero…
      expect(report.report_data.sales_count).toBe(0);
      expect(report.report_data.gross_sales).toBe('0.00');
      expect(report.report_data.net_sales).toBe('0.00');
      expect(report.report_data.tax_amount).toBe('0.00');
      // VAT breakdown IS populated -- §7.3's refund branch SUBTRACTS each
      // refund line's own (0%-rate, in this fixture) VAT contribution
      // from the running per-rate totals, unlike the OLD
      // local_refund_records mechanism which had no lines data at all
      // and therefore never touched vat_breakdown for a refund.
      expect(report.report_data.vat_breakdown).toEqual([
        { tax_rate: 0, net_amount: '-15.50', vat_amount: '0.00', gross_amount: '-15.50' },
      ]);
      // Payment-method breakdown is populated too -- SUBTRACTED (a
      // negative CASH line), not simply absent as under the old mechanism.
      expect(report.report_data.payment_methods).toEqual([
        { payment_type: 'CASH', total_amount: '-15.50', transaction_count: 2 },
      ]);

      // …while the refunds fold in: 2 cash refunds (10.00 + 5.50).
      expect(report.report_data.refunds_count).toBe(2);
      expect(report.report_data.refunds_amount).toBe('15.50');

      // expected_cash = opening 100 − CASH refund impact 15.50 (no cash
      // sales; every v4 refund is cash-only at launch).
      expect(report.report_data.expected_cash).toBe('84.50');
      expect(report.expected_cash).toBe('84.50');

      // Cumulative/perpetual formulas stay coherent with zero sales:
      // cumulative_sales unchanged (+0); cumulative_refunds 0 + 15.50;
      // perpetual 500 + (0 − 15.50) = 484.500.
      expect(report.grand_totals.cumulative_sales).toBe('500.000');
      expect(report.grand_totals.cumulative_refunds).toBe('15.500');
      expect(report.grand_totals.perpetual_grand_total).toBe('484.500');
      expect(report.grand_totals.receipt_count_lifetime).toBe(10);
      expect(updateGrandTotals).toHaveBeenCalledWith(db, 'term-1', '0.00', '0.00', '15.50', 0);

      // insertZReport still runs -- the Z persists + chains even though
      // no SALE snapshot exists (receipt_snapshots is built from sale
      // rows only, a separate, unaffected concern from this section's
      // fix).
      expect(insertZReport).toHaveBeenCalledOnce();
      expect(advanceZChain).toHaveBeenCalledOnce();
    });

    it('refund-only shift supports cash counts against the refund-adjusted expected cash', async () => {
      mockQueryAll(db, makeRefundOfflineReceiptRows());

      const report = await generateZReport(
        db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00',
        { cashCounts: [{ payment_method_id: 'pm-cash', currency_code: 'EUR', actual_amount: '84.50' }] },
      );

      const cashRow = (report.report_data.cash_counts ?? [])[0]!;
      expect(cashRow.expected_amount).toBe('84.50');
      expect(cashRow.variance_amount).toBe('0.00');
      expect(cashRow.transaction_count).toBe(2);
    });

    it('generates a nil Z for a truly-empty shift (allow empty Z, H3 — server parity)', async () => {
      // A cashier can always close the register; an empty shift produces a nil
      // Z (Z néant) with all-zero totals and expected_cash = opening float. The
      // server ReportGenerationService allows this; the device must match.
      mockQueryAll(db, []);

      const report = await generateZReport(db, 'term-1', 'shift-1', '2026-04-23T08:00:00+00:00', '100.00');

      expect(report.report_data.sales_count).toBe(0);
      expect(report.report_data.gross_sales).toBe('0.00');
      expect(report.report_data.expected_cash).toBe('100.00'); // opening only
      expect(insertZReport).toHaveBeenCalled();
      expect(advanceZChain).toHaveBeenCalled();
    });
  });
});
