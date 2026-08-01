/**
 * Lane C wave-2 fix wave — findings 1 / 4 / 14 / 16.
 *
 * **Why this file exists (the mandate).** Every wave-2 test that touched
 * the §7.3/§7.3a refund reporting branches built its `offline_receipts`
 * fixtures BY HAND, with `lines[].line_total` already NET. The REAL writer
 * (`createRefundReceipt()`) copies the cart's `line_total`, which is
 * GROSS/TTC (precision contract: POS `unit_price` is tax-inclusive, and
 * `cartStore.recalcLineTotal()` derives `line_total = unit_price × qty −
 * discount`, then EXTRACTS `tax_amount` out of it). The hand-built
 * fixtures therefore masked a VAT double-count in every consumer.
 *
 * This test refuses to author a fixture. It drives the REAL
 * `createRefundReceipt()` against a REAL SQLite database (the same
 * `SqliteTestAdapter` + real `migrations` harness
 * `resolveOriginalFiscalEventLocally.realAuthoring.test.ts` uses), with a
 * REAL `FiscalEventEngine` sealing the v4 payload and the REAL
 * `insertOfflineReceipt()` writing the row — then feeds THAT row, unmodified,
 * through all THREE `offline_receipts` consumers:
 *
 *   - `generateZReport()`      (§7.3  — the SIGNED Z)
 *   - `buildEndOfDayPreview()` (§7.3a — the EOD preview)
 *   - `generateXReport()`      (finding 1 — the SIGNED X, a third consumer
 *                               §17's manifest never enumerated)
 *
 * and asserts each one's net / VAT / gross buckets against INDEPENDENTLY
 * computed values (net = gross − vat, arithmetic done in the test's own
 * head, not by re-running the production formula).
 *
 * Fixture: ONE refunded unit at 12.00 TTC, 20% VAT ⇒ gross 12.00,
 * vat 2.00, net 10.00. Before the fix every consumer reported net −12.00 /
 * gross −14.00.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';

// ─── Hoisted mocks ───────────────────────────────────────────────────────────

/** Hoisted so the `@/stores/terminalStore` factory can close over it. */
const TERMINAL_UUID_HOISTED = vi.hoisted(() => '11111111-1111-4111-8111-111111111111');

const h = vi.hoisted(() => ({
  /** Set in beforeEach to the live SqliteTestAdapter. */
  db: null as unknown,
  /** Set in beforeEach to the live FiscalEventEngine. */
  engine: null as unknown,
  appendXReportMock: vi.fn(),
  shift: null as unknown,
}));

// `@/lib/db` is a set of thin wrappers over `Database.select/execute`;
// re-implement them verbatim over the live adapter so every repository in
// the graph (real `insertOfflineReceipt`, real `markRefundEventAppended`,
// real Z/EOD/X queries) hits REAL SQLite.
vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => h.db),
  queryAll: vi.fn(async (db: { select: (s: string, p: unknown[]) => Promise<unknown[]> }, sql: string, params: unknown[] = []) =>
    db.select(sql, params)),
  queryOne: vi.fn(async (db: { select: (s: string, p: unknown[]) => Promise<unknown[]> }, sql: string, params: unknown[] = []) => {
    const rows = await db.select(sql, params);
    return rows[0] ?? null;
  }),
  execute: vi.fn(async (db: { execute: (s: string, p: unknown[]) => Promise<unknown> }, sql: string, params: unknown[] = []) =>
    db.execute(sql, params)),
}));

vi.mock('@/lib/db/writeGate', () => ({
  withWriteTransaction: vi.fn(async (_lane: string, cb: (tx: unknown) => unknown) => cb(h.db)),
}));

vi.mock('@/lib/fiscal/instance', () => ({
  getFiscalEventEngine: vi.fn(async () => h.engine),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: () => ({ companyId: 'company-1', companies: [{ id: 'company-1', currency: 'EUR' }] }),
  },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: () => ({ shift: h.shift, terminal: { id: TERMINAL_UUID_HOISTED } }) },
}));

// Offline → `fetchShiftReceipts` takes the LOCAL fallback (finding 16's surface).
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: { getState: () => ({ isOnline: false }) },
}));

// Z-report peripherals: the Z's own chain/persistence machinery is NOT what
// this test proves — the aggregation is. Same mock set zReportService.test.ts
// uses, EXCEPT `queryAll`, which stays REAL above so the Z reads the REAL row.
vi.mock('@/lib/fiscal/zReportHashService', () => ({
  computeZReportHash: vi.fn().mockResolvedValue('z-hash'),
}));
vi.mock('@/lib/fiscal/zSessionAuthoring', () => ({
  appendZSessionCloseAndZReport: vi.fn().mockResolvedValue({
    sessionCloseEvent: { id: 'sc-1' },
    sessionCloseUuid: 'sc-uuid',
    zReportEvent: { id: 'z-1' },
  }),
  appendXReport: (...args: unknown[]) => h.appendXReportMock(...args),
}));
vi.mock('@/lib/db/repositories/zReportRepository', () => ({
  insertZReport: vi.fn().mockResolvedValue(undefined),
  getZReportByShift: vi.fn().mockResolvedValue(null),
}));
vi.mock('@/lib/db/repositories/zReportCountRepository', () => ({
  insertZReportCounts: vi.fn().mockResolvedValue(undefined),
}));
vi.mock('@/lib/db/repositories/shiftReceiptAnchorRepository', () => ({
  getShiftReceiptAnchor: vi.fn().mockResolvedValue(null),
}));

import { migrations } from '@/lib/db/migrations';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { FiscalEventEngine } from '@/lib/fiscal/FiscalEventEngine';
import { FiscalEventCanonicalEncoder } from '@/lib/fiscal/FiscalEventCanonicalEncoder';
import { HashChainIntegrityProvider } from '@/lib/fiscal/HashChainIntegrityProvider';
import { FiscalEventPayloadRegistry } from '@/lib/fiscal/FiscalEventPayloadRegistry';
import { createRefundReceipt, type CreateRefundReceiptInput } from '../refundReceiptService';
import { generateZReport } from '../zReportService';
import { buildEndOfDayPreview } from '../endOfDayPreview';
import { generateXReport, fetchShiftReceipts } from '@/api/reportApi';
import type { OriginalFiscalEventLocalView } from '@/lib/db/repositories/fiscalEventRepository';
import type { RefundLineInput } from '@/lib/fiscal/payloads/RefundReceiptV4Payload';
import type { CartItem } from '@/types/cart';

// ─── Constants / fixture arithmetic (computed BY HAND, not by the code) ──────

const TERMINAL_ID = 'terminal-1';
const COMPANY_ID = 'company-1';
const TENANT_ID = 'tenant-1';
const SHIFT_UUID = '33333333-3333-4333-8333-333333333333';
const TERMINAL_UUID = '11111111-1111-4111-8111-111111111111';
const OPERATOR_UUID = '22222222-2222-4222-8222-222222222222';
const ORIGINAL_RECEIPT_UUID = '00000000-0000-4000-8000-000000000001';
const ORIGINAL_FISCAL_EVENT_ID = '99999999-9999-4999-8999-999999999999';
const REFUND_INTENT_ID = '55555555-5555-4555-8555-555555555555';
const GENESIS_SEED = 'a'.repeat(64);
const SHIFT_OPENED_AT = '2020-01-01T00:00:00.000Z';

/** ONE unit refunded at 12.00 TTC / 20 % VAT. */
const GROSS = '12.00';
const VAT = '2.00';
const NET = '10.00'; // 12.00 − 2.00, by hand.
const NEG_GROSS = '-12.00';
const NEG_VAT = '-2.00';
const NEG_NET = '-10.00';

async function runAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const m of migrations) {
    if (m.run) await m.run(adapter);
    else if (m.sql) await adapter.execute(m.sql);
  }
}

function returnCartItem(): CartItem {
  return {
    id: `return-${ORIGINAL_RECEIPT_UUID}-0`,
    product: { id: 'prod-1', name: 'Widget', sku: 'WGT-1', price: GROSS },
    quantity: -1,
    unit_price: GROSS,
    // The REAL cart shape: line_total is GROSS/TTC, tax_amount is EXTRACTED
    // out of it (cartStore.computeTaxAmount). Negative-signed for a return.
    line_total: NEG_GROSS,
    tax_rate: '20.00',
    tax_amount: NEG_VAT,
    kind: 'return',
  };
}

function refundInput(): CreateRefundReceiptInput {
  const lines: RefundLineInput[] = [
    { cartItem: returnCartItem(), originalLineIndex: 0, disposition: 'restock', quantity: '1.0000' },
  ];
  const original: OriginalFiscalEventLocalView = {
    fiscalEventId: ORIGINAL_FISCAL_EVENT_ID,
    businessDate: '2026-07-31',
    lineItems: [],
    payments: [{ method_code: 'CASH', amount: GROSS }] as unknown as OriginalFiscalEventLocalView['payments'],
    trainingFlag: false,
    transactionDiscountAmount: '0.00',
  };
  return {
    tenantId: TENANT_ID,
    companyId: COMPANY_ID,
    terminalId: TERMINAL_UUID,
    terminalCode: 'T01',
    locationCode: 'MAIN',
    operatorId: OPERATOR_UUID,
    operatorName: 'Alice',
    shiftId: SHIFT_UUID,
    businessDate: '2026-08-01',
    eventTimeDevice: new Date('2026-08-01T10:00:00.000Z'),
    currency: 'EUR',
    seller: {
      name: 'Cafe Tunis',
      taxNumber: '1234567AM000',
      countryCode: 'TN',
      street: '1 Rue de la Liberte',
      city: 'Tunis',
      postalCode: '1000',
    },
    refundReason: 'customer return',
    lines,
    original,
    originalReceiptUuid: ORIGINAL_RECEIPT_UUID,
    originalBusinessDate: '2026-07-31',
    approvalReferences: [
      {
        approval_event_id: '66666666-6666-4666-8666-666666666666',
        approval_id: '77777777-7777-4777-8777-777777777777',
        approval_scope: 'void_or_return_override',
        override_event_id: '88888888-8888-4888-8888-888888888888',
        policy_version: 'v1',
        supervisor_user_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        target_reference_id: ORIGINAL_RECEIPT_UUID,
      },
    ],
    refundIntentId: REFUND_INTENT_ID,
    paymentMethodId: 'pm-cash',
    paymentRepositoryId: 'pr-cash',
  };
}

describe('v4 refund reporting — REAL writer through Z / EOD / X (findings 1, 4, 14, 16)', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    vi.clearAllMocks();
    adapter = new SqliteTestAdapter();
    h.db = adapter;
    await runAllMigrations(adapter);
    await adapter.execute(
      `INSERT INTO terminal_state (
         terminal_id, terminal_code, location_code, genesis_seed, last_hash,
         fiscal_event_genesis_seed, fiscal_event_last_hash, fiscal_event_sequence
       ) VALUES ($1, 'T01', 'MAIN', 'legacy-seed', 'legacy-hash', $2, '', 0)`,
      [TERMINAL_UUID, GENESIS_SEED],
    );
    await adapter.execute(
      `INSERT INTO payment_methods (id, code, name, is_active, is_cash_tender, is_physical, position)
       VALUES ('pm-cash', 'CASH', 'Cash', 1, 1, 1, 1)`,
    );
    await adapter.execute(
      `INSERT INTO refund_intents (
         id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
         line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
         override_source_event_id, state, created_at, updated_at
       ) VALUES ($1, $2, $3, $4, $5, '[]', 'fp', 'a-src', 'o-src', 'approval_authored',
                 datetime('now'), datetime('now'))`,
      [REFUND_INTENT_ID, TERMINAL_UUID, OPERATOR_UUID, ORIGINAL_RECEIPT_UUID, ORIGINAL_FISCAL_EVENT_ID],
    );
    h.engine = new FiscalEventEngine(
      adapter,
      new FiscalEventCanonicalEncoder(),
      new HashChainIntegrityProvider(),
      new FiscalEventPayloadRegistry(),
    );
    h.shift = { id: 'shift-1', opened_at: SHIFT_OPENED_AT };
  });

  afterEach(() => {
    adapter.close();
  });

  it('writes an §7.2a-correct offline_receipts row and reports correct net/VAT/gross in Z, EOD and X', async () => {
    // ── 1. REAL writer. No fixture. ────────────────────────────────────────
    await createRefundReceipt(refundInput());

    const rows = await adapter.select<Array<Record<string, string>>>(
      'SELECT * FROM offline_receipts WHERE receipt_kind = $1',
      ['refund'],
    );
    expect(rows).toHaveLength(1);
    const row = rows[0]!;

    // ── finding 14 — the §7.2a column contract. `subtotal` is the negative
    //    NET, not a third alias of the gross total. ──────────────────────────
    expect(row['total']).toBe(NEG_GROSS);
    expect(row['tax_amount']).toBe(NEG_VAT);
    expect(row['subtotal']).toBe(NEG_NET);
    // subtotal + tax_amount == total, at the configured scale.
    expect(
      (Number.parseFloat(row['subtotal']!) + Number.parseFloat(row['tax_amount']!)).toFixed(2),
    ).toBe(Number.parseFloat(row['total']!).toFixed(2));

    // The line mirror stays GROSS-signed (§7.2's stated convention) — this is
    // exactly the value the three consumers below must NOT mistake for net.
    const lines = JSON.parse(row['lines']!) as Array<Record<string, string>>;
    expect(lines[0]!['line_total']).toBe(NEG_GROSS);
    expect(lines[0]!['tax_amount']).toBe(NEG_VAT);

    // ── 2. §7.3 — the SIGNED Z. ────────────────────────────────────────────
    const z = await generateZReport(
      adapter as unknown as Database,
      TERMINAL_UUID,
      'shift-1',
      SHIFT_OPENED_AT,
      '100.00',
    );
    expect(z.report_data.sales_count).toBe(0);
    expect(z.report_data.refunds_count).toBe(1);
    expect(z.report_data.refunds_amount).toBe(GROSS);
    expect(z.report_data.vat_breakdown).toEqual([
      { tax_rate: 20, net_amount: NEG_NET, vat_amount: NEG_VAT, gross_amount: NEG_GROSS },
    ]);

    // ── 3. §7.3a — the EOD preview (REAL adapter, real query). ─────────────
    const eod = await buildEndOfDayPreview(
      adapter as unknown as Database,
      TERMINAL_UUID,
      SHIFT_OPENED_AT,
      '100.00',
      'EUR',
    );
    expect(eod.sales_count).toBe(0);
    expect(eod.vat_breakdown).toHaveLength(1);
    expect(eod.vat_breakdown[0]!.net_amount).toBe(NEG_NET);
    expect(eod.vat_breakdown[0]!.vat_amount).toBe(NEG_VAT);
    expect(eod.vat_breakdown[0]!.gross_amount).toBe(NEG_GROSS);
    // The cash payout leaves the drawer: expected = opening − 12.00.
    expect(eod.expected_cash).toBe('88.00');

    // ── 4. finding 1 — the SIGNED X. ───────────────────────────────────────
    const x = await generateXReport(TERMINAL_UUID, {
      tenantId: TENANT_ID,
      fiscalShiftId: SHIFT_UUID,
      fiscalSessionId: '44444444-4444-4444-8444-444444444444',
      operatorId: OPERATOR_UUID,
      operatorName: 'Alice',
    });
    expect(x.sales_count).toBe(0);
    expect(x.gross_sales).toBe('0.00');
    expect(x.net_sales).toBe('0.00');
    expect(x.tax_amount).toBe('0.00');
    expect(x.refunds_count).toBe(1);
    expect(x.refunds_amount).toBe(GROSS);
    expect(x.vat_breakdown).toEqual([
      { tax_rate: 20, net_amount: NEG_NET, vat_amount: NEG_VAT, gross_amount: NEG_GROSS },
    ]);

    // …and the SIGNED X_REPORT event carries the same, not zeros.
    expect(h.appendXReportMock).toHaveBeenCalledOnce();
    const signed = h.appendXReportMock.mock.calls[0]![2] as {
      reportTotals: Record<string, string | number>;
      vatBreakdown: Array<Record<string, string | number>>;
    };
    expect(signed.reportTotals['sales_count']).toBe(0);
    expect(signed.reportTotals['gross_sales']).toBe('0.00');
    expect(signed.reportTotals['refunds_count']).toBe(1);
    expect(signed.reportTotals['refunds_amount']).toBe(GROSS);
    expect(signed.vatBreakdown[0]!['net_amount']).toBe(NEG_NET);
    expect(signed.vatBreakdown[0]!['gross_amount']).toBe(NEG_GROSS);
  });

  it('finding 16 — the offline shift-receipts list labels the refund row as a return, not a sale', async () => {
    await createRefundReceipt(refundInput());

    // No server (apiGet is unmocked and will throw) → the local fallback runs.
    const receipts = await fetchShiftReceipts('shift-1');
    expect(receipts).toHaveLength(1);
    expect(receipts[0]!.receipt_type).toBe('return');
    expect(receipts[0]!.total).toBe(NEG_GROSS);
  });
});
