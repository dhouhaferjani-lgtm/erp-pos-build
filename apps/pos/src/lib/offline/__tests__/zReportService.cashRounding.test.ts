/**
 * generateZReport — real tolerance aggregation + LOCAL cash-rounding summary
 * (spec §4.3, Task 10).
 *
 * Both figures come from the receipt-level columns receiptService writes inside
 * the fiscal transaction (`tolerance_shortfall`, `cash_rounding_adjustment`),
 * which replaces the hardcoded tolerance zero-shape.
 *
 * Harness mirrors zReportService.test.ts (all DB/store reads mocked) but runs a
 * 3-decimal currency, because that is the scale cash rounding actually ships on
 * (TND, denomination 0.050).
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
  getCurrencyDecimals: vi.fn().mockReturnValue(3),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      companyId: 'co-1',
      companies: [{ id: 'co-1', currency: 'TND', name: 'Pharma' }],
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
    cumulative_sales: '500.000',
    cumulative_tax: '80.000',
    cumulative_refunds: '0.000',
    perpetual_grand_total: '500.000',
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

import type Database from '@tauri-apps/plugin-sql';

// ─── Test helpers ─────────────────────────────────────────────────────────────

const SHIFT_OPENED_AT = '2026-07-27T08:00:00+00:00';

interface SeedReceiptInput {
  total: string;
  tolerance_shortfall?: string | null;
  cash_rounding_adjustment?: string | null;
}

let seq = 0;

/** One CASH receipt at TND scale; tax is 0 so gross_sales is exactly `total`. */
function makeReceipt(input: SeedReceiptInput): Record<string, unknown> {
  seq += 1;
  return {
    id: `r${String(seq)}`,
    receipt_number: `T001-000${String(seq)}`,
    terminal_id: 'term-1',
    operator_id: 'op-1',
    operator_name: 'Alice',
    payment_method_id: 'pm-cash',
    total: input.total,
    subtotal: input.total,
    tax_amount: '0.000',
    discount_amount: '0.000',
    transaction_discount_amount: null,
    transaction_discount_reason: null,
    tendered_amount: input.total,
    change_due: '0.000',
    lines: JSON.stringify([
      {
        name: 'Widget',
        quantity: 1,
        unit_price: input.total,
        line_total: input.total,
        tax_rate: '0',
        tax_amount: '0.000',
        discount_amount: null,
      },
    ]),
    fiscal_hash: `h${String(seq)}`,
    previous_hash: 'genesis',
    hash_sequence: seq,
    currency: 'TND',
    idempotency_key: `idem-r${String(seq)}`,
    status: 'pending',
    retry_count: 0,
    payments_json: JSON.stringify([
      { payment_method_id: 'pm-cash', amount: input.total, method_code: 'CASH' },
    ]),
    consumption_mode: null,
    table_id: null,
    server_receipt_id: null,
    payment_repository_id: 'repo-1',
    terminal_code: 'T001',
    created_at: '2026-07-27 10:00:00',
    synced_at: null,
    sync_error: null,
    // The Task 9 columns. `SELECT *` carries them through untouched.
    cash_rounding_adjustment: input.cash_rounding_adjustment ?? null,
    cash_rounding_denomination:
      input.cash_rounding_adjustment == null ? null : '0.050',
    tolerance_shortfall: input.tolerance_shortfall ?? null,
  };
}

function mockShift(receipts: Array<Record<string, unknown>>): Database {
  vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
    const s = sql as string;
    if (s.includes('offline_receipts')) return receipts as unknown as never[];
    if (s.includes('payment_methods')) {
      return [
        { id: 'pm-cash', code: 'CASH' },
        { id: 'pm-card', code: 'CARD' },
      ] as unknown as never[];
    }
    return [] as unknown as never[];
  });
  return { execute: vi.fn().mockResolvedValue(undefined) } as unknown as Database;
}

const CASH_COUNT = (actual: string) => ({
  cashCounts: [
    { payment_method_id: 'pm-cash', currency_code: 'TND', actual_amount: actual },
  ],
});

// ─── Tests ───────────────────────────────────────────────────────────────────

describe('generateZReport — real tolerance + local cash rounding summary', () => {
  let db: Database;

  beforeEach(() => {
    vi.clearAllMocks();
    seq = 0;
    __resetWriteGateForTesting();
  });

  function install(receipts: Array<Record<string, unknown>>): void {
    db = mockShift(receipts);
    setWriter(db as unknown as SqlSurface);
  }

  it('aggregates tolerance_shortfall into tolerance_summary instead of the zero-shape', async () => {
    install([
      makeReceipt({ total: '9.950', tolerance_shortfall: '0.050' }),
      makeReceipt({ total: '10.000', tolerance_shortfall: null }),
    ]);

    const z = await generateZReport(
      db, 'term-1', 'shift-1', SHIFT_OPENED_AT, '0.000', CASH_COUNT('19.950'),
    );

    expect(z.report_data.tolerance_summary).toEqual({
      totalAmount: '0.050',
      currencyCode: 'TND',
      writeoffCount: 1,
    });
    expect(z.tolerance_summary).toEqual(z.report_data.tolerance_summary);
  });

  it('emits cash_rounding_summary into LOCAL report_data only', async () => {
    install([
      makeReceipt({ total: '10.000', cash_rounding_adjustment: '0.003' }),
      makeReceipt({ total: '9.950', cash_rounding_adjustment: '-0.023' }),
    ]);

    const z = await generateZReport(
      db, 'term-1', 'shift-1', SHIFT_OPENED_AT, '0.000', CASH_COUNT('19.950'),
    );

    expect(z.report_data.cash_rounding_summary).toEqual({
      total_adjustment: '-0.020',
      receipt_count: 2,
    });
  });

  it('emits NOTHING when no receipt was rounded (legacy hash shape preserved)', async () => {
    install([makeReceipt({ total: '10.000' })]);

    const z = await generateZReport(
      db, 'term-1', 'shift-1', SHIFT_OPENED_AT, '0.000', CASH_COUNT('10.000'),
    );

    expect(z.report_data).not.toHaveProperty('cash_rounding_summary');
    expect(z.report_data.tolerance_summary).toEqual({
      totalAmount: '0.000',
      currencyCode: 'TND',
      writeoffCount: 0,
    });
  });

  it('keeps report_data.schema_version at 2 (a bump breaks Z hash parity)', async () => {
    install([makeReceipt({ total: '10.000', cash_rounding_adjustment: '0.003' })]);

    const z = await generateZReport(
      db, 'term-1', 'shift-1', SHIFT_OPENED_AT, '0.000', CASH_COUNT('10.000'),
    );

    expect(z.report_data.schema_version).toBe(2);
  });

  it('records the rounding summary on a shift closed WITHOUT cash counts', async () => {
    install([makeReceipt({ total: '10.000', cash_rounding_adjustment: '0.003' })]);

    const z = await generateZReport(db, 'term-1', 'shift-1', SHIFT_OPENED_AT, '0.000');

    expect(z.report_data.cash_rounding_summary).toEqual({
      total_adjustment: '0.003',
      receipt_count: 1,
    });
    // tolerance_summary stays a v2-only key: no cash counts, no schema_version 2.
    expect(z.report_data.schema_version).not.toBe(2);
    expect(z.tolerance_summary).toBeUndefined();
  });

  it('gross_sales sums the ROUNDED totals (what was collected)', async () => {
    install([makeReceipt({ total: '10.000', cash_rounding_adjustment: '0.003' })]);

    const z = await generateZReport(db, 'term-1', 'shift-1', SHIFT_OPENED_AT, '0.000');

    expect(z.report_data.gross_sales).toBe('10.000');
  });
});
