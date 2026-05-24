/**
 * Tests for generateZReport — cash count persistence (Task 11).
 *
 * Uses the same vi.mock hoisting pattern as the other offline tests.
 * All DB calls and store reads are mocked; only the pure transformation
 * and orchestration logic is exercised.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

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
      payments_json: null,
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

/** Setup queryAll to return receipts on first call and payment_methods on second */
function mockQueryAll(db: Database, receipts: ReturnType<typeof makeReceiptRows>) {
  vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
    const s = sql as string;
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
  });

  describe('without cash counts (backwards-compatible)', () => {
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
      expect(executeCalls).toContain('BEGIN TRANSACTION');
      expect(executeCalls).toContain('COMMIT');
      expect(insertZReport).toHaveBeenCalledOnce();
      expect(insertZReportCounts).toHaveBeenCalledOnce();
      expect(advanceZChain).toHaveBeenCalledOnce();
      expect(updateGrandTotals).toHaveBeenCalledOnce();
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
});
