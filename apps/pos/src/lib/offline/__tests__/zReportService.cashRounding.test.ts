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

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setWriter, __resetWriteGateForTesting } from '@/lib/db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

// ─── Hoist mocks before any imports ──────────────────────────────────────────

const fiscalMocks = vi.hoisted(() => ({
  engine: { append: vi.fn() },
}));

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  // B3 (Lane B, 2026-07-31): real pass-throughs, NOT stubs. The
  // "signed Z bytes" describe block below un-mocks the real
  // `zSessionAuthoring` module via `vi.importActual`, and its real
  // `appendZSessionCloseAndZReport` calls `closeLocalShift`
  // (`localShiftRepository.ts`), which imports `execute`/`queryOne`
  // from THIS module. Mirrors `@/lib/db.ts`'s actual implementation
  // (`database.execute(sql, params)` / `database.select(...)`) exactly,
  // so it is correct for a REAL `SqliteTestAdapter` while remaining a
  // no-op for the OTHER describe blocks in this file, none of which
  // exercise `execute`/`queryOne` from this module (they mock `queryAll`
  // directly and drive their own `db.execute` stub).
  queryOne: vi.fn(async (database: { select: (sql: string, params?: unknown[]) => Promise<unknown[]> }, sql: string, params: unknown[] = []) => {
    const rows = await database.select(sql, params);
    return rows[0] ?? null;
  }),
  execute: vi.fn((database: { execute: (sql: string, params?: unknown[]) => Promise<unknown> }, sql: string, params: unknown[] = []) => database.execute(sql, params)),
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

// B3 additions (Lane B, 2026-07-31) — real-signing coverage for the
// `appendZSessionCloseAndZReport` handoff. None of these modules are
// mocked anywhere in this file (only `@/lib/fiscal/zSessionAuthoring`
// itself, `@/lib/fiscal/instance`, and the repositories mocked above
// are), so these are plain, real static imports.
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { FiscalEventEngine } from '@/lib/fiscal/FiscalEventEngine';
import { FiscalEventCanonicalEncoder } from '@/lib/fiscal/FiscalEventCanonicalEncoder';
import { HashChainIntegrityProvider } from '@/lib/fiscal/HashChainIntegrityProvider';
import { FiscalEventPayloadRegistry } from '@/lib/fiscal/FiscalEventPayloadRegistry';
import type { AuthorZSessionCloseInput } from '@/lib/fiscal/zSessionAuthoring';

// ─── Test helpers ─────────────────────────────────────────────────────────────

const SHIFT_OPENED_AT = '2026-07-27T08:00:00+00:00';

interface SeedReceiptInput {
  total: string;
  tolerance_shortfall?: string | null;
  cash_rounding_adjustment?: string | null;
  /** v3-refund-chain-integration spec §7.3 — 'sale' (default) or 'refund'.
   *  A refund row's `total`/`cash_rounding_adjustment` are still passed as
   *  the caller supplies them (this fixture does not auto-negate) — tests
   *  that seed a refund row pass an already-negative-signed `total`
   *  themselves, matching §7.2's own negative-signed convention. */
  receipt_kind?: 'sale' | 'refund';
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
    receipt_kind: input.receipt_kind ?? 'sale',
  };
}

function mockShift(receipts: Array<Record<string, unknown>>): Database {
  vi.mocked(queryAll).mockImplementation(async (_db, sql) => {
    const s = sql as string;
    if (s.includes('offline_receipts')) return receipts as unknown as never[];
    if (s.includes('payment_methods')) {
      // I-1 — see zReportService.test.ts: the Z aggregation classifies cash by
      // `is_cash_tender`, so the cached rows must carry it.
      return [
        { id: 'pm-cash', code: 'CASH', is_cash_tender: 1, is_active: 1 },
        { id: 'pm-card', code: 'CARD', is_cash_tender: 0, is_active: 1 },
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

  // ─── §7.3 refund-row routing AT TND (3-decimal) SCALE, combined with the
  // Task 9 cash-rounding/tolerance columns — the exact interaction
  // zReportService.test.ts (EUR/2-decimal, no rounding) never exercises. ───

  it('§7.3: a refund row SUBTRACTS its own cash_rounding_adjustment from the shift total (mirrors a sale row adding)', async () => {
    install([
      makeReceipt({ total: '10.000', cash_rounding_adjustment: '0.003' }),
      // A refund's own rounding reverses the sale's — canonical-zero on
      // every launch v4 refund in practice, but the aggregation must stay
      // forward-correct regardless (zReportService.ts's own §7.3 comment).
      makeReceipt({ total: '-9.950', cash_rounding_adjustment: '0.020', receipt_kind: 'refund' }),
    ]);

    const z = await generateZReport(db, 'term-1', 'shift-1', SHIFT_OPENED_AT, '0.000');

    expect(z.report_data.cash_rounding_summary).toEqual({
      total_adjustment: '-0.017', // 0.003 (sale, added) − 0.020 (refund, subtracted)
      receipt_count: 2,
    });
  });

  it('§7.3: a refund row is excluded from gross_sales/net_sales even at TND scale', async () => {
    install([
      makeReceipt({ total: '10.000' }),
      makeReceipt({ total: '-9.950', receipt_kind: 'refund' }),
    ]);

    const z = await generateZReport(db, 'term-1', 'shift-1', SHIFT_OPENED_AT, '0.000', CASH_COUNT('0.050'));

    // Refund is tracked on its own — never folded into gross/net sales.
    expect(z.report_data.gross_sales).toBe('10.000');
    expect(z.report_data.net_sales).toBe('10.000');
  });

  it('§7.2a: tolerance_shortfall is always null on a refund row by construction — a mixed shift reflects only the sale-side shortfall', async () => {
    install([
      makeReceipt({ total: '9.950', tolerance_shortfall: '0.050' }),
      // A refund row NEVER carries a tolerance_shortfall (§7.2a — no
      // tender-tolerance concept applies to a refund payout); this
      // fixture omits it (defaults null) to mirror real data exactly.
      makeReceipt({ total: '-5.000', receipt_kind: 'refund' }),
    ]);

    const z = await generateZReport(
      db, 'term-1', 'shift-1', SHIFT_OPENED_AT, '0.000', CASH_COUNT('14.900'),
    );

    expect(z.report_data.tolerance_summary).toEqual({
      totalAmount: '0.050',
      currencyCode: 'TND',
      writeoffCount: 1,
    });
  });
});

// ─── B3 (Lane B, 2026-07-31) — appendZSessionCloseAndZReport handoff ────────
//
// Every test above runs against the file-level `vi.mock('@/lib/fiscal/
// zSessionAuthoring', ...)` (line ~49), which replaces
// `appendZSessionCloseAndZReport` with a `vi.fn()` that returns a fixed fake
// shape and never inspects what `closeInput` it was called with. This block
// bypasses that mock with `vi.importActual` and drives the REAL
// `appendZSessionCloseAndZReport` against a REAL `FiscalEventEngine` +
// `SqliteTestAdapter`, then reads back the ACTUAL bytes the engine signed —
// `FiscalEventAppendResult.canonical_bytes`, the exact JSON that is
// SHA-256-hashed into `current_hash` (mirrors the read-back technique in
// `zSessionAuthoring.test.ts:545-554`) — and asserts `tolerance_summary`
// landed there with real, non-zero values.
//
// Scope, precisely: `b3CloseInput()` below HAND-BUILDS an
// `AuthorZSessionCloseInput` directly — it does not call `generateZReport`
// and never drives `zReportService.ts`'s private `buildFiscalCloseInput`
// (the `zReport.tolerance_summary` → `closeInput.toleranceSummary` mapping
// on the OTHER side of this handoff). What this block proves is the
// authoring→signed-bytes leg alone: GIVEN a `toleranceSummary` on
// `AuthorZSessionCloseInput`, `appendZSessionCloseAndZReport` /
// `buildZReportPayload` (zSessionAuthoring.ts) correctly carries it,
// unmutated, into the signed `Z_REPORT` canonical bytes. A regression in
// `buildFiscalCloseInput`'s mapping itself would NOT be caught here — that
// half is covered by the "generateZReport" describe block above (via the
// MOCKED `appendZSessionCloseAndZReport`, which cannot see the signed bytes
// but DOES see the `closeInput` shape `generateZReport` passes it — no
// assertion on that call currently exists, so that half is not directly
// pinned by any test in this file today).
const B3_TENANT_ID = 'b3-tenant-1';
const B3_COMPANY_ID = 'b3-company-1';
const B3_TERMINAL_ID = 'b3b3b3b3-b3b3-4b3b-8b3b-b3b3b3b3b3b3';
const B3_OPERATOR_ID = 'b3b3b3b3-b3b3-4b3b-8b3b-b3b3b3b3b3b4';
const B3_SHIFT_ID = 'b3b3b3b3-b3b3-4b3b-8b3b-b3b3b3b3b3b5';
const B3_SESSION_ID = 'b3b3b3b3-b3b3-4b3b-8b3b-b3b3b3b3b3b6';
const B3_GENESIS_SEED = 'f'.repeat(64);

async function b3SeedTerminalState(adapter: SqliteTestAdapter): Promise<void> {
  await adapter.execute(
    `INSERT INTO terminal_state (
       terminal_id, terminal_code, genesis_seed, last_hash,
       fiscal_event_genesis_seed, z_chain_genesis_seed
     ) VALUES ($1, 'T01', 'legacy-seed', 'legacy-hash', $2, $2)`,
    [B3_TERMINAL_ID, B3_GENESIS_SEED],
  );
}

function b3CloseInput(
  toleranceSummary: Record<string, unknown> | null,
): AuthorZSessionCloseInput {
  return {
    tenantId: B3_TENANT_ID,
    companyId: B3_COMPANY_ID,
    terminalId: B3_TERMINAL_ID,
    terminalLabel: 'T01',
    shiftId: B3_SHIFT_ID,
    sessionId: B3_SESSION_ID,
    businessDate: '2026-07-31',
    operatorId: B3_OPERATOR_ID,
    operatorName: 'Alice',
    currencyCode: 'TND',
    currencyScale: 3,
    periodStart: '2026-07-31T08:00:00.000Z',
    periodEnd: '2026-07-31T18:00:00.000Z',
    zReportUuid: 'b3b3b3b3-b3b3-4b3b-8b3b-b3b3b3b3b3b7',
    zNumber: 1,
    formattedZNumber: 'Z0001',
    expectedCash: '150.000',
    countedCash: '150.000',
    varianceAmount: '0.000',
    varianceDirection: 'balanced',
    varianceSeverity: 'balanced',
    varianceReason: null,
    reportTotals: {
      sales_count: 2,
      gross_sales: '19.950',
      net_sales: '19.950',
      tax_amount: '0.000',
      refunds_count: 0,
      refunds_amount: '0.000',
      voided_count: 0,
    },
    vatBreakdown: [],
    paymentMethodTotals: [],
    cashCountLines: [],
    cashDrawerTotals: {},
    grandTotalsBefore: {},
    grandTotalsAfter: {},
    toleranceSummary,
    legacyReportReference: null,
    companySnapshot: { company_id: B3_COMPANY_ID },
    seller: null,
    operationalEventRange: { first_sequence: 1, last_sequence: 1 },
    isTraining: false,
    closedAtDevice: new Date('2026-07-31T18:00:00.000Z'),
    sessionCloseUuid: 'b3b3b3b3-b3b3-4b3b-8b3b-b3b3b3b3b3b8',
  };
}

describe('appendZSessionCloseAndZReport — SIGNED Z bytes carry real tolerance_summary (B3)', () => {
  let adapter: SqliteTestAdapter;
  let engine: FiscalEventEngine;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
    await b3SeedTerminalState(adapter);
    engine = new FiscalEventEngine(
      adapter,
      new FiscalEventCanonicalEncoder(),
      new HashChainIntegrityProvider(),
      new FiscalEventPayloadRegistry(),
    );

    // Seed the SESSION_OPEN anchor `appendZSessionCloseAndZReport` requires
    // (`requireSessionOpenEvent`), authored directly through the real engine
    // — mirrors what `authorZSessionOpenWithOpeningFloatOnDb` writes, without
    // pulling in the shift-numbering / `local_shifts` machinery this test
    // does not exercise.
    await engine.append(adapter, {
      event_type: 'SESSION_OPEN',
      tenant_id: B3_TENANT_ID,
      company_id: B3_COMPANY_ID,
      terminal_id: B3_TERMINAL_ID,
      operator_id: B3_OPERATOR_ID,
      event_time_device: '2026-07-31T08:00:00Z',
      business_date: '2026-07-31',
      chain_context: 'z_session',
      payload: {
        business_date: '2026-07-31',
        currency_code: 'TND',
        currency_scale: 3,
        opened_at_device: '2026-07-31T08:00:00.000Z',
        opening_float_amount: '100.000',
        operator_id: B3_OPERATOR_ID,
        operator_name: 'Alice',
        session_id: B3_SESSION_ID,
        shift_id: B3_SHIFT_ID,
        shift_number: 1,
        terminal_id: B3_TERMINAL_ID,
        terminal_label: 'T01',
        training_flag: false,
      },
      source_event_class: 'pos_session',
      source_event_id: B3_SESSION_ID,
    });
  });

  afterEach(() => {
    adapter.close();
  });

  it('signs a non-null tolerance_summary with real values into the Z_REPORT canonical bytes', async () => {
    // Bypasses the file-level `vi.mock('@/lib/fiscal/zSessionAuthoring', ...)`
    // for THIS call only — every other describe block in this file keeps
    // using the mocked version.
    const { appendZSessionCloseAndZReport } = await vi.importActual<
      typeof import('@/lib/fiscal/zSessionAuthoring')
    >('@/lib/fiscal/zSessionAuthoring');

    const realToleranceSummary = {
      currencyCode: 'TND',
      totalAmount: '0.075',
      writeoffCount: 2,
    };

    const result = await appendZSessionCloseAndZReport(
      adapter as unknown as Database,
      engine,
      b3CloseInput(realToleranceSummary),
    );

    expect(result.zReportEvent.event_type).toBe('Z_REPORT');

    // The exact bytes the engine hashed into `current_hash` — not a
    // re-serialization. If a regression drops `toleranceSummary` from
    // `buildZReportPayload` (zSessionAuthoring.ts), or breaks the
    // authoring→signing handoff some other way, this is what goes dark
    // first. (A regression in `buildFiscalCloseInput`'s OWN mapping —
    // zReportService.ts — is out of this test's reach: `b3CloseInput()`
    // hand-builds `AuthorZSessionCloseInput` directly and never calls that
    // private function.)
    const envelope = JSON.parse(result.zReportEvent.canonical_bytes) as {
      payload: { tolerance_summary: unknown };
    };
    expect(envelope.payload.tolerance_summary).toEqual(realToleranceSummary);
    // Three distinct non-null values, none of them a canonical/zero shape —
    // proof this is real data, not a zero-shape that happened to round-trip.
    expect(realToleranceSummary.currencyCode).not.toBe('0');
    expect(realToleranceSummary.totalAmount).not.toBe('0.000');
    expect(realToleranceSummary.writeoffCount).not.toBe(0);
  });

  it('signs a null tolerance_summary when the shift closed without cash counts (legacy shape preserved)', async () => {
    const { appendZSessionCloseAndZReport } = await vi.importActual<
      typeof import('@/lib/fiscal/zSessionAuthoring')
    >('@/lib/fiscal/zSessionAuthoring');

    const result = await appendZSessionCloseAndZReport(
      adapter as unknown as Database,
      engine,
      b3CloseInput(null),
    );

    const envelope = JSON.parse(result.zReportEvent.canonical_bytes) as {
      payload: { tolerance_summary: unknown };
    };
    expect(envelope.payload.tolerance_summary).toBeNull();
  });
});
