import Big from 'big.js';
import { apiGet, apiGetRaw, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { queryAll } from '@/lib/db';
import { toSqliteUtc } from '@/lib/db/sqliteTime';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcabs, bcadd, bcformat, bcsub } from '@/lib/decimal';
import { appendXReport } from '@/lib/fiscal/zSessionAuthoring';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { generateZReport as generateLocalZReport } from '@/lib/offline/zReportService';
import type { GenerateZReportOpts } from '@/lib/offline/zReportService';
import { getAllPaymentMethods } from '@/lib/db/repositories/paymentRepository';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import type { LocalZReport } from '@/lib/offline/types';

export type { GenerateZReportOpts };

export interface VatBreakdownItem {
  /**
   * Tax rate percentage. Local report/fiscal event paths keep this numeric;
   * UI components format it at display only.
   */
  tax_rate: number;
  net_amount: string;
  vat_amount: string;
  gross_amount: string;
}

export interface PaymentMethodItem {
  payment_type: string;
  total_amount: string;
  transaction_count: number;
}

export interface XReportResponse {
  id: string;
  terminal_id: string;
  shift_id: string | null;
  generated_by: string;
  generated_at: string;
  sales_count: number;
  gross_sales: string;
  net_sales: string;
  tax_amount: string;
  refunds_count: number;
  /** Positive magnitude (server-pinned semantics — `refunds_amount` is
   *  never folded into `gross_sales`). Added by the wave-2 fix for
   *  finding 1: the local X-report previously hardcoded a zero refund
   *  figure into the SIGNED `X_REPORT` event while silently folding
   *  refunds into the sale aggregates. */
  refunds_amount: string;
  vat_breakdown: VatBreakdownItem[];
  payment_methods: PaymentMethodItem[];
}

export interface GenerateXReportOpts {
  tenantId?: string;
  fiscalShiftId?: string;
  fiscalSessionId?: string;
  operatorId?: string;
  operatorName?: string;
  isTraining?: boolean;
}

export interface ZReportResponse {
  id: string;
  terminal_id: string;
  shift_id: string;
  z_number: number;
  fiscal_hash: string;
  previous_z_hash: string | null;
  generated_by: string;
  generated_at: string;
  is_first_z_report: boolean;
  formatted_z_number: string;
  /** True when this Z was already generated for the shift and returned idempotently. */
  was_reused?: boolean;
  /** Per-tender cash count rows — only present when cash counts were captured at shift close. */
  cash_counts?: import('@/lib/offline/types').ZReportCountEntry[];
  sales_count: number;
  gross_sales: string;
  opening_cash: string;
  expected_cash: string;
  actual_cash: string;
  variance: string;
  has_variance: boolean;
  report_data: {
    sales_count: number;
    gross_sales: string;
    net_sales: string;
    tax_amount: string;
    refunds_count: number;
    refunds_amount: string;
    voided_count: number;
    opening_cash: string;
    expected_cash: string;
    actual_cash?: string;
    variance?: string;
    vat_breakdown: VatBreakdownItem[];
    payment_methods: PaymentMethodItem[];
  };
}

export interface ReceiptPayment {
  id: string;
  payment_type: string;
  amount: string;
  card_last_four?: string | null;
  transaction_reference?: string | null;
}

export interface ShiftReceiptLine {
  id: string;
  quantity: number;
  quantity_decimals?: number | null;
  unit_price: string;
  line_total: string;
  product?: { id: string; name: string } | null;
  product_name?: string;
}

export interface ShiftReceipt {
  id: string;
  receipt_number: string;
  receipt_type: string;
  total: string;
  subtotal: string;
  tax_amount: string;
  is_voided: boolean;
  /**
   * Present on the ONLINE path only. `Receipt` has no `$hidden`, casts
   * `is_training` to boolean and lists it in `$fillable`, and
   * `ShiftController::receipts` serialises the raw models — so the server
   * already sends it and no API-resource change was needed. The offline mapper
   * in `fetchLocalShiftReceipts` does NOT emit it (nor a real `is_voided`);
   * consumers must treat `undefined` as "not known to be training", which is
   * why the panel tests `!== true` rather than trusting a default.
   */
  is_training?: boolean;
  voided_at?: string | null;
  posted_at: string;
  created_at?: string;
  payments: ReceiptPayment[];
  lines: ShiftReceiptLine[];
}

export interface CashDrawerOperation {
  id: string;
  type: 'deposit' | 'payout';
  amount: string;
  reason: string;
  created_at: string;
  user_name: string;
}

export async function generateXReport(
  terminalId: string,
  opts: GenerateXReportOpts = {},
): Promise<XReportResponse> {
  if (opts.fiscalSessionId !== undefined) {
    return generateLocalXReport(terminalId, opts);
  }

  try {
    return await apiPost<XReportResponse>('/pos/reports/x', { terminal_id: terminalId });
  } catch {
    // Offline fallback: generate X report from local SQLite data
    return await generateLocalXReport(terminalId, opts);
  }
}

/**
 * Generate Z-report locally in SQLite (offline-first).
 * Falls back to server API only if local generation is not available.
 */
export async function generateZReport(
  terminalId: string,
  companyId: string,
  shiftId: string,
  shiftOpenedAt: string,
  openingCash: string,
  opts: GenerateZReportOpts = {},
): Promise<ZReportResponse> {
  const authState = useAuthStore.getState();
  const company = authState.companies.find((c) => c.id === authState.companyId);
  const decimals = getCurrencyDecimals(company?.currency ?? 'EUR');
  const db = await getDatabase(companyId);
  const terminal = useTerminalStore.getState().terminal;
  const requiresFiscalEvents = opts.requireFiscalEvents ?? (
    terminal?.id === terminalId && terminal.fiscal_schema_version === 3
  );
  const localReport = await generateLocalZReport(db, terminalId, shiftId, shiftOpenedAt, openingCash, {
    ...opts,
    requireFiscalEvents: requiresFiscalEvents,
  });
  return localZReportToResponse(localReport, decimals);
}

/** Map local Z-report to the same shape the UI expects. */
function localZReportToResponse(report: LocalZReport, decimals: number): ZReportResponse {
  return {
    id: report.id,
    terminal_id: report.terminal_id,
    shift_id: report.shift_id,
    z_number: report.z_number,
    fiscal_hash: report.fiscal_hash,
    previous_z_hash: report.previous_hash === 'GENESIS' ? null : report.previous_hash,
    generated_by: '',
    generated_at: report.generated_at,
    is_first_z_report: report.z_number === 1,
    formatted_z_number: report.formatted_z_number,
    cash_counts: report.cash_counts,
    sales_count: report.report_data.sales_count,
    gross_sales: report.report_data.gross_sales,
    opening_cash: new Big(report.opening_cash).toFixed(decimals),
    expected_cash: new Big(report.expected_cash).toFixed(decimals),
    actual_cash: (0).toFixed(decimals),
    variance: (0).toFixed(decimals),
    has_variance: false,
    report_data: {
      sales_count: report.report_data.sales_count,
      gross_sales: report.report_data.gross_sales,
      net_sales: report.report_data.net_sales,
      tax_amount: report.report_data.tax_amount,
      refunds_count: report.report_data.refunds_count,
      refunds_amount: report.report_data.refunds_amount,
      voided_count: report.report_data.voided_count,
      opening_cash: new Big(report.opening_cash).toFixed(decimals),
      expected_cash: new Big(report.expected_cash).toFixed(decimals),
      // Preserve local Z-report tax_rate as a number; it can feed fiscal event
      // authoring and must only be formatted in presentation components.
      vat_breakdown: report.report_data.vat_breakdown.map((v) => ({
        tax_rate: v.tax_rate,
        net_amount: v.net_amount,
        vat_amount: v.vat_amount,
        gross_amount: v.gross_amount,
      })),
      payment_methods: report.report_data.payment_methods.map((p) => ({
        payment_type: p.payment_type,
        total_amount: p.total_amount,
        transaction_count: p.transaction_count,
      })),
    },
  };
}

/**
 * Server-only Z-report generation for admin/tooling fallback.
 *
 * @deprecated POS close is offline-first. Keep this helper out of the cashier close path;
 * web admin still uses the backend route through apps/web.
 */
export async function generateZReportServer(terminalId: string): Promise<ZReportResponse> {
  return apiPost<ZReportResponse>('/pos/reports/z', { terminal_id: terminalId });
}

export interface ZReportListItem {
  id: string;
  terminal_id: string;
  shift_id: string;
  z_number: number;
  formatted_z_number: string;
  generated_at: string;
  fiscal_hash: string;
  gross_sales: string;
  is_reprint: boolean;
}

/**
 * Fetch all Z-reports for the current terminal from the local SQLite store.
 * Falls back to the server API when online.
 */
export async function fetchZReports(terminalId: string, companyId: string): Promise<ZReportListItem[]> {
  try {
    const { useConnectivityStore } = await import('@/stores/connectivityStore');
    if (useConnectivityStore.getState().isOnline) {
      return await apiGet<ZReportListItem[]>(`/pos/terminals/${terminalId}/z-reports`);
    }
  } catch {
    // fall through to local
  }

  // Offline: read from local SQLite
  const db = await getDatabase(companyId);
  const { queryAll: dbQueryAll } = await import('@/lib/db');
  const rows = await dbQueryAll<{
    id: string;
    terminal_id: string;
    shift_id: string;
    z_number: number;
    formatted_z_number: string;
    generated_at: string;
    fiscal_hash: string;
    report_data: string;
  }>(
    db,
    'SELECT id, terminal_id, shift_id, z_number, formatted_z_number, generated_at, fiscal_hash, report_data FROM z_reports WHERE terminal_id = $1 ORDER BY z_number DESC',
    [terminalId],
  );

  return rows.map((row) => {
    const data = JSON.parse(row.report_data) as { gross_sales?: string };
    return {
      id: row.id,
      terminal_id: row.terminal_id,
      shift_id: row.shift_id,
      z_number: row.z_number,
      formatted_z_number: row.formatted_z_number,
      generated_at: row.generated_at,
      fiscal_hash: row.fiscal_hash,
      gross_sales: data.gross_sales ?? '0.00',
      is_reprint: false,
    };
  });
}

function isNotFound(err: unknown): boolean {
  return (
    typeof err === 'object' &&
    err !== null &&
    'status' in err &&
    (err as { status: unknown }).status === 404
  );
}

/** Server default is 20; a shift routinely exceeds that in a single hour. */
const SHIFT_RECEIPTS_PAGE_SIZE = 200;

/**
 * Safety stop for the pagination loop — 40 × 200 = 8000 receipts in one shift.
 * A `last_page` that never terminates means the server contract changed; fail
 * loudly rather than spin forever or silently truncate.
 */
const SHIFT_RECEIPTS_MAX_PAGES = 40;

interface PaginatedEnvelope<T> {
  data: T[];
  meta?: { current_page?: number; last_page?: number; per_page?: number; total?: number };
}

/**
 * A BROKEN PAGINATION CONTRACT, not a transport failure. It must never be
 * absorbed by the offline fallback below: falling back would answer a plausible
 * local figure for a shift whose true extent is unknown, which is precisely the
 * silent truncation this pagination exists to remove. Thrown from inside the try
 * and rethrown unconditionally by the catch.
 */
class ShiftReceiptsPaginationError extends Error {}

export async function fetchShiftReceipts(shiftId: string): Promise<ShiftReceipt[]> {
  try {
    // `GET /pos/shifts/{id}/receipts` PAGINATES (ShiftController::receipts ends in
    // `->paginate($request->input('per_page', 20))`, ordered `posted_at DESC`).
    // `apiGet` unwraps `json.data` and DROPS `meta`, so it returned page 1 only —
    // the 20 most recent receipts. Because the order is DESC, the rows dropped are
    // the EARLIEST, so an opening-of-shift refund stopped being deducted from the
    // net headline once 20 later receipts existed. `apiGetRaw` preserves the
    // envelope; see its docblock in `lib/api.ts`.
    const all: ShiftReceipt[] = [];

    for (let page = 1; page <= SHIFT_RECEIPTS_MAX_PAGES; page++) {
      const envelope = await apiGetRaw<PaginatedEnvelope<ShiftReceipt>>(
        `/pos/shifts/${shiftId}/receipts`,
        { page, per_page: SHIFT_RECEIPTS_PAGE_SIZE },
      );

      all.push(...envelope.data);

      const lastPage = envelope.meta?.last_page;

      if (lastPage === undefined) {
        // No `meta` at all. A SHORT page is legitimately the only page — some
        // endpoints in this module answer a divergent envelope. A FULL page is the
        // dangerous case: it looks complete and is not. Never assume.
        if (envelope.data.length >= SHIFT_RECEIPTS_PAGE_SIZE) {
          throw new ShiftReceiptsPaginationError(
            `fetchShiftReceipts: shift ${shiftId} returned a full page with no pagination meta`,
          );
        }

        return all;
      }

      if (page >= lastPage) {
        return all;
      }
    }

    throw new ShiftReceiptsPaginationError(
      `fetchShiftReceipts: shift ${shiftId} exceeded ${String(SHIFT_RECEIPTS_MAX_PAGES)} pages`,
    );
  } catch (err) {
    // A broken pagination contract is never a connectivity problem — rethrow it
    // BEFORE the offline fallback can absorb it (P3-B).
    if (err instanceof ShiftReceiptsPaginationError) {
      throw err;
    }

    const { useConnectivityStore } = await import('@/stores/connectivityStore');
    const offline = !useConnectivityStore.getState().isOnline;
    // A 404 means the server has no projection for this shift yet — a normal,
    // transient state under the device-authoritative offline-first model: the
    // device mints the shift id and authors SESSION_OPEN locally, and pos_shifts
    // only lands server-side once the projection syncs. The device's own receipts
    // already live in local SQLite, so read them instead of surfacing an error.
    if (offline || isNotFound(err)) {
      return await fetchLocalShiftReceipts();
    }
    // Genuine online error (auth, 5xx, schema) — bubble up for the UI toast.
    throw err;
  }
}

export async function fetchCashDrawerOps(shiftId: string): Promise<CashDrawerOperation[]> {
  try {
    return await apiGet<CashDrawerOperation[]>(`/pos/cash-drawer/${shiftId}/operations`);
  } catch {
    // Offline fallback: load from local SQLite
    const db = await getDb();
    const { getCashDrawerOpsForShift } = await import('@/lib/db/repositories/cashDrawerRepository');
    const localOps = await getCashDrawerOpsForShift(db, shiftId);
    return localOps.map((op) => ({
      id: op.id,
      type: op.type,
      amount: op.amount,
      reason: op.reason,
      created_at: op.created_at,
      user_name: op.operator_name,
    }));
  }
}

// ── Offline report helpers ──

interface ReceiptLineJson {
  product_id?: string;
  composite_item_id?: string;
  name: string;
  sku?: string;
  quantity: number;
  unit_price: string;
  line_total: string;
  tax_rate?: string;
  tax_amount?: string;
}

interface ProductQuantityPrecisionRow {
  id: string;
  quantity_decimals: number | null;
}

const PRODUCT_PRECISION_LOOKUP_BATCH_SIZE = 500;

function getDb() {
  const { companyId } = useAuthStore.getState();
  return getDatabase(companyId ?? '');
}

function getDecimals(): number {
  const authState = useAuthStore.getState();
  const company = authState.companies.find((c) => c.id === authState.companyId);
  return getCurrencyDecimals(company?.currency ?? 'EUR');
}

/**
 * Build an X Report from local SQLite offline_receipts.
 * Uses the same aggregation approach as zReportService.
 */
async function generateLocalXReport(
  terminalId: string,
  opts: GenerateXReportOpts = {},
): Promise<XReportResponse> {
  const db = await getDb();
  const decimals = getDecimals();
  const generatedAtDevice = new Date();
  const xReportUuid = crypto.randomUUID();
  const authState = useAuthStore.getState();
  const companyId = authState.companyId;

  // Get current shift open time from terminal store
  const { useTerminalStore } = await import('@/stores/terminalStore');
  const shift = useTerminalStore.getState().shift;
  const shiftOpenedAt = shift?.opened_at ?? new Date(0).toISOString();

  // created_at is `datetime('now')` format (space separator, UTC) — the ISO
  // shift timestamp must be normalized or the TEXT comparison excludes every
  // same-day receipt (' ' < 'T').
  const receipts = await queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE terminal_id = $1 AND created_at >= $2
     ORDER BY hash_sequence ASC`,
    [terminalId, toSqliteUtc(shiftOpenedAt)],
  );

  // Build payment method lookup
  const methods = await getAllPaymentMethods(db);
  const methodMap = new Map<string, string>();
  for (const m of methods) {
    methodMap.set(m.id, m.code);
  }

  // Aggregate
  //
  // v3-refund-chain-integration wave-2 review fix (finding 1 / fiscal C-1).
  // This function is a THIRD, structurally separate consumer of
  // `offline_receipts` that §7.3 (`zReportService.ts`) and §7.3a
  // (`endOfDayPreview.ts`) both branched and §17's manifest never
  // enumerated. Before this fix it selected EVERY row for the shift with
  // no `receipt_kind` branch, so a v4 refund row (negative `total`/
  // `subtotal`/`tax_amount`, §7.2) was folded straight into
  // gross_sales/net_sales/tax_amount, counted as a sale in `sales_count`,
  // and reported as `refunds_count: 0` / `refunds_amount: 0` — and those
  // wrong totals were then SIGNED into an immutable `X_REPORT` fiscal
  // event (rule 8: never correctable, only superseded). The branch below
  // mirrors §7.3's exactly: sale-only gross/net/tax/`sales_count`;
  // refunds tracked as their own positive-magnitude figure; VAT and
  // payment-method breakdowns SUBTRACTED, with net derived as
  // gross − vat (finding 4 — `lines[].line_total` is GROSS/TTC).
  let grossSales = '0';
  let netSales = '0';
  let taxAmount = '0';
  let salesCount = 0;
  let refundsCount = 0;
  let refundsAmount = '0';
  const vatByRate = new Map<string, { net: string; vat: string; gross: string }>();
  const paymentByType = new Map<string, { amount: string; count: number }>();

  for (const receipt of receipts) {
    const methodCode = methodMap.get(receipt.payment_method_id) ?? 'UNKNOWN';

    if (receipt.receipt_kind === 'refund') {
      refundsCount += 1;
      refundsAmount = bcadd(refundsAmount, bcabs(receipt.total, decimals), decimals);

      const refundLines = JSON.parse(receipt.lines) as ReceiptLineJson[];
      for (const line of refundLines) {
        const rate = line.tax_rate ?? '0';
        const lineVat = bcabs(line.tax_amount ?? '0', decimals);
        const lineGross = bcabs(line.line_total ?? '0', decimals);
        const lineNet = bcsub(lineGross, lineVat, decimals);
        const existing = vatByRate.get(rate) ?? { net: '0', vat: '0', gross: '0' };
        existing.net = bcsub(existing.net, lineNet, decimals);
        existing.vat = bcsub(existing.vat, lineVat, decimals);
        existing.gross = bcsub(existing.gross, lineGross, decimals);
        vatByRate.set(rate, existing);
      }

      // Payment-method breakdown — SUBTRACTED as a positive magnitude,
      // matching §7.3's own treatment (never `bcadd` of the already-
      // negative `total`, which would read as a signed delta here and a
      // magnitude there).
      const refundPay = paymentByType.get(methodCode) ?? { amount: '0', count: 0 };
      refundPay.amount = bcsub(refundPay.amount, bcabs(receipt.total, decimals), decimals);
      refundPay.count += 1;
      paymentByType.set(methodCode, refundPay);
      continue;
    }

    salesCount += 1;
    grossSales = bcadd(grossSales, receipt.total);
    // C-6 fix (z-headline-net-sales) — the third structurally separate copy of
    // this headline accumulation, feeding the SIGNED X_REPORT. Same derivation
    // as `zReportService.ts` (which carries the full rationale): the SQLite
    // column `subtotal` holds Σ GROSS `line_total`, while the canonical
    // SALE_RECEIPT field of the same name is the NET (`SaleReceiptPayload.ts:121`
    // = `subtotalGross − taxAmount`), so net is derived the only way that
    // reproduces the sealed receipt — `subtotal − tax_amount`, at the currency
    // scale. NOT `total − tax_amount`: `total` is the ROUNDED, POST-discount
    // gross against a PRE-discount VAT, and that mixture would also break
    // `Σ vat_breakdown[].net_amount == net_sales`.
    netSales = bcadd(netSales, bcsub(receipt.subtotal, receipt.tax_amount, decimals), decimals);
    taxAmount = bcadd(taxAmount, receipt.tax_amount);

    // C-2 fix (z-sale-branch-decomposition, ruling: Option B) — the third,
    // structurally separate copy of this loop. Same derivation as the refund
    // branch above and as the other two sites: `line_total` is GROSS/TTC, so
    // net is gross − vat at the currency scale. No `bcabs`: a sale row is
    // positive-signed. `lineGross` is bound to a local (rather than inlining
    // the addition in the accumulator, as this site used to) so a reviewer
    // reading all three fixes side by side sees ONE pattern, not three.
    const lines = JSON.parse(receipt.lines) as ReceiptLineJson[];
    for (const line of lines) {
      const rate = line.tax_rate ?? '0';
      const lineVat = line.tax_amount ?? '0';
      const lineGross = line.line_total ?? '0';
      const lineNet = bcsub(lineGross, lineVat, decimals);

      const existing = vatByRate.get(rate) ?? { net: '0', vat: '0', gross: '0' };
      existing.net = bcadd(existing.net, lineNet, decimals);
      existing.vat = bcadd(existing.vat, lineVat, decimals);
      existing.gross = bcadd(existing.gross, lineGross, decimals);
      vatByRate.set(rate, existing);
    }

    const payExisting = paymentByType.get(methodCode) ?? { amount: '0', count: 0 };
    payExisting.amount = bcadd(payExisting.amount, receipt.total);
    payExisting.count += 1;
    paymentByType.set(methodCode, payExisting);
  }

  const report: XReportResponse = {
    id: xReportUuid,
    terminal_id: terminalId,
    shift_id: shift?.id ?? null,
    generated_by: 'local',
    generated_at: generatedAtDevice.toISOString(),
    sales_count: salesCount,
    gross_sales: bcformat(grossSales, decimals),
    net_sales: bcformat(netSales, decimals),
    tax_amount: bcformat(taxAmount, decimals),
    refunds_count: refundsCount,
    refunds_amount: bcformat(refundsAmount, decimals),
    // Preserve the local X-report/fiscal event tax_rate as a number; trim or
    // round only where the report is rendered.
    vat_breakdown: Array.from(vatByRate.entries())
      .sort(([a], [b]) => parseFloat(a) - parseFloat(b))
      .map(([rate, totals]) => ({
        tax_rate: parseFloat(rate),
        net_amount: bcformat(totals.net, decimals),
        vat_amount: bcformat(totals.vat, decimals),
        gross_amount: bcformat(totals.gross, decimals),
      })),
    payment_methods: Array.from(paymentByType.entries()).map(([type, data]) => ({
      payment_type: type,
      total_amount: bcformat(data.amount, decimals),
      transaction_count: data.count,
    })),
  };

  if (
    companyId !== null &&
    shift !== null &&
    opts.tenantId !== undefined &&
    opts.fiscalShiftId !== undefined &&
    opts.fiscalSessionId !== undefined &&
    opts.operatorId !== undefined &&
    opts.operatorName !== undefined
  ) {
    const engine = await getFiscalEventEngine(companyId, db);
    const firstReceipt = receipts[0] ?? null;
    const lastReceipt = receipts[receipts.length - 1] ?? null;
    await appendXReport(db, engine, {
      tenantId: opts.tenantId,
      companyId,
      terminalId,
      shiftId: opts.fiscalShiftId,
      sessionId: opts.fiscalSessionId,
      businessDate: shift.opened_at.slice(0, 10),
      operatorId: opts.operatorId,
      operatorName: opts.operatorName,
      periodStart: new Date(shift.opened_at).toISOString(),
      periodEnd: generatedAtDevice.toISOString(),
      xReportUuid,
      reportTotals: {
        sales_count: report.sales_count,
        gross_sales: report.gross_sales,
        net_sales: report.net_sales,
        tax_amount: report.tax_amount,
        refunds_count: report.refunds_count,
        refunds_amount: report.refunds_amount,
        voided_count: 0,
      },
      vatBreakdown: report.vat_breakdown.map((row) => ({
        gross_amount: row.gross_amount,
        net_amount: row.net_amount,
        tax_rate: row.tax_rate,
        vat_amount: row.vat_amount,
      })),
      paymentMethodTotals: report.payment_methods.map((row) => ({
        payment_type: row.payment_type,
        total_amount: row.total_amount,
        transaction_count: row.transaction_count,
      })),
      cashDrawerTotals: {},
      operationalEventRange: {
        first_receipt_hash: firstReceipt?.fiscal_hash ?? null,
        first_receipt_sequence: firstReceipt?.hash_sequence ?? null,
        last_receipt_hash: lastReceipt?.fiscal_hash ?? null,
        last_receipt_sequence: lastReceipt?.hash_sequence ?? null,
        receipt_count: receipts.length,
      },
      isTraining: opts.isTraining ?? false,
      generatedAtDevice,
    });
  }

  return report;
}

/**
 * Fetch shift receipts from local SQLite (offline fallback).
 * Maps OfflineReceipt → ShiftReceipt format.
 */
async function fetchLocalShiftReceipts(): Promise<ShiftReceipt[]> {
  const db = await getDb();

  const { useTerminalStore } = await import('@/stores/terminalStore');
  const { shift, terminal } = useTerminalStore.getState();
  if (!terminal || !shift) return [];

  const shiftOpenedAt = shift.opened_at;

  const receipts = await queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE terminal_id = $1 AND created_at >= $2
     ORDER BY created_at DESC`,
    [terminal.id, toSqliteUtc(shiftOpenedAt)],
  );

  // Build payment method lookup for labels
  const methods = await getAllPaymentMethods(db);
  const methodMap = new Map<string, string>();
  for (const m of methods) {
    methodMap.set(m.id, m.code);
  }

  const parsedReceipts = receipts.map((receipt) => ({
    receipt,
    lines: JSON.parse(receipt.lines) as ReceiptLineJson[],
  }));
  const productIds = Array.from(new Set(
    parsedReceipts.flatMap(({ lines }) => lines.flatMap((line) => (
      line.product_id === undefined ? [] : [line.product_id]
    ))),
  ));
  const quantityDecimalsByProductId = new Map<string, number | null>();
  if (productIds.length > 0) {
    const batches = Array.from(
      { length: Math.ceil(productIds.length / PRODUCT_PRECISION_LOOKUP_BATCH_SIZE) },
      (_, index) => productIds.slice(
        index * PRODUCT_PRECISION_LOOKUP_BATCH_SIZE,
        (index + 1) * PRODUCT_PRECISION_LOOKUP_BATCH_SIZE,
      ),
    );
    const productRowsByBatch = await Promise.all(batches.map(async (batch) => {
      const placeholders = batch.map((_, index) => `$${String(index + 1)}`).join(', ');
      return queryAll<ProductQuantityPrecisionRow>(
        db,
        `SELECT id, quantity_decimals FROM products WHERE id IN (${placeholders})`,
        batch,
      );
    }));
    for (const productRows of productRowsByBatch) {
      for (const product of productRows) {
        quantityDecimalsByProductId.set(product.id, product.quantity_decimals);
      }
    }
  }

  return parsedReceipts.map(({ receipt, lines }): ShiftReceipt => {
    const methodCode = methodMap.get(receipt.payment_method_id) ?? 'CASH';

    return {
      id: receipt.id,
      receipt_number: receipt.receipt_number,
      // Wave-2 review fix (finding 16 / fiscal I-4) — v4 refund rows now
      // live in `offline_receipts` too (§7.2), so a hardcoded `'sale'`
      // rendered every refund as a SALE with a negative total and a
      // negative payment on the operator-visible offline shift-receipts
      // screen — the exact screen used when the server projection is not
      // yet available, i.e. right after a refund.
      receipt_type: receipt.receipt_kind === 'refund' ? 'return' : 'sale',
      total: receipt.total,
      subtotal: receipt.subtotal,
      tax_amount: receipt.tax_amount,
      // O-28 — both flags are projected from the row rather than assumed. The
      // query is `SELECT *` and `offline_receipts` carries both columns
      // (`is_training`; `voided` from migration 15), but the mapper used to
      // hardcode `is_voided: false` and drop `is_training` entirely — so offline,
      // a training or voided receipt reached the Today's-Sales tile as a real
      // sale. Same predicate pair the repository already relies on in
      // `getUnsyncedReceiptLineBlobs` ("a sealed-then-voided receipt's sale was
      // reversed" / "training receipts never move real stock").
      is_voided: receipt.voided === 1,
      is_training: receipt.is_training === 1,
      posted_at: receipt.created_at,
      payments: [{
        id: `local-pay-${receipt.id}`,
        payment_type: methodCode,
        amount: receipt.total,
      }],
      lines: lines.map((line, idx) => ({
        id: `local-line-${receipt.id}-${String(idx)}`,
        quantity: line.quantity,
        quantity_decimals: line.product_id === undefined
          ? undefined
          : quantityDecimalsByProductId.get(line.product_id),
        unit_price: line.unit_price,
        line_total: line.line_total,
        product_name: line.name,
      })),
    };
  });
}
