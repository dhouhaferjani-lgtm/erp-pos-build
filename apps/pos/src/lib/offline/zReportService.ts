/**
 * Offline Z-report generation service.
 *
 * Generates Z-reports locally in SQLite with fiscal hash chain,
 * receipt snapshots, and grand total perpetual counters.
 * Mirrors the server's ReportGenerationService for offline-first operation.
 */

import type Database from '@tauri-apps/plugin-sql';
import Big from 'big.js';
import { queryAll } from '@/lib/db';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bcsub, bcformat, bccomp } from '@/lib/decimal';
import { useAuthStore } from '@/stores/authStore';
import { computeZReportHash } from '@/lib/fiscal/zReportHashService';
import { insertZReport, getZReportByShift } from '@/lib/db/repositories/zReportRepository';
import {
  getTerminalState,
  getZChainState,
  advanceZChain,
  updateGrandTotals,
} from '@/lib/db/repositories/terminalStateRepository';
import { insertZReportCounts } from '@/lib/db/repositories/zReportCountRepository';
import type { ZReportCountRow } from '@/lib/db/repositories/zReportCountRepository';
import { getRefundRecordsForShift } from '@/lib/db/repositories/localRefundRecordRepository';
import type { LocalRefundRecord } from '@/lib/db/repositories/localRefundRecordRepository';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import type {
  LocalZReport,
  ZReportData,
  ZReportVatBreakdown,
  ZReportPaymentMethod,
  ZReportCountEntry,
  ReceiptSnapshot,
  ReceiptSnapshotTaxLine,
  ReceiptSnapshotLine,
  GrandTotals,
} from '@/lib/offline/types';
import {
  computeCashCountSeverity,
  type CashCountThresholds,
} from '@/lib/offline/cashCountValidation';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import {
  appendZSessionCloseAndZReport,
  type AuthorZSessionCloseInput,
} from '@/lib/fiscal/zSessionAuthoring';

// Cumulative fields are persisted at 3 decimals (TND max); see terminalStateRepository.
const CUMULATIVE_SCALE = 3;

// ─── Cash count input / opts types ───────────────────────────────────────────

export interface CashCountInputForGeneration {
  payment_method_id: string;
  currency_code: string;
  actual_amount: string;
}

export interface GenerateZReportOpts {
  cashCounts?: CashCountInputForGeneration[];
  varianceReason?: string | null;
  managerUserId?: string | null;
  blindCountUsed?: boolean;
  tenantId?: string;
  fiscalShiftId?: string;
  fiscalSessionId?: string;
  terminalLabel?: string;
  operatorId?: string;
  operatorName?: string;
  isTraining?: boolean;
  requireFiscalEvents?: boolean;
  /** Fraud-settings thresholds. When provided, variance_severity is computed
   *  from the aggregated per-tender variances and stamped into shift_fields. */
  fraudSettings?: CashCountThresholds | null;
}

/**
 * Format a Date to match Carbon's toIso8601String() output.
 * JS: "2026-03-13T14:30:00.000Z"  →  Carbon: "2026-03-13T14:30:00+00:00"
 * The fiscal hash MUST use the same format as the server.
 */
function toIso8601(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, '+00:00');
}

interface ReceiptLineJson {
  name?: string;
  quantity?: number;
  unit_price?: string;
  line_total?: string;
  tax_rate?: string;
  tax_amount?: string;
  discount_amount?: string | null;
}

/**
 * Generate a Z-report for the current shift, stored locally in SQLite.
 *
 * All operations are wrapped in a single transaction to prevent inconsistent state.
 *
 * @param db - SQLite database connection
 * @param terminalId - Terminal UUID
 * @param shiftId - Current shift UUID
 * @param shiftOpenedAt - ISO 8601 timestamp of when the shift was opened
 * @param openingCash - Opening cash amount for the shift
 * @param opts - Optional cash count inputs and shift metadata (closes G2/G3)
 */
export async function generateZReport(
  db: Database,
  terminalId: string,
  shiftId: string,
  shiftOpenedAt: string,
  openingCash: string,
  opts: GenerateZReportOpts = {},
): Promise<LocalZReport> {
  const authState = useAuthStore.getState();
  const company = authState.companies.find((c) => c.id === authState.companyId);
  const decimals = getCurrencyDecimals(company?.currency ?? 'EUR');
  const companyId = authState.companyId;
  assertRequiredFiscalCloseContext(companyId, opts);

  // 1. Guard: check no Z-report already exists for this shift
  const existing = await getZReportByShift(db, shiftId);
  if (existing) {
    throw new Error(`Z-report already exists for shift ${shiftId}`);
  }

  // 2. Read terminal state
  const terminalState = await getTerminalState(db, terminalId);
  if (!terminalState) {
    throw new Error('Terminal hash chain not initialized. Cannot generate Z-report.');
  }

  const zChainState = await getZChainState(db, terminalId);
  if (!zChainState) {
    throw new Error('Z-chain state not found in terminal_state.');
  }

  // 3. Aggregate receipts for this shift
  // Receipts are queried by terminal_id + shift open time because offline_receipts
  // does not have a shift_id column. This is acceptable because shifts are sequential
  // per terminal — only one shift can be open at a time.
  // T2.7 — filter out is_training=1 rows. Training receipts must not enter local
  // Z-report totals or the Z-chain hash; this mirrors the server-side
  // `Terminal::scopeProduction()` exclusion that NF525 / ReportGenerationService
  // already apply on the canonical reporting path.
  const receipts = await queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE terminal_id = $1 AND created_at >= $2 AND is_training = 0
     ORDER BY hash_sequence ASC`,
    [terminalId, shiftOpenedAt]
  );

  if (receipts.length === 0) {
    throw new Error('Cannot generate Z-report for a shift with no receipts.');
  }

  // Build payment method lookup for names
  const paymentMethodMap = await buildPaymentMethodMap(db);

  // 3b. Phase 4 (fiscal audit B2) — refunds settled AT THIS terminal during
  // this shift, mirrored at settle time into local_refund_records (see
  // localRefundRecordRepository). Refunds processed at OTHER terminals do not
  // affect this device's drawer or its Z — the server Z/report side owns
  // global reconciliation. A device crash between the server settle and the
  // local mirror write undercounts here (server remains source of truth).
  const refundRecords = await getRefundRecordsForShift(db, shiftId);

  // 4. Compute report data
  const reportData = aggregateReportData(receipts, paymentMethodMap, decimals, refundRecords);

  // 5. Compute expected cash BEFORE hashing — must be embedded in report_data
  // to match the server's ReportGenerationService which adds opening_cash/expected_cash
  // to report_data before hash computation.
  // Cash-destination refunds physically left this drawer and reduce the
  // expected cash; voucher / original-payment refunds move no till cash
  // (cash_impact is '0' for those rows by construction).
  const cashPayments = reportData.payment_methods.find((p) => p.payment_type === 'CASH');
  const cashSales = cashPayments ? cashPayments.total_amount : '0';
  let cashRefundImpact = '0';
  for (const record of refundRecords) {
    cashRefundImpact = bcadd(cashRefundImpact, record.cash_impact);
  }
  const expectedCash = bcsub(bcadd(openingCash, cashSales, decimals), cashRefundImpact, decimals);

  reportData.opening_cash = new Big(openingCash).toFixed(decimals);
  reportData.expected_cash = new Big(expectedCash).toFixed(decimals);
  reportData.variance = null;

  // 5b. Compute cash count rows when opts.cashCounts is provided.
  //     Adds schema_version=2, cash_counts[], and tolerance_summary to report_data.
  const companyCurrency = company?.currency ?? 'EUR';
  let zReportCountRows: ZReportCountRow[] | null = null;
  let cashCountEntries: ZReportCountEntry[] | null = null;

  if (opts.cashCounts && opts.cashCounts.length > 0) {
    // Build a map of payment_method_id → expected_amount for the entries provided.
    // For CASH, expected = opening_cash + Σ(cash_sales from receipts).
    // For other physical methods, expected = Σ(sales for that method from receipts).
    const pmIdToSales = new Map<string, string>();
    for (const pm of reportData.payment_methods) {
      // Look up the payment_method_id from the code
      const pmId = [...paymentMethodMap.entries()].find(([, code]) => code === pm.payment_type)?.[0];
      if (pmId) {
        pmIdToSales.set(pmId, pm.total_amount);
      }
    }

    const countEntries: ZReportCountEntry[] = [];
    const countRows: ZReportCountRow[] = [];

    for (const input of opts.cashCounts) {
      // Compute expected_amount for this payment method
      const methodSales = pmIdToSales.get(input.payment_method_id) ?? '0';
      const methodCode = paymentMethodMap.get(input.payment_method_id) ?? '';

      // For CASH: expected = opening_float + cash_receipts_amount
      // For others: expected = sales_amount for that method
      const expectedAmount =
        methodCode === 'CASH'
          ? bcformat(expectedCash, decimals)
          : bcformat(methodSales, decimals);

      // variance = actual - expected (positive = over, negative = under)
      const variance = bcsub(input.actual_amount, expectedAmount, decimals);
      const varianceCmp = bccomp(variance, '0');
      const varianceDirection: 'over' | 'under' | 'balanced' =
        varianceCmp > 0 ? 'over' : varianceCmp < 0 ? 'under' : 'balanced';

      const entry: ZReportCountEntry = {
        payment_method_id: input.payment_method_id,
        currency_code: input.currency_code,
        expected_amount: expectedAmount,
        actual_amount: bcformat(input.actual_amount, decimals),
        variance_amount: variance,
        variance_direction: varianceDirection,
        transaction_count: receipts.filter(
          (r) => r.payment_method_id === input.payment_method_id,
        ).length,
      };

      countEntries.push(entry);
      countRows.push({
        id: crypto.randomUUID(),
        z_report_id: '', // filled in after we know the Z-report id below
        payment_method_id: entry.payment_method_id,
        currency_code: entry.currency_code,
        expected_amount: entry.expected_amount,
        actual_amount: entry.actual_amount,
        variance_amount: entry.variance_amount,
        variance_direction: entry.variance_direction,
        transaction_count: entry.transaction_count,
      });
    }

    cashCountEntries = countEntries;
    zReportCountRows = countRows;

    // Stamp schema_version = 2 and cash_counts into report_data
    reportData.schema_version = 2;
    reportData.cash_counts = countEntries;
    // Tolerance zero-shape (3dp amount, integer count) — byte-matches server ZReportHashService
    // TODO(payment-tolerance-v3): when offline A1 short-pay ships, this
    // tolerance_summary must aggregate `tolerance_writeoff` over the
    // shift's local receipts (the data is already in
    // endOfDayPreview.ts:184-192). Hash chain stability across
    // offline-generated → server-synced Zs depends on this.
    reportData.tolerance_summary = {
      totalAmount: '0.000',
      currencyCode: companyCurrency,
      writeoffCount: 0,
    };
  }

  // 6. Build receipt snapshots for fiscal export
  const receiptSnapshots = buildReceiptSnapshots(receipts, paymentMethodMap);

  // 7. Increment Z-number
  const newZNumber = zChainState.z_number + 1;
  const formattedZNumber = `Z${String(newZNumber).padStart(4, '0')}`;
  const newHashSequence = zChainState.z_hash_sequence + 1;
  const generatedAt = toIso8601(new Date());

  // 8. Compute fiscal hash (must match server format exactly)
  const fiscalHash = await computeZReportHash({
    previousHash: zChainState.z_last_hash,
    zNumber: newZNumber,
    terminalId,
    generatedAt,
    reportData,
  });

  // 9. Compute updated grand totals using Big.js (no IEEE 754 coercion).
  const grossSalesStr = reportData.gross_sales;
  const taxStr = reportData.tax_amount;
  const refundsStr = reportData.refunds_amount;
  const salesCount = reportData.sales_count;

  const netDeltaStr = bcsub(grossSalesStr, refundsStr, CUMULATIVE_SCALE);
  const grandTotals: GrandTotals = {
    cumulative_sales: bcadd(zChainState.cumulative_sales, grossSalesStr, CUMULATIVE_SCALE),
    cumulative_tax: bcadd(zChainState.cumulative_tax, taxStr, CUMULATIVE_SCALE),
    cumulative_refunds: bcadd(zChainState.cumulative_refunds, refundsStr, CUMULATIVE_SCALE),
    perpetual_grand_total: bcadd(zChainState.perpetual_grand_total, netDeltaStr, CUMULATIVE_SCALE),
    receipt_count_lifetime: zChainState.receipt_count_lifetime + salesCount,
  };

  // 10. Build shift_fields when cash count opts are provided.
  //     Compute variance_severity from the aggregated per-tender variances when
  //     fraud settings thresholds are available; otherwise leave null.
  const zReportId = crypto.randomUUID();

  let varianceSeverity: string | null = null;
  if (cashCountEntries !== null && opts.fraudSettings != null) {
    const severityResult = computeCashCountSeverity(
      cashCountEntries,
      opts.fraudSettings,
      decimals,
    );
    varianceSeverity = severityResult.severity;
  }

  const shiftFields =
    cashCountEntries !== null
      ? {
          blind_count_used: opts.blindCountUsed ?? false,
          variance_severity: varianceSeverity,
          variance_reason: opts.varianceReason ?? null,
          manager_override_by: opts.managerUserId ?? null,
        }
      : null;

  // 11. Build the Z-report record
  const zReport: LocalZReport = {
    id: zReportId,
    terminal_id: terminalId,
    shift_id: shiftId,
    z_number: newZNumber,
    formatted_z_number: formattedZNumber,
    generated_at: generatedAt,
    fiscal_hash: fiscalHash,
    previous_hash: zChainState.z_last_hash,
    hash_sequence: newHashSequence,
    report_data: reportData,
    opening_cash: reportData.opening_cash ?? new Big(openingCash).toFixed(decimals),
    expected_cash: reportData.expected_cash ?? new Big(expectedCash).toFixed(decimals),
    receipt_snapshots: receiptSnapshots,
    grand_totals: grandTotals,
    synced: false,
    synced_at: null,
    cash_counts: cashCountEntries ?? undefined,
    shift_fields: shiftFields,
    manager_user_id: opts.managerUserId ?? null,
    tolerance_summary: cashCountEntries !== null
      ? { totalAmount: '0.000', currencyCode: companyCurrency, writeoffCount: 0 }
      : undefined,
    currency_code: companyCurrency,
  };

  // Fix z_report_id on count rows now that we have the report id
  if (zReportCountRows !== null) {
    for (const row of zReportCountRows) {
      row.z_report_id = zReportId;
    }
  }

  // 12. Persist atomically: insert Z-report + (optionally) cash count rows + advance Z-chain + update grand totals
  await db.execute('BEGIN TRANSACTION');
  try {
    await insertZReport(db, zReport);
    if (zReportCountRows !== null && zReportCountRows.length > 0) {
      await insertZReportCounts(db, zReportCountRows);
    }
    await advanceZChain(db, terminalId, fiscalHash, newHashSequence, newZNumber);
    await updateGrandTotals(db, terminalId, grossSalesStr, taxStr, refundsStr, salesCount);

    const closeInput = buildFiscalCloseInput({
      companyId,
      companyName: company?.name ?? null,
      currencyCode: companyCurrency,
      decimals,
      fiscalHash,
      generatedAt,
      grandTotals,
      newHashSequence,
      opts,
      receiptSnapshots,
      reportData,
      shiftId,
      shiftOpenedAt,
      terminalId,
      zChainState,
      zReport,
      zReportCountRows,
    });
    if (closeInput !== null && companyId !== null) {
      const engine = await getFiscalEventEngine(companyId, db);
      await appendZSessionCloseAndZReport(db, engine, closeInput);
    }

    await db.execute('COMMIT');
  } catch (error) {
    await db.execute('ROLLBACK');
    throw error;
  }

  return zReport;
}

function assertRequiredFiscalCloseContext(companyId: string | null, opts: GenerateZReportOpts): void {
  if (opts.requireFiscalEvents !== true) {
    return;
  }

  const missing: string[] = [];
  if (companyId === null) missing.push('companyId');
  if (opts.tenantId === undefined) missing.push('tenantId');
  if (opts.fiscalShiftId === undefined) missing.push('fiscalShiftId');
  if (opts.fiscalSessionId === undefined) missing.push('fiscalSessionId');
  if (opts.terminalLabel === undefined) missing.push('terminalLabel');
  if (opts.operatorId === undefined) missing.push('operatorId');
  if (opts.operatorName === undefined) missing.push('operatorName');

  if (missing.length > 0) {
    throw new Error(
      `Cannot generate cutover Z-report without fiscal event close context: missing ${missing.join(', ')}.`,
    );
  }
}

interface FiscalCloseInputContext {
  companyId: string | null;
  companyName: string | null;
  currencyCode: string;
  decimals: number;
  fiscalHash: string;
  generatedAt: string;
  grandTotals: GrandTotals;
  newHashSequence: number;
  opts: GenerateZReportOpts;
  receiptSnapshots: ReceiptSnapshot[];
  reportData: ZReportData;
  shiftId: string;
  shiftOpenedAt: string;
  terminalId: string;
  zChainState: NonNullable<Awaited<ReturnType<typeof getZChainState>>>;
  zReport: LocalZReport;
  zReportCountRows: ZReportCountRow[] | null;
}

function buildFiscalCloseInput(ctx: FiscalCloseInputContext): AuthorZSessionCloseInput | null {
  const {
    companyId,
    companyName,
    currencyCode,
    decimals,
    fiscalHash,
    generatedAt,
    grandTotals,
    newHashSequence,
    opts,
    receiptSnapshots,
    reportData,
    shiftId,
    shiftOpenedAt,
    terminalId,
    zChainState,
    zReport,
    zReportCountRows,
  } = ctx;

  if (
    companyId === null ||
    opts.tenantId === undefined ||
    opts.fiscalShiftId === undefined ||
    opts.fiscalSessionId === undefined ||
    opts.terminalLabel === undefined ||
    opts.operatorId === undefined ||
    opts.operatorName === undefined
  ) {
    return null;
  }

  const cashCountLines = (zReportCountRows ?? []).map((row) => ({
    actual_amount: row.actual_amount,
    currency_code: row.currency_code,
    expected_amount: row.expected_amount,
    payment_method_id: row.payment_method_id,
    transaction_count: row.transaction_count,
    variance_amount: row.variance_amount,
    variance_direction: row.variance_direction,
  }));
  const firstReceipt = receiptSnapshots[0] ?? null;
  const lastReceipt = receiptSnapshots[receiptSnapshots.length - 1] ?? null;
  const cashCountSummary = summarizeCashCountLines(cashCountLines, decimals);

  return {
    tenantId: opts.tenantId,
    companyId,
    terminalId,
    terminalLabel: opts.terminalLabel,
    shiftId: opts.fiscalShiftId,
    sessionId: opts.fiscalSessionId,
    businessDate: shiftOpenedAt.slice(0, 10),
    operatorId: opts.operatorId,
    operatorName: opts.operatorName,
    currencyCode,
    currencyScale: fiscalCurrencyScale(decimals),
    periodStart: new Date(shiftOpenedAt).toISOString(),
    periodEnd: new Date(generatedAt).toISOString(),
    zReportUuid: zReport.id,
    zNumber: zReport.z_number,
    formattedZNumber: zReport.formatted_z_number,
    expectedCash: zReport.expected_cash,
    countedCash: cashCountSummary.countedCash,
    varianceAmount: cashCountSummary.varianceAmount,
    varianceDirection: cashCountSummary.varianceDirection,
    varianceSeverity: zReport.shift_fields?.variance_severity ?? null,
    varianceReason: zReport.shift_fields?.variance_reason ?? null,
    reportTotals: {
      sales_count: reportData.sales_count,
      gross_sales: reportData.gross_sales,
      net_sales: reportData.net_sales,
      tax_amount: reportData.tax_amount,
      refunds_count: reportData.refunds_count,
      refunds_amount: reportData.refunds_amount,
      voided_count: reportData.voided_count,
    },
    vatBreakdown: reportData.vat_breakdown.map((row) => ({
      gross_amount: row.gross_amount,
      net_amount: row.net_amount,
      tax_rate: row.tax_rate,
      vat_amount: row.vat_amount,
    })),
    paymentMethodTotals: reportData.payment_methods.map((row) => ({
      payment_type: row.payment_type,
      total_amount: row.total_amount,
      transaction_count: row.transaction_count,
    })),
    cashCountLines,
    cashDrawerTotals: {
      expected_cash: zReport.expected_cash,
      opening_cash: zReport.opening_cash,
    },
    grandTotalsBefore: {
      cumulative_refunds: zChainState.cumulative_refunds,
      cumulative_sales: zChainState.cumulative_sales,
      cumulative_tax: zChainState.cumulative_tax,
      perpetual_grand_total: zChainState.perpetual_grand_total,
      receipt_count_lifetime: zChainState.receipt_count_lifetime,
    },
    grandTotalsAfter: {
      cumulative_refunds: grandTotals.cumulative_refunds,
      cumulative_sales: grandTotals.cumulative_sales,
      cumulative_tax: grandTotals.cumulative_tax,
      perpetual_grand_total: grandTotals.perpetual_grand_total,
      receipt_count_lifetime: grandTotals.receipt_count_lifetime,
    },
    toleranceSummary: zReport.tolerance_summary === undefined || zReport.tolerance_summary === null
      ? null
      : {
          currencyCode: zReport.tolerance_summary.currencyCode,
          totalAmount: zReport.tolerance_summary.totalAmount,
          writeoffCount: zReport.tolerance_summary.writeoffCount,
        },
    legacyReportReference: {
      fiscal_hash: fiscalHash,
      hash_sequence: newHashSequence,
      local_z_report_id: zReport.id,
      shift_id: shiftId,
    },
    companySnapshot: {
      company_id: companyId,
      name: companyName,
    },
    seller: null,
    operationalEventRange: {
      first_receipt_hash: firstReceipt?.fiscal_hash ?? null,
      first_receipt_sequence: firstReceipt?.hash_sequence ?? null,
      last_receipt_hash: lastReceipt?.fiscal_hash ?? null,
      last_receipt_sequence: lastReceipt?.hash_sequence ?? null,
      receipt_count: receiptSnapshots.length,
    },
    isTraining: opts.isTraining ?? false,
    closedAtDevice: new Date(generatedAt),
  };
}

function fiscalCurrencyScale(decimals: number): 0 | 2 | 3 {
  if (decimals === 0 || decimals === 2 || decimals === 3) return decimals;
  throw new Error(`Unsupported fiscal currency scale ${decimals}`);
}

function summarizeCashCountLines(
  cashCountLines: ReadonlyArray<{
    actual_amount: string;
    expected_amount: string;
  }>,
  decimals: number,
): {
  countedCash: string | null;
  varianceAmount: string | null;
  varianceDirection: 'over' | 'under' | 'balanced' | null;
} {
  if (cashCountLines.length === 0) {
    return {
      countedCash: null,
      varianceAmount: null,
      varianceDirection: null,
    };
  }

  const totals = cashCountLines.reduce(
    (acc, line) => ({
      actual: bcadd(acc.actual, line.actual_amount, decimals),
      expected: bcadd(acc.expected, line.expected_amount, decimals),
    }),
    { actual: bcformat('0', decimals), expected: bcformat('0', decimals) },
  );
  const varianceAmount = bcsub(totals.actual, totals.expected, decimals);
  const varianceCompare = bccomp(varianceAmount, '0');

  return {
    countedCash: totals.actual,
    varianceAmount,
    varianceDirection: varianceCompare > 0
      ? 'over'
      : varianceCompare < 0
        ? 'under'
        : 'balanced',
  };
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

async function buildPaymentMethodMap(db: Database): Promise<Map<string, string>> {
  const methods = await queryAll<{ id: string; code: string }>(
    db,
    'SELECT id, code FROM payment_methods'
  );
  const map = new Map<string, string>();
  for (const m of methods) {
    map.set(m.id, m.code);
  }
  return map;
}

/** Positive magnitude of a signed decimal string ('-23.80' → '23.80'). */
function absAmount(value: string): string {
  return value.startsWith('-') ? value.slice(1) : value;
}

function aggregateReportData(
  receipts: OfflineReceipt[],
  paymentMethodMap: Map<string, string>,
  decimals: number,
  refundRecords: LocalRefundRecord[],
): ZReportData {
  let grossSales = '0';
  let netSales = '0';
  let taxAmount = '0';
  let salesCount = 0;
  // Phase 4 (fiscal audit B2) — refund counters fold in the record-at-settle
  // mirror of refunds settled at THIS terminal during this shift.
  // refunds_amount is a POSITIVE magnitude: the server pins these semantics
  // (ReportGenerationService::calculateShiftTotals asserts a positive
  // refunds_amount in ZReportV3AggregationTest, and GrandtotalService
  // advances perpetual_grand_total by gross_sales − refunds_amount). The
  // signed totals stay on the record's `total`; only the magnitude enters
  // the report. Store-voucher refunds count here as refunds — voucher
  // ISSUANCE/redemption counters are a separate server-side v3 ledger
  // aggregation and are not part of the device report_data (no double-count).
  // Void counters remain 0 offline — voiding is a server-side operation
  // tracked after sync.
  const refundsCount = refundRecords.length;
  let refundsAmount = '0';
  for (const record of refundRecords) {
    refundsAmount = bcadd(refundsAmount, absAmount(record.total));
  }
  const voidedCount = 0;

  const vatByRate = new Map<string, { net: string; vat: string; gross: string }>();
  const paymentByType = new Map<string, { amount: string; count: number }>();

  for (const receipt of receipts) {
    // Skip voided receipts (status === 'voided' or similar) — for now we count all
    // since offline receipts don't have a voided flag. Voided receipts are tracked server-side.
    salesCount++;
    grossSales = bcadd(grossSales, receipt.total);
    netSales = bcadd(netSales, receipt.subtotal);
    taxAmount = bcadd(taxAmount, receipt.tax_amount);

    // VAT breakdown from receipt lines
    const lines = JSON.parse(receipt.lines) as ReceiptLineJson[];
    for (const line of lines) {
      const rate = line.tax_rate ?? '0';
      const lineVat = line.tax_amount ?? '0';
      const lineNet = line.line_total ?? '0';
      const lineGross = bcadd(lineNet, lineVat);

      const existing = vatByRate.get(rate) ?? { net: '0', vat: '0', gross: '0' };
      existing.net = bcadd(existing.net, lineNet);
      existing.vat = bcadd(existing.vat, lineVat);
      existing.gross = bcadd(existing.gross, lineGross);
      vatByRate.set(rate, existing);
    }

    // Payment method breakdown
    const methodCode = paymentMethodMap.get(receipt.payment_method_id) ?? 'UNKNOWN';
    const existing = paymentByType.get(methodCode) ?? { amount: '0', count: 0 };
    existing.amount = bcadd(existing.amount, receipt.total);
    existing.count += 1;
    paymentByType.set(methodCode, existing);
  }

  const vatBreakdown: ZReportVatBreakdown[] = [];
  for (const [rate, totals] of vatByRate) {
    vatBreakdown.push({
      tax_rate: parseFloat(rate),
      net_amount: bcformat(totals.net, decimals),
      vat_amount: bcformat(totals.vat, decimals),
      gross_amount: bcformat(totals.gross, decimals),
    });
  }
  vatBreakdown.sort((a, b) => a.tax_rate - b.tax_rate);

  const paymentMethods: ZReportPaymentMethod[] = [];
  for (const [type, data] of paymentByType) {
    paymentMethods.push({
      payment_type: type,
      total_amount: bcformat(data.amount, decimals),
      transaction_count: data.count,
    });
  }

  return {
    sales_count: salesCount,
    gross_sales: bcformat(grossSales, decimals),
    net_sales: bcformat(netSales, decimals),
    tax_amount: bcformat(taxAmount, decimals),
    refunds_count: refundsCount,
    refunds_amount: bcformat(refundsAmount, decimals),
    voided_count: voidedCount,
    vat_breakdown: vatBreakdown,
    payment_methods: paymentMethods,
  };
}

function buildReceiptSnapshots(
  receipts: OfflineReceipt[],
  paymentMethodMap: Map<string, string>,
): ReceiptSnapshot[] {
  return receipts.map((receipt) => {
    const lines = JSON.parse(receipt.lines) as ReceiptLineJson[];
    const methodCode = paymentMethodMap.get(receipt.payment_method_id) ?? 'UNKNOWN';

    // Build tax lines grouped by rate.
    // Map key is a numeric tax rate (percentage, not monetary) for sort stability.
    const taxByRate = new Map<number, { rate: number; net: string; vat: string; gross: string }>();
    for (const line of lines) {
      // tax_rate is a percentage — parseFloat is intentional and correct here
      const rate = parseFloat(line.tax_rate ?? '0');
      const lineNet = line.line_total ?? '0';
      const lineVat = line.tax_amount ?? '0';
      const lineGross = bcadd(lineNet, lineVat);

      const existing = taxByRate.get(rate) ?? { rate, net: '0', vat: '0', gross: '0' };
      existing.net = bcadd(existing.net, lineNet);
      existing.vat = bcadd(existing.vat, lineVat);
      existing.gross = bcadd(existing.gross, lineGross);
      taxByRate.set(rate, existing);
    }

    const taxLines: ReceiptSnapshotTaxLine[] = Array.from(taxByRate.values()).map((t) => ({
      rate: t.rate,
      net_amount: t.net,
      tax_amount: t.vat,
      gross_amount: t.gross,
    }));

    const snapshotLines: ReceiptSnapshotLine[] = lines.map((line) => ({
      description: line.name ?? '',
      quantity: line.quantity ?? 1,
      unit_price: line.unit_price ?? '0',
      total: line.line_total ?? '0',
      // tax_rate is a percentage — parseFloat is intentional and correct here
      tax_rate: parseFloat(line.tax_rate ?? '0'),
      discount_amount: line.discount_amount ?? '0',
    }));

    return {
      receipt_number: receipt.receipt_number,
      fiscal_hash: receipt.fiscal_hash,
      hash_sequence: receipt.hash_sequence,
      created_at: receipt.created_at,

      total_ht: receipt.subtotal,
      total_ttc: receipt.total,
      total_tax: receipt.tax_amount,

      tax_lines: taxLines,

      payment_type: methodCode,
      payment_amount: receipt.total,
      change_given: receipt.change_due ?? '0',

      lines: snapshotLines,

      voided: false,
      void_reason: null,
      refund_of: null,

      operator_id: receipt.operator_id,
      operator_name: receipt.operator_name,
    };
  });
}
