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
function saleCartItem(): CartItem {
  const taxAmount = computeTaxAmount(GROSS, TAX_RATE);
  // Guard the guard: the derived value must equal the hand-computed VAT, or
  // every assertion below is measuring the wrong fixture.
  if (taxAmount !== VAT) {
    throw new Error(`fixture drift: computeTaxAmount('${GROSS}','${TAX_RATE}') = ${taxAmount}, expected ${VAT}`);
  }
  return {
    id: 'sale-line-0',
    product: { id: 'prod-1', name: 'Widget', sku: 'WGT-1', price: GROSS },
    quantity: 1,
    unit_price: GROSS,
    line_total: GROSS,
    tax_rate: TAX_RATE,
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

function saleInput(): Parameters<typeof createOfflineReceipt>[1] {
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
    cartItems: [saleCartItem()],
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
    tenderedAmount: GROSS,
    payments: [{ methodCode: 'CASH', amount: GROSS, paymentMethodId: 'pm-cash', repositoryId: 'pr-cash' }],
    policySnapshot: snapshot(GROSS),
  } as unknown as Parameters<typeof createOfflineReceipt>[1];
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
