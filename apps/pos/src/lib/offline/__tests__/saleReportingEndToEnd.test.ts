/**
 * C-2 / z-sale-branch-decomposition — the SALE-path mirror of
 * `refundReportingEndToEnd.test.ts`.
 *
 * **Why this file exists (the mandate).** Every existing sale fixture that
 * touches the Z / EOD / X per-rate aggregation authors `lines[].line_total`
 * as NET (`zReportService.test.ts:123` writes `line_total '42.00'` on a
 * 50.00/8.00 receipt; `endOfDayPreview.test.ts:37` writes `'8.40'` on a
 * 10.00/1.60 one). The REAL writer does the opposite: `createOfflineReceipt()`
 * copies the cart's `line_total`, which is GROSS/TTC — POS `unit_price` is
 * tax-inclusive and `cartStore.recalcLineTotal()` derives
 * `line_total = unit_price × qty − discount`, then EXTRACTS `tax_amount` out
 * of it (`cartStore.computeTaxAmount`). Those hand-authored fixtures therefore
 * MASKED a VAT double-count in all three sale branches.
 *
 * This test refuses to author a fixture:
 *
 *  - the cart line's `tax_amount` is derived by calling the REAL
 *    `computeTaxAmount()` from `cartStore` — the exact function the cart uses
 *    — so the input cannot drift from the writer's own semantics;
 *  - the row is written by the REAL `createOfflineReceipt()` against a REAL
 *    SQLite database (`SqliteTestAdapter` + the real `migrations`), with a REAL
 *    `FiscalEventEngine` sealing the v3 payload and the REAL
 *    `insertOfflineReceipt()` persisting it;
 *  - THAT row, unmodified, is then read back through all THREE
 *    `offline_receipts` consumers:
 *      `generateZReport()`      (the SIGNED Z)
 *      `buildEndOfDayPreview()` (the EOD preview)
 *      `generateXReport()`      (the SIGNED X)
 *    and each one's net / VAT / gross buckets are asserted against values
 *    computed BY HAND below, never by re-running the production formula.
 *
 * Fixture: ONE unit at 12.00 TTC, 20 % VAT ⇒ gross 12.00, vat 2.00, net 10.00.
 * Before the fix every consumer reported net 12.00 / gross 14.00 — net and
 * gross each overstated by exactly the VAT.
 *
 * Also pinned here (M1 ruling condition 2): the `Z_REPORT` and `SESSION_CLOSE`
 * payloads authored for the SAME close carry a byte-identical `vat_breakdown`.
 * They read one `input.vatBreakdown` (`zSessionAuthoring.ts:440` / `:498`), so
 * the correction reaches both in lockstep — this asserts that rather than
 * assuming it.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';

// ─── Hoisted mocks ───────────────────────────────────────────────────────────

const TERMINAL_UUID_HOISTED = vi.hoisted(() => '11111111-1111-4111-8111-111111111111');

const h = vi.hoisted(() => ({
  /** Set in beforeEach to the live SqliteTestAdapter. */
  db: null as unknown,
  /** Set in beforeEach to the live FiscalEventEngine. */
  engine: null as unknown,
  appendXReportMock: vi.fn(),
  appendZSessionCloseAndZReportMock: vi.fn(),
  shift: null as unknown,
}));

// `@/lib/db` is a set of thin wrappers over `Database.select/execute`;
// re-implement them verbatim over the live adapter so every repository in the
// graph (real `insertOfflineReceipt`, real Z/EOD/X queries) hits REAL SQLite.
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
    getState: () => ({ companyId: COMPANY_ID_HOISTED, companies: [{ id: COMPANY_ID_HOISTED, currency: 'EUR' }] }),
  },
}));

const COMPANY_ID_HOISTED = vi.hoisted(() => 'company-1');

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: () => ({ shift: h.shift, terminal: { id: TERMINAL_UUID_HOISTED } }) },
}));

vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: { getState: () => ({ isOnline: false }) },
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: { getState: () => ({ incrementPendingCount: vi.fn() }) },
}));

// Z-report peripherals: the Z's own chain/persistence machinery is NOT what
// this test proves — the aggregation is. Same mock set zReportService.test.ts
// uses, EXCEPT `queryAll`, which stays REAL above so the Z reads the REAL row.
vi.mock('@/lib/fiscal/zReportHashService', () => ({
  computeZReportHash: vi.fn().mockResolvedValue('z-hash'),
}));

// Keep the REAL payload builders (this test compares two of them byte-for-byte)
// and intercept ONLY the two append functions.
vi.mock('@/lib/fiscal/zSessionAuthoring', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/fiscal/zSessionAuthoring')>();
  return {
    ...actual,
    appendZSessionCloseAndZReport: (...args: unknown[]) => h.appendZSessionCloseAndZReportMock(...args),
    appendXReport: (...args: unknown[]) => h.appendXReportMock(...args),
  };
});

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
import { buildCheckoutPolicySnapshot, type CheckoutPolicySnapshot } from '@/lib/payment/checkoutPolicySnapshot';
import { buildSessionClosePayload, buildZReportPayload } from '@/lib/fiscal/zSessionAuthoring';
import type { AuthorZSessionCloseInput } from '@/lib/fiscal/zSessionAuthoring';
import { computeTaxAmount } from '@/stores/cartStore';
import { createOfflineReceipt } from '../receiptService';
import { createRefundReceipt, type CreateRefundReceiptInput } from '../refundReceiptService';
import type { OriginalFiscalEventLocalView } from '@/lib/db/repositories/fiscalEventRepository';
import { type RefundLineInput } from '@/lib/fiscal/payloads/RefundReceiptV4Payload';
import { generateZReport } from '../zReportService';
import { buildEndOfDayPreview } from '../endOfDayPreview';
import { generateXReport } from '@/api/reportApi';
import type { CartItem } from '@/types/cart';

// ─── Constants / fixture arithmetic (computed BY HAND, not by the code) ──────

const COMPANY_ID = COMPANY_ID_HOISTED;
const TENANT_ID = 'tenant-1';
const SHIFT_UUID = '33333333-3333-4333-8333-333333333333';
const SESSION_UUID = '44444444-4444-4444-8444-444444444444';
const TERMINAL_UUID = TERMINAL_UUID_HOISTED;
const OPERATOR_UUID = '22222222-2222-4222-8222-222222222222';
const GENESIS_SEED = 'a'.repeat(64);
const SHIFT_OPENED_AT = '2020-01-01T00:00:00.000Z';

/** ONE unit at 12.00 TTC / 20 % VAT. Hand arithmetic: 12.00 / 1.2 = 10.00 net. */
const GROSS = '12.00';
const VAT = '2.00';
const NET = '10.00';
const TAX_RATE = '20.00';

/**
 * The SECOND rate for the mixed-rate case: 10.50 TTC at 5 % VAT.
 * Hand arithmetic: 10.50 / 1.05 = 10.00 net, so VAT = 0.50.
 * Deliberately chosen so the two rates share the SAME net (10.00) while
 * carrying different VAT — a bucket that leaked across rates would land on
 * a number that looks plausible, so this catches mis-keying as well as
 * mis-arithmetic.
 */
const GROSS_B = '10.50';
const VAT_B = '0.50';
const NET_B = '10.00';
const TAX_RATE_B = '5.00';

const REFUND_INTENT_ID = '55555555-5555-4555-8555-555555555555';
const ORIGINAL_FISCAL_EVENT_ID = '99999999-9999-4999-8999-999999999999';

async function runAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const m of migrations) {
    if (m.run) await m.run(adapter);
    else if (m.sql) await adapter.execute(m.sql);
  }
}

/**
 * The REAL cart shape. `line_total` is GROSS/TTC and `tax_amount` is EXTRACTED
 * out of it — and rather than hand-authoring the extraction (the exact mistake
 * that masked this bug for three waves), `tax_amount` is produced by calling
 * the cart's OWN `computeTaxAmount()`. If the cart's tax semantics ever change,
 * this fixture changes with them instead of silently disagreeing.
 */
function saleCartItem(
  gross: string = GROSS,
  rate: string = TAX_RATE,
  expectedVat: string = VAT,
  id = 'sale-line-0',
): CartItem {
  const taxAmount = computeTaxAmount(gross, rate);
  // Guard the guard: the derived value must equal the hand-computed VAT, or
  // every assertion below is measuring the wrong fixture.
  if (taxAmount !== expectedVat) {
    throw new Error(`fixture drift: computeTaxAmount('${gross}','${rate}') = ${taxAmount}, expected ${expectedVat}`);
  }
  return {
    id,
    product: { id: `prod-${id}`, name: 'Widget', sku: 'WGT-1', price: gross },
    quantity: 1,
    unit_price: gross,
    line_total: gross,
    tax_rate: rate,
    tax_amount: taxAmount,
  } as unknown as CartItem;
}

function snapshot(exactTotal: string): CheckoutPolicySnapshot {
  return buildCheckoutPolicySnapshot({
    exactTotal,
    currency: 'EUR',
    legs: [{ methodCode: 'CASH', amount: exactTotal }],
    tenderedAmount: exactTotal,
    isCashMethodCode: (code) => code === 'CASH',
    policy: null,
    fiscalSchemaVersion: 3,
    invoiceType: 'SALE',
    autoAcceptCountThisShift: 0,
  });
}

function saleInput(
  cartItems: CartItem[] = [saleCartItem()],
  exactTotal: string = GROSS,
): Parameters<typeof createOfflineReceipt>[1] {
  return {
    tenantId: TENANT_ID,
    companyId: COMPANY_ID,
    terminalId: TERMINAL_UUID,
    operatorId: OPERATOR_UUID,
    operatorName: 'Alice',
    // The SIGNED payload requires a lowercase-hex UUID here
    // (`FiscalEventEngine.ts:1603` → `assertUuid`). The device's LOCAL shift id
    // ('shift-1', below) is a separate identifier and is not signed — a
    // distinction the hand-authored fixtures never had to honour.
    shiftId: SHIFT_UUID,
    cartItems,
    currency: 'EUR',
    seller: {
      name: 'Cafe Tunis',
      taxNumber: '1234567AM000',
      countryCode: 'TN',
      street: '1 Rue de la Liberte',
      city: 'Tunis',
      postalCode: '1000',
    },
    paymentMethodId: 'pm-cash',
    paymentRepositoryId: 'pr-cash',
    tenderedAmount: exactTotal,
    payments: [{ methodCode: 'CASH', amount: exactTotal, paymentMethodId: 'pm-cash', repositoryId: 'pr-cash' }],
    policySnapshot: snapshot(exactTotal),
  } as unknown as Parameters<typeof createOfflineReceipt>[1];
}

/**
 * A REAL v4 refund that reverses the sale line: same rate, same gross, same
 * extracted VAT, negative-signed — exactly what the refund cart produces.
 *
 * M3 gate-r1 finding 7: the rate, gross and VAT are read back from the
 * PERSISTED sale row rather than re-used from the test's own constants. The
 * `vatByRate` bucket is keyed on the RAW `tax_rate` string
 * (`zReportService.ts:924`), so a `'20'` vs `'20.00'` formatting difference
 * between the sale row and the refund row would produce TWO buckets that each
 * fail to cancel — and a test that fed both sides the same constant could not
 * see it. Reading the sale row back makes the key agreement part of what is
 * asserted, not part of the fixture.
 */
function refundInputFor(
  originalLocalReceiptId: string,
  saleLine: Record<string, string>,
): CreateRefundReceiptInput {
  const rate = saleLine['tax_rate']!;
  const gross = saleLine['line_total']!;
  const vat = saleLine['tax_amount']!;
  const negGross = `-${gross}`;
  const negVat = `-${vat}`;
  const returnItem = {
    id: `return-${originalLocalReceiptId}-0`,
    product: { id: 'prod-sale-line-0', name: 'Widget', sku: 'WGT-1', price: gross },
    quantity: -1,
    unit_price: gross,
    line_total: negGross,
    tax_rate: rate,
    tax_amount: negVat,
    kind: 'return',
  } as unknown as CartItem;

  const lines: RefundLineInput[] = [
    { cartItem: returnItem, originalLineIndex: 0, disposition: 'restock', quantity: '1.000' },
  ];
  const original: OriginalFiscalEventLocalView = {
    fiscalEventId: ORIGINAL_FISCAL_EVENT_ID,
    businessDate: '2026-08-01',
    total: gross,
    cashRoundingAdjustment: '0.00',
    lineItems: [],
    payments: [{ method_code: 'CASH', amount: gross }] as unknown as OriginalFiscalEventLocalView['payments'],
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
    originalReceiptUuid: originalLocalReceiptId,
    originalBusinessDate: '2026-08-01',
    approvalReferences: [
      {
        approval_event_id: '66666666-6666-4666-8666-666666666666',
        approval_id: '77777777-7777-4777-8777-777777777777',
        approval_scope: 'void_or_return_override',
        override_event_id: '88888888-8888-4888-8888-888888888888',
        policy_version: 'v1',
        supervisor_user_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        target_reference_id: originalLocalReceiptId,
      },
    ],
    refundIntentId: REFUND_INTENT_ID,
    paymentMethodId: 'pm-cash',
    paymentRepositoryId: 'pr-cash',
  } as unknown as CreateRefundReceiptInput;
}

const zOpts = {
  tenantId: TENANT_ID,
  fiscalShiftId: SHIFT_UUID,
  fiscalSessionId: SESSION_UUID,
  terminalLabel: 'T01',
  operatorId: OPERATOR_UUID,
  operatorName: 'Alice',
};

describe('C-2 sale reporting — REAL writer through Z / EOD / X', () => {
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

  /** Drives the REAL writer and returns the persisted row. */
  async function writeRealSaleRow(): Promise<Record<string, string>> {
    await createOfflineReceipt(adapter as unknown as Database, saleInput());
    const rows = await adapter.select<Array<Record<string, string>>>(
      'SELECT * FROM offline_receipts',
      [],
    );
    expect(rows).toHaveLength(1);
    return rows[0]!;
  }

  it('the REAL writer persists a GROSS line_total with the VAT extracted out of it', async () => {
    const row = await writeRealSaleRow();

    // This is the premise the three consumers get wrong. Asserted on the REAL
    // row so this suite can never drift back into the masking fixture shape
    // (`line_total` authored as NET).
    const lines = JSON.parse(row['lines']!) as Array<Record<string, string>>;
    expect(lines[0]!['line_total']).toBe(GROSS);
    expect(lines[0]!['tax_amount']).toBe(VAT);
  });

  it('consumer 1 — the SIGNED Z decomposes the rate bucket as net = gross − vat', async () => {
    await writeRealSaleRow();

    const z = await generateZReport(
      adapter as unknown as Database,
      TERMINAL_UUID,
      'shift-1',
      SHIFT_OPENED_AT,
      '100.00',
      zOpts,
    );
    expect(z.report_data.sales_count).toBe(1);
    expect(z.report_data.vat_breakdown).toEqual([
      { tax_rate: 20, net_amount: NET, vat_amount: VAT, gross_amount: GROSS },
    ]);
  });

  it('consumer 2 — the EOD preview decomposes the rate bucket as net = gross − vat', async () => {
    await writeRealSaleRow();

    const eod = await buildEndOfDayPreview(
      adapter as unknown as Database,
      TERMINAL_UUID,
      SHIFT_OPENED_AT,
      '100.00',
      'EUR',
    );
    expect(eod.sales_count).toBe(1);
    expect(eod.vat_breakdown).toHaveLength(1);
    expect(eod.vat_breakdown[0]!.net_amount).toBe(NET);
    expect(eod.vat_breakdown[0]!.vat_amount).toBe(VAT);
    expect(eod.vat_breakdown[0]!.gross_amount).toBe(GROSS);
  });

  it('consumer 3 — the SIGNED X decomposes the rate bucket as net = gross − vat', async () => {
    await writeRealSaleRow();

    const x = await generateXReport(TERMINAL_UUID, {
      tenantId: TENANT_ID,
      fiscalShiftId: SHIFT_UUID,
      fiscalSessionId: SESSION_UUID,
      operatorId: OPERATOR_UUID,
      operatorName: 'Alice',
    });
    expect(x.sales_count).toBe(1);
    expect(x.vat_breakdown).toEqual([
      { tax_rate: 20, net_amount: NET, vat_amount: VAT, gross_amount: GROSS },
    ]);

    // …and the SIGNED X_REPORT event carries the same, not the inflated pair.
    expect(h.appendXReportMock).toHaveBeenCalledOnce();
    const signedX = h.appendXReportMock.mock.calls[0]![2] as {
      vatBreakdown: Array<Record<string, string | number>>;
    };
    expect(signedX.vatBreakdown[0]!['net_amount']).toBe(NET);
    expect(signedX.vatBreakdown[0]!['gross_amount']).toBe(GROSS);
  });

  // ── M3: the mixed-rate case ──────────────────────────────────────────────

  it('mixed rate — two rates on ONE receipt each decompose independently', async () => {
    // 12.00 @ 20 % (vat 2.00, net 10.00) + 10.50 @ 5 % (vat 0.50, net 10.00).
    // Receipt gross 22.50. The two rates share a net of 10.00 but differ in VAT,
    // so a bucket that leaked across rates would land on a plausible-looking
    // number rather than an obviously wrong one.
    const items = [
      saleCartItem(GROSS, TAX_RATE, VAT, 'sale-line-0'),
      saleCartItem(GROSS_B, TAX_RATE_B, VAT_B, 'sale-line-1'),
    ];
    await createOfflineReceipt(adapter as unknown as Database, saleInput(items, '22.50'));

    const z = await generateZReport(
      adapter as unknown as Database,
      TERMINAL_UUID,
      'shift-1',
      SHIFT_OPENED_AT,
      '100.00',
      zOpts,
    );

    // Sorted by tax_rate ascending (5 before 20).
    expect(z.report_data.vat_breakdown).toEqual([
      { tax_rate: 5, net_amount: NET_B, vat_amount: VAT_B, gross_amount: GROSS_B },
      { tax_rate: 20, net_amount: NET, vat_amount: VAT, gross_amount: GROSS },
    ]);

    const eod = await buildEndOfDayPreview(
      adapter as unknown as Database,
      TERMINAL_UUID,
      SHIFT_OPENED_AT,
      '100.00',
      'EUR',
    );
    expect(eod.vat_breakdown).toHaveLength(2);
    const eodByRate = new Map(eod.vat_breakdown.map((r) => [r.tax_rate, r]));
    // gross_amount asserted too (M3 gate-r1 finding 8): the gross accumulator
    // is one of the three lines the fix changed, and the Z and X legs assert it
    // via toEqual — leaving it out here would have left that line unpinned on
    // the one consumer whose assertions are field-by-field.
    expect(eodByRate.get(5)!.net_amount).toBe(NET_B);
    expect(eodByRate.get(5)!.vat_amount).toBe(VAT_B);
    expect(eodByRate.get(5)!.gross_amount).toBe(GROSS_B);
    expect(eodByRate.get(20)!.net_amount).toBe(NET);
    expect(eodByRate.get(20)!.vat_amount).toBe(VAT);
    expect(eodByRate.get(20)!.gross_amount).toBe(GROSS);

    const x = await generateXReport(TERMINAL_UUID, {
      tenantId: TENANT_ID,
      fiscalShiftId: SHIFT_UUID,
      fiscalSessionId: SESSION_UUID,
      operatorId: OPERATOR_UUID,
      operatorName: 'Alice',
    });
    expect(x.vat_breakdown).toEqual([
      { tax_rate: 5, net_amount: NET_B, vat_amount: VAT_B, gross_amount: GROSS_B },
      { tax_rate: 20, net_amount: NET, vat_amount: VAT, gross_amount: GROSS },
    ]);
  });

  // ── M3: the sale + refund case — THE ticket's live symptom ───────────────

  it('a fully-refunded taxed sale nets the per-rate buckets to ZERO (the interim asymmetry, closed)', async () => {
    // This is the defect the ticket describes as observable: with the refund
    // branch already corrected (Lane C) and the sale branch not, a sale and its
    // full refund left a +VAT / −0 residue instead of cancelling — e.g. +2.00
    // net / +2.00 gross on a 12.00-gross / 2.00-VAT line. Both branches now
    // derive net the same way, so the buckets must cancel EXACTLY.
    await createOfflineReceipt(adapter as unknown as Database, saleInput());
    const saleRows = await adapter.select<Array<Record<string, string>>>(
      "SELECT * FROM offline_receipts WHERE receipt_kind IS NULL OR receipt_kind != 'refund'",
      [],
    );
    expect(saleRows).toHaveLength(1);
    const saleRow = saleRows[0]!;

    // The refund reverses THIS sale — a real second write through the real
    // refund writer, not a hand-authored negative row.
    await adapter.execute(
      `INSERT INTO refund_intents (
         id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
         line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
         override_source_event_id, state, created_at, updated_at
       ) VALUES ($1, $2, $3, $4, $5, '[]', 'fp', 'a-src', 'o-src', 'approval_authored',
                 datetime('now'), datetime('now'))`,
      [REFUND_INTENT_ID, TERMINAL_UUID, OPERATOR_UUID, saleRow['id'], ORIGINAL_FISCAL_EVENT_ID],
    );
    const saleLines = JSON.parse(saleRow['lines']!) as Array<Record<string, string>>;
    await createRefundReceipt(refundInputFor(saleRow['id']!, saleLines[0]!));

    const z = await generateZReport(
      adapter as unknown as Database,
      TERMINAL_UUID,
      'shift-1',
      SHIFT_OPENED_AT,
      '100.00',
      zOpts,
    );

    // Both rows were seen AND routed to their own branch — otherwise "nets to
    // zero" could be read as "the refund row was never picked up" (it can't:
    // the sale alone would give +10.00/+2.00/+12.00, not zero — but making the
    // routing explicit costs nothing and answers the question directly).
    expect(z.report_data.sales_count).toBe(1);
    expect(z.report_data.refunds_count).toBe(1);
    expect(z.report_data.refunds_amount).toBe(GROSS);

    // ONE rate bucket, all three components exactly zero at the currency scale.
    expect(z.report_data.vat_breakdown).toEqual([
      { tax_rate: 20, net_amount: '0.00', vat_amount: '0.00', gross_amount: '0.00' },
    ]);

    const eod = await buildEndOfDayPreview(
      adapter as unknown as Database,
      TERMINAL_UUID,
      SHIFT_OPENED_AT,
      '100.00',
      'EUR',
    );
    expect(eod.vat_breakdown).toHaveLength(1);
    expect(eod.vat_breakdown[0]!.net_amount).toBe('0.00');
    expect(eod.vat_breakdown[0]!.vat_amount).toBe('0.00');
    expect(eod.vat_breakdown[0]!.gross_amount).toBe('0.00');

    const x = await generateXReport(TERMINAL_UUID, {
      tenantId: TENANT_ID,
      fiscalShiftId: SHIFT_UUID,
      fiscalSessionId: SESSION_UUID,
      operatorId: OPERATOR_UUID,
      operatorName: 'Alice',
    });
    expect(x.vat_breakdown).toEqual([
      { tax_rate: 20, net_amount: '0.00', vat_amount: '0.00', gross_amount: '0.00' },
    ]);
  });

  it('M1 ruling condition 2 — Z_REPORT and SESSION_CLOSE for the same close carry byte-identical vat_breakdown', async () => {
    await writeRealSaleRow();

    await generateZReport(
      adapter as unknown as Database,
      TERMINAL_UUID,
      'shift-1',
      SHIFT_OPENED_AT,
      '100.00',
      zOpts,
    );

    // The close authoring must actually have been reached — otherwise the two
    // payloads below would be built from a fabricated input and prove nothing.
    expect(h.appendZSessionCloseAndZReportMock).toHaveBeenCalledOnce();
    const closeInput = h.appendZSessionCloseAndZReportMock.mock.calls[0]![2] as AuthorZSessionCloseInput;

    // Both signed payloads, from the REAL builders, on the REAL close input.
    const closedAt = new Date('2026-08-21T18:00:00.000Z');
    const zPayload = buildZReportPayload(closeInput, closedAt, {});
    const sessionClosePayload = buildSessionClosePayload(closeInput, closedAt, 'session-close-uuid');

    // Byte-identical, not merely equal: the canonical encoder serializes these,
    // so ordering and formatting are part of what is signed.
    expect(JSON.stringify(sessionClosePayload['vat_breakdown']))
      .toBe(JSON.stringify(zPayload['vat_breakdown']));

    // …and that shared value is the CORRECTED decomposition, not the inflated
    // one — a byte-identity assertion alone would pass on two wrong payloads.
    expect(zPayload['vat_breakdown']).toEqual([
      { gross_amount: GROSS, net_amount: NET, tax_rate: 20, vat_amount: VAT },
    ]);
  });
});
