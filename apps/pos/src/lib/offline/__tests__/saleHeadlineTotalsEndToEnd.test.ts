/**
 * C-6 / z-headline-net-sales — the HEADLINE mirror of
 * `saleReportingEndToEnd.test.ts` (C-2, which fixed the PER-RATE rows).
 *
 * **The defect.** `offline_receipts.subtotal` is a MISNOMER: the writer stores
 * Σ GROSS `line_total` there (`receiptService.ts:149-157` `computeLineTotals`
 * → `:547`), while the canonical, server-enforced SALE_RECEIPT field also
 * called `subtotal` is the NET (`SaleReceiptPayload.ts:121`:
 * `subtotalNet = bcsub(subtotalGross, taxAmount)`). All three
 * `offline_receipts` consumers accumulated the SQLite column straight into the
 * signed headline `net_sales`, so on every taxed shift
 * `net_sales == gross_sales` — exported by NF525 as `<VentesNettes>`
 * (`Nf525XmlBuilder.php:298-300`) and passed through verbatim by
 * `ZReportProjection.php:148-150`.
 *
 * **The chosen identity, and why (settled with writer evidence).**
 *
 *   net_sales += receipt.subtotal − receipt.tax_amount
 *
 * NOT `receipt.total − receipt.tax_amount`. The two agree on an undiscounted,
 * un-cash-rounded receipt and diverge otherwise, because the three stored
 * columns do not share a base:
 *
 *   - `subtotal`   = Σ gross `line_total`  — PRE transaction discount, PRE cash rounding
 *   - `tax_amount` = Σ line `tax_amount`   — PRE transaction discount, PRE cash rounding
 *   - `total`      = the ROUNDED total     — POST discount, POST cash rounding
 *     (`receiptService.ts:548` ← `:290` `bcformat(policySnapshot.roundedTotal)`)
 *
 * Subtracting the pre-discount VAT out of the post-discount gross mixes bases
 * and produces a figure that is neither the sealed receipt's own net nor the
 * sum of the per-rate nets. `subtotal − tax_amount` instead reproduces the
 * sealed SALE_RECEIPT `subtotal` BYTE FOR BYTE (`receiptService.ts:368` hands
 * exactly `subtotalGross`/`taxAmount` to the builder), so the Z headline
 * becomes Σ of the sealed per-receipt nets — the same corpus tie the M1 ruling
 * used (V-14) to rule Option B for the per-rate rows.
 *
 * It is also the only choice that keeps the payload internally consistent:
 * after C-2, Σ over lines of (line_total − line tax) == subtotal − tax_amount
 * EXACTLY (bc arithmetic at the currency scale on scale-formatted values
 * distributes), so on a refund-free shift
 *
 *     Σ vat_breakdown[].net_amount == net_sales
 *     Σ vat_breakdown[].vat_amount == tax_amount
 *
 * which is precisely SALE_RECEIPT aggregate invariants #2/#3
 * (`FiscalPayloadConstraintValidator.php:1155-1170`) — the invariant the F-4
 * server tripwire in this same lane extends to the Z family. `total −
 * tax_amount` would FAIL that tripwire on every discounted or cash-rounded
 * shift. Both facts are pinned as tests below, not asserted in prose.
 *
 * The transaction-discount / cash-rounding wedge therefore lives exactly where
 * the canonical receipt puts it — between `gross_sales` (Σ `total`) and
 * `net_sales + tax_amount`, reconciled on the receipt by
 * `transaction_discount_amount` + `cash_rounding_adjustment`. The Z payload
 * carries neither field, which is why no `net + tax == gross` identity is
 * asserted anywhere (the SALE_RECEIPT validator's own identity #1 needs the
 * discount term too — `FiscalPayloadConstraintValidator.php:1128-1140`).
 *
 * Same evidence discipline as C-2: REAL cart tax via `computeTaxAmount`, REAL
 * `createOfflineReceipt` → REAL SQLite → REAL `FiscalEventEngine`, then THAT
 * row read back through all three consumers, asserted against hand arithmetic.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';

// ─── Hoisted mocks (same harness as saleReportingEndToEnd.test.ts) ───────────

const TERMINAL_UUID_HOISTED = vi.hoisted(() => '11111111-1111-4111-8111-111111111111');

const h = vi.hoisted(() => ({
  db: null as unknown,
  engine: null as unknown,
  appendXReportMock: vi.fn(),
  appendZSessionCloseAndZReportMock: vi.fn(),
  shift: null as unknown,
}));

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

const COMPANY_ID_HOISTED = vi.hoisted(() => 'company-1');

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: () => ({ companyId: COMPANY_ID_HOISTED, companies: [{ id: COMPANY_ID_HOISTED, currency: 'EUR' }] }),
  },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: () => ({ shift: h.shift, terminal: { id: TERMINAL_UUID_HOISTED } }) },
}));

vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: { getState: () => ({ isOnline: false }) },
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: { getState: () => ({ incrementPendingCount: vi.fn() }) },
}));

vi.mock('@/lib/fiscal/zReportHashService', () => ({
  computeZReportHash: vi.fn().mockResolvedValue('z-hash'),
}));

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
import { bcadd } from '@/lib/decimal';
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

/** The ticket-level discount used by the transaction-discount case. */
const TXN_DISCOUNT = '2.00';
/** total = subtotal(gross) − discount = 12.00 − 2.00. */
const DISCOUNTED_TOTAL = '10.00';

async function runAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const m of migrations) {
    if (m.run) await m.run(adapter);
    else if (m.sql) await adapter.execute(m.sql);
  }
}

function saleCartItem(
  gross: string = GROSS,
  rate: string = TAX_RATE,
  expectedVat: string = VAT,
  id = 'sale-line-0',
): CartItem {
  const taxAmount = computeTaxAmount(gross, rate);
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
  transactionDiscount?: { type: 'fixed' | 'percentage'; value: string; reason: string },
): Parameters<typeof createOfflineReceipt>[1] {
  return {
    tenantId: TENANT_ID,
    companyId: COMPANY_ID,
    terminalId: TERMINAL_UUID,
    operatorId: OPERATOR_UUID,
    operatorName: 'Alice',
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
    ...(transactionDiscount ? { transactionDiscount } : {}),
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

describe('C-6 sale headline totals — REAL writer through Z / EOD / X', () => {
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

  async function writeRealSaleRow(
    input: Parameters<typeof createOfflineReceipt>[1] = saleInput(),
  ): Promise<Record<string, string>> {
    await createOfflineReceipt(adapter as unknown as Database, input);
    const rows = await adapter.select<Array<Record<string, string>>>(
      'SELECT * FROM offline_receipts',
      [],
    );
    expect(rows).toHaveLength(1);
    return rows[0]!;
  }

  /** The SEALED SALE_RECEIPT payload for the one receipt on the chain. */
  async function sealedSaleReceiptPayload(): Promise<Record<string, string>> {
    const events = await adapter.select<Array<Record<string, string>>>(
      "SELECT canonical_bytes FROM fiscal_events WHERE event_type = 'SALE_RECEIPT'",
      [],
    );
    expect(events).toHaveLength(1);
    const envelope = JSON.parse(events[0]!['canonical_bytes']!) as { payload: Record<string, string> };
    return envelope.payload;
  }

  // ── The premise: the column and the canonical field disagree ─────────────

  it('the REAL writer stores GROSS in `offline_receipts.subtotal` while the SEALED payload `subtotal` is NET', async () => {
    const row = await writeRealSaleRow();

    // The SQLite column is Σ gross line_total — equal to the gross total here.
    expect(row['subtotal']).toBe(GROSS);
    expect(row['tax_amount']).toBe(VAT);
    expect(row['total']).toBe(GROSS);

    // The canonical, server-validated field of the SAME name is the NET.
    const sealed = await sealedSaleReceiptPayload();
    expect(sealed['subtotal']).toBe(NET);
    expect(sealed['vat_total']).toBe(VAT);
    expect(sealed['total']).toBe(GROSS);

    // …and the chosen identity reproduces it exactly from the two columns.
    expect(row['subtotal']).not.toBe(sealed['subtotal']);
  });

  // ── The three consumers ──────────────────────────────────────────────────

  it('consumer 1 — the SIGNED Z headline net_sales is the NET, not the gross', async () => {
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
    expect(z.report_data.gross_sales).toBe(GROSS);
    expect(z.report_data.tax_amount).toBe(VAT);
    // The defect: this was GROSS ('12.00'), so net_sales === gross_sales on
    // every taxed shift and NF525 <VentesNettes> carried the TTC figure.
    expect(z.report_data.net_sales).toBe(NET);
    expect(z.report_data.net_sales).not.toBe(z.report_data.gross_sales);
  });

  it('consumer 2 — the EOD preview headline net_sales is the NET, not the gross', async () => {
    await writeRealSaleRow();

    const eod = await buildEndOfDayPreview(
      adapter as unknown as Database,
      TERMINAL_UUID,
      SHIFT_OPENED_AT,
      '100.00',
      'EUR',
    );

    expect(eod.sales_count).toBe(1);
    expect(eod.gross_sales).toBe(GROSS);
    expect(eod.tax_amount).toBe(VAT);
    expect(eod.net_sales).toBe(NET);
  });

  it('consumer 3 — the SIGNED X headline net_sales is the NET, not the gross', async () => {
    await writeRealSaleRow();

    const x = await generateXReport(TERMINAL_UUID, {
      tenantId: TENANT_ID,
      fiscalShiftId: SHIFT_UUID,
      fiscalSessionId: SESSION_UUID,
      operatorId: OPERATOR_UUID,
      operatorName: 'Alice',
    });

    expect(x.sales_count).toBe(1);
    expect(x.gross_sales).toBe(GROSS);
    expect(x.tax_amount).toBe(VAT);
    expect(x.net_sales).toBe(NET);

    // …and the SIGNED X_REPORT event carries the same, not the gross figure.
    expect(h.appendXReportMock).toHaveBeenCalledOnce();
    const signedX = h.appendXReportMock.mock.calls[0]![2] as {
      reportTotals: Record<string, string | number>;
    };
    expect(signedX.reportTotals['net_sales']).toBe(NET);
    expect(signedX.reportTotals['gross_sales']).toBe(GROSS);
  });

  // ── SESSION_CLOSE follows the Z automatically — asserted, not assumed ────

  it('SESSION_CLOSE and Z_REPORT for the same close carry byte-identical headline totals', async () => {
    await writeRealSaleRow();

    await generateZReport(
      adapter as unknown as Database,
      TERMINAL_UUID,
      'shift-1',
      SHIFT_OPENED_AT,
      '100.00',
      zOpts,
    );

    expect(h.appendZSessionCloseAndZReportMock).toHaveBeenCalledOnce();
    const closeInput = h.appendZSessionCloseAndZReportMock.mock.calls[0]![2] as AuthorZSessionCloseInput;

    const closedAt = new Date('2026-08-21T18:00:00.000Z');
    const zPayload = buildZReportPayload(closeInput, closedAt, {});
    const sessionClosePayload = buildSessionClosePayload(closeInput, closedAt, 'session-close-uuid');

    // The two builders use DIFFERENT container keys for the same three figures
    // (`receipt_totals` on Z_REPORT, `sales_totals` on SESSION_CLOSE —
    // `zSessionAuthoring.ts:427/:482`), so byte-identity is asserted on the
    // three shared money fields rather than on the object.
    const zTotals = zPayload['receipt_totals'] as Record<string, string>;
    const scTotals = sessionClosePayload['sales_totals'] as Record<string, string>;
    expect(scTotals['gross_sales']).toBe(zTotals['gross_sales']);
    expect(scTotals['net_sales']).toBe(zTotals['net_sales']);
    expect(scTotals['tax_amount']).toBe(zTotals['tax_amount']);

    // …and that shared value is the CORRECTED net — byte-identity alone would
    // pass on two wrong payloads.
    expect(zTotals['net_sales']).toBe(NET);
    expect(scTotals['net_sales']).toBe(NET);
  });

  // ── The identity that F-4 (the server tripwire) will enforce ─────────────

  it('on a refund-free shift the headline reconciles with the per-rate rows EXACTLY', async () => {
    // Two receipts so the reconciliation is an actual sum, not a one-row echo.
    await createOfflineReceipt(adapter as unknown as Database, saleInput());
    await createOfflineReceipt(adapter as unknown as Database, saleInput());

    const z = await generateZReport(
      adapter as unknown as Database,
      TERMINAL_UUID,
      'shift-1',
      SHIFT_OPENED_AT,
      '100.00',
      zOpts,
    );

    expect(z.report_data.refunds_count).toBe(0);
    let sumNet = '0.00';
    let sumVat = '0.00';
    for (const row of z.report_data.vat_breakdown) {
      sumNet = bcadd(sumNet, row.net_amount, 2);
      sumVat = bcadd(sumVat, row.vat_amount, 2);
    }
    // SALE_RECEIPT aggregate invariants #2/#3, lifted to the Z family — the
    // exact pair the F-4 server tripwire in this lane asserts.
    expect(sumNet).toBe(z.report_data.net_sales);
    expect(sumVat).toBe(z.report_data.tax_amount);
    expect(z.report_data.net_sales).toBe('20.00');
  });

  // ── The transaction-discount case: the identity, pinned ─────────────────

  it('with a ticket discount, net_sales is Σ sealed receipt `subtotal` — NOT `total − tax_amount`', async () => {
    const row = await writeRealSaleRow(
      saleInput([saleCartItem()], DISCOUNTED_TOTAL, {
        type: 'fixed',
        value: TXN_DISCOUNT,
        reason: 'manager goodwill',
      }),
    );

    // The three columns, on three different bases (the whole reason the
    // identity had to be settled rather than guessed).
    expect(row['subtotal']).toBe(GROSS);              // pre-discount gross
    expect(row['tax_amount']).toBe(VAT);              // pre-discount VAT
    expect(row['total']).toBe(DISCOUNTED_TOTAL);      // post-discount gross
    expect(row['transaction_discount_amount']).toBe(TXN_DISCOUNT);

    const sealed = await sealedSaleReceiptPayload();
    expect(sealed['subtotal']).toBe(NET);             // canonical net: 12.00 − 2.00
    expect(sealed['total']).toBe(DISCOUNTED_TOTAL);

    const z = await generateZReport(
      adapter as unknown as Database,
      TERMINAL_UUID,
      'shift-1',
      SHIFT_OPENED_AT,
      '100.00',
      zOpts,
    );

    expect(z.report_data.gross_sales).toBe(DISCOUNTED_TOTAL);
    expect(z.report_data.tax_amount).toBe(VAT);
    // The chosen identity: Σ (subtotal − tax_amount) == Σ sealed `subtotal`.
    expect(z.report_data.net_sales).toBe(sealed['subtotal']);
    expect(z.report_data.net_sales).toBe(NET);
    // The REJECTED identity would have produced 10.00 − 2.00 = 8.00 …
    expect(z.report_data.net_sales).not.toBe('8.00');
    // … and 8.00 would contradict the per-rate rows, which stay pre-discount
    // (the transaction discount never enters `vat_breakdown` — on the receipt
    // either: `SaleReceiptPayload.ts:340-348` sums line nets/VATs only).
    expect(z.report_data.vat_breakdown).toEqual([
      { tax_rate: 20, net_amount: NET, vat_amount: VAT, gross_amount: GROSS },
    ]);

    // Stated rather than glossed: with a ticket discount the headline does NOT
    // satisfy net + tax == gross. The wedge is exactly the discount, and it is
    // the same wedge the canonical receipt carries (validator identity #1 adds
    // `transaction_discount_amount` back — :1128-1140). No Z field records it,
    // so no such identity is asserted here or in the F-4 tripwire.
    expect(bcadd(z.report_data.net_sales, z.report_data.tax_amount, 2)).toBe(
      bcadd(z.report_data.gross_sales, TXN_DISCOUNT, 2),
    );

    // Both signed sibling consumers agree.
    const eod = await buildEndOfDayPreview(
      adapter as unknown as Database,
      TERMINAL_UUID,
      SHIFT_OPENED_AT,
      '100.00',
      'EUR',
    );
    expect(eod.net_sales).toBe(NET);

    const x = await generateXReport(TERMINAL_UUID, {
      tenantId: TENANT_ID,
      fiscalShiftId: SHIFT_UUID,
      fiscalSessionId: SESSION_UUID,
      operatorId: OPERATOR_UUID,
      operatorName: 'Alice',
    });
    expect(x.net_sales).toBe(NET);
  });

  // ── F-6: the headline accumulators' currency-scale arguments ─────────────

  /**
   * Insert an `offline_receipts` row directly, with sub-scale precision in the
   * three HEADLINE columns.
   *
   * Same rationale as C-2's R-3 fixture (`saleReportingEndToEnd.test.ts:616`):
   * the scale arguments are invisible on a well-formed scale-2 row because
   * every consumer re-formats through `bcformat(total, scale)` at emission, so
   * the drift only shows when a stored value carries MORE precision than the
   * currency scale — which the real writer DOES produce on the cash-rounding
   * path (`receiptService.cashRounding.test.ts:202` persists a `'9.997'`).
   * A precision fixture, not a decomposition one.
   *
   * Two rows at subtotal 10.006 / tax 0.001 / total 10.005 on EUR (scale 2):
   *   accumulated at scale 2 → 10.01 + 10.01 = 20.02 gross,
   *                            (10.01 − 0.00) × 2 = 20.02 net   ✅
   *   accumulated at scale 3 → 10.005 + 10.005 = 20.010 → 20.01 gross,
   *                            10.005 − 0.001 = 10.004, ×2 = 20.008 → 20.01 net ❌
   */
  async function insertSubScaleHeadlineRow(suffix: string): Promise<void> {
    const lines = JSON.stringify([
      { name: 'W', quantity: 1, unit_price: '10.006', line_total: '10.006', tax_rate: TAX_RATE, tax_amount: '0.001' },
    ]);
    await adapter.execute(
      `INSERT INTO offline_receipts (
         id, idempotency_key, receipt_number, terminal_id, terminal_code,
         operator_id, operator_name, lines, subtotal, tax_amount, discount_amount,
         total, currency, fiscal_hash, previous_hash, hash_sequence,
         tendered_amount, change_due, payment_method_id, payment_repository_id, status,
         payments_json, fiscal_schema_version, is_training
       ) VALUES ($1,$2,$3,$4,'T01',$5,'Alice',$6,'10.006','0.001','0.00','10.005','EUR',
                 $8,'genesis',$9,'10.005','0.00','pm-cash','pr-cash','pending',$7,3,0)`,
      [
        `precision-row-${suffix}`, `idem-prec-${suffix}`, `T001-900${suffix}`, TERMINAL_UUID, OPERATOR_UUID, lines,
        JSON.stringify([{ method_code: 'CASH', amount: '10.005' }]),
        `h-prec-${suffix}`, Number(suffix),
      ],
    );
  }

  it('F-6 — the SIGNED Z headline accumulates at the CURRENCY scale, not decimal.ts\'s default of 3', async () => {
    await insertSubScaleHeadlineRow('1');
    await insertSubScaleHeadlineRow('2');

    const z = await generateZReport(
      adapter as unknown as Database,
      TERMINAL_UUID,
      'shift-1',
      SHIFT_OPENED_AT,
      '100.00',
      zOpts,
    );

    // Per-row rounding at the currency scale (Big.RM = 1, ROUND_HALF_UP):
    // 10.005 → 10.01 each, so 20.02 — not 20.01.
    expect(z.report_data.gross_sales).toBe('20.02');
    // net: (10.006 − 0.001) rounded at scale 2 = 10.01 each → 20.02.
    expect(z.report_data.net_sales).toBe('20.02');
    // tax: 0.001 → 0.00 each → 0.00 (unscaled it accumulates 0.002 → '0.00'
    // too, so this one rests on structural identity with its two siblings —
    // stated rather than glossed, exactly as C-2's M4 did for its vat leg).
    expect(z.report_data.tax_amount).toBe('0.00');
  });

  it('F-6 — the SIGNED X headline accumulates at the CURRENCY scale, not decimal.ts\'s default of 3', async () => {
    await insertSubScaleHeadlineRow('1');
    await insertSubScaleHeadlineRow('2');

    const x = await generateXReport(TERMINAL_UUID, {
      tenantId: TENANT_ID,
      fiscalShiftId: SHIFT_UUID,
      fiscalSessionId: SESSION_UUID,
      operatorId: OPERATOR_UUID,
      operatorName: 'Alice',
    });

    expect(x.gross_sales).toBe('20.02');
    expect(x.net_sales).toBe('20.02');
  });

  it('F-6 — the EOD preview headline accumulates at the CURRENCY scale', async () => {
    await insertSubScaleHeadlineRow('1');
    await insertSubScaleHeadlineRow('2');

    const eod = await buildEndOfDayPreview(
      adapter as unknown as Database,
      TERMINAL_UUID,
      SHIFT_OPENED_AT,
      '100.00',
      'EUR',
    );

    expect(eod.gross_sales).toBe('20.02');
    expect(eod.net_sales).toBe('20.02');
  });
});
