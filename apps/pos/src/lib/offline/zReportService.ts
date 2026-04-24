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
import { bcadd, bcsub, bcformat } from '@/lib/decimal';
import { useAuthStore } from '@/stores/authStore';
import { computeZReportHash } from '@/lib/fiscal/zReportHashService';
import { insertZReport, getZReportByShift } from '@/lib/db/repositories/zReportRepository';
import {
  getTerminalState,
  getZChainState,
  advanceZChain,
  updateGrandTotals,
} from '@/lib/db/repositories/terminalStateRepository';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import type {
  LocalZReport,
  ZReportData,
  ZReportVatBreakdown,
  ZReportPaymentMethod,
  ReceiptSnapshot,
  ReceiptSnapshotTaxLine,
  ReceiptSnapshotLine,
  GrandTotals,
} from '@/lib/offline/types';

// Cumulative fields are persisted at 3 decimals (TND max); see terminalStateRepository.
const CUMULATIVE_SCALE = 3;

// ─── Schema-version-2 hash-input types & builder ─────────────────────────────

export interface CashCountForHash {
  payment_method_id: string;
  currency_code: string;
  expected_amount: string;
  actual_amount: string;
  variance_amount: string;
}

export interface ReportTotalsForHash {
  gross_sales: string;
  net_sales: string;
  tax_amount: string;
}

export interface ShiftForHash {
  opening_cash: string;
  opened_at: string;
  currency_code: string;
}

/**
 * Build a schema_version:2 report_data payload normalized to scale-4 monetary strings.
 * Keys are inserted in a canonical order to guarantee byte-deterministic JSON serialization
 * vs. the PHP server's ZReportHashService::normalizeForHash output.
 *
 * NOTE: This function is intentionally decoupled from generateZReport for PR-1.
 * Full wiring into the hash-computation call site lands in PR-2 (Tasks 18 + 26),
 * once per-tender cash_counts rows are persisted in SQLite (v22 schema migration).
 * Existing generateZReport produces v1 report_data shapes and must not be modified here.
 */
export function buildReportDataForHash(
  shift: ShiftForHash,
  cashCounts: CashCountForHash[],
  totals: ReportTotalsForHash,
): {
  schema_version: number;
  opening_cash: string;
  gross_sales: string;
  net_sales: string;
  tax_amount: string;
  cash_counts: Array<{
    payment_method_id: string;
    currency_code: string;
    expected_amount: string;
    actual_amount: string;
    variance_amount: string;
  }>;
} {
  return {
    schema_version: 2,
    opening_cash: bcformat(shift.opening_cash, 4),
    gross_sales: bcformat(totals.gross_sales, 4),
    net_sales: bcformat(totals.net_sales, 4),
    tax_amount: bcformat(totals.tax_amount, 4),
    cash_counts: cashCounts.map((c) => ({
      payment_method_id: c.payment_method_id,
      currency_code: c.currency_code,
      expected_amount: bcformat(c.expected_amount, 4),
      actual_amount: bcformat(c.actual_amount, 4),
      variance_amount: bcformat(c.variance_amount, 4),
    })),
  };
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
 */
export async function generateZReport(
  db: Database,
  terminalId: string,
  shiftId: string,
  shiftOpenedAt: string,
  openingCash: string,
): Promise<LocalZReport> {
  const authState = useAuthStore.getState();
  const company = authState.companies.find((c) => c.id === authState.companyId);
  const decimals = getCurrencyDecimals(company?.currency ?? 'EUR');

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
  const receipts = await queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE terminal_id = $1 AND created_at >= $2
     ORDER BY hash_sequence ASC`,
    [terminalId, shiftOpenedAt]
  );

  if (receipts.length === 0) {
    throw new Error('Cannot generate Z-report for a shift with no receipts.');
  }

  // Build payment method lookup for names
  const paymentMethodMap = await buildPaymentMethodMap(db);

  // 4. Compute report data
  const reportData = aggregateReportData(receipts, paymentMethodMap, decimals);

  // 5. Compute expected cash BEFORE hashing — must be embedded in report_data
  // to match the server's ReportGenerationService which adds opening_cash/expected_cash
  // to report_data before hash computation.
  const cashPayments = reportData.payment_methods.find((p) => p.payment_type === 'CASH');
  const cashSales = cashPayments ? cashPayments.total_amount : '0';
  const expectedCash = bcadd(openingCash, cashSales, decimals);

  reportData.opening_cash = new Big(openingCash).toFixed(decimals);
  reportData.expected_cash = new Big(expectedCash).toFixed(decimals);
  reportData.variance = null;

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

  // 10. Build the Z-report record
  const zReport: LocalZReport = {
    id: crypto.randomUUID(),
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
  };

  // 11. Persist atomically: insert Z-report + advance Z-chain + update grand totals
  await db.execute('BEGIN TRANSACTION');
  try {
    await insertZReport(db, zReport);
    await advanceZChain(db, terminalId, fiscalHash, newHashSequence, newZNumber);
    await updateGrandTotals(db, terminalId, grossSalesStr, taxStr, refundsStr, salesCount);
    await db.execute('COMMIT');
  } catch (error) {
    await db.execute('ROLLBACK');
    throw error;
  }

  return zReport;
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

function aggregateReportData(
  receipts: OfflineReceipt[],
  paymentMethodMap: Map<string, string>,
  decimals: number,
): ZReportData {
  let grossSales = '0';
  let netSales = '0';
  let taxAmount = '0';
  let salesCount = 0;
  // Void/refund counters are always 0 offline — voiding and refunding
  // are server-side operations tracked after sync.
  const refundsCount = 0;
  let refundsAmount = '0';
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
