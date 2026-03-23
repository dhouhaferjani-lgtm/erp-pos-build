/**
 * Offline Z-report generation service.
 *
 * Generates Z-reports locally in SQLite with fiscal hash chain,
 * receipt snapshots, and grand total perpetual counters.
 * Mirrors the server's ReportGenerationService for offline-first operation.
 */

import type Database from '@tauri-apps/plugin-sql';
import { queryAll } from '@/lib/db';
import { getCurrencyDecimals } from '@/lib/currency';
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
  openingCash: number,
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
  const cashSales = cashPayments ? parseFloat(cashPayments.total_amount) : 0;
  const expectedCash = openingCash + cashSales;

  reportData.opening_cash = openingCash.toFixed(decimals);
  reportData.expected_cash = expectedCash.toFixed(decimals);
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

  // 9. Compute updated grand totals
  const grossSalesNum = parseFloat(reportData.gross_sales);
  const taxNum = parseFloat(reportData.tax_amount);
  const refundsNum = parseFloat(reportData.refunds_amount);
  const salesCount = reportData.sales_count;

  const grandTotals: GrandTotals = {
    cumulative_sales: zChainState.cumulative_sales + grossSalesNum,
    cumulative_tax: zChainState.cumulative_tax + taxNum,
    cumulative_refunds: zChainState.cumulative_refunds + refundsNum,
    perpetual_grand_total: zChainState.perpetual_grand_total + (grossSalesNum - refundsNum),
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
    opening_cash: openingCash,
    expected_cash: expectedCash,
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
    await updateGrandTotals(db, terminalId, grossSalesNum, taxNum, refundsNum, salesCount);
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
  let grossSales = 0;
  let netSales = 0;
  let taxAmount = 0;
  let salesCount = 0;
  // Void/refund counters are always 0 offline — voiding and refunding
  // are server-side operations tracked after sync.
  let refundsCount = 0;
  let refundsAmount = 0;
  let voidedCount = 0;

  const vatByRate = new Map<string, { net: number; vat: number; gross: number }>();
  const paymentByType = new Map<string, { amount: number; count: number }>();

  for (const receipt of receipts) {
    const total = parseFloat(receipt.total);
    const subtotal = parseFloat(receipt.subtotal);
    const tax = parseFloat(receipt.tax_amount);

    // Skip voided receipts (status === 'voided' or similar) — for now we count all
    // since offline receipts don't have a voided flag. Voided receipts are tracked server-side.
    salesCount++;
    grossSales += total;
    netSales += subtotal;
    taxAmount += tax;

    // VAT breakdown from receipt lines
    const lines = JSON.parse(receipt.lines) as ReceiptLineJson[];
    for (const line of lines) {
      const rate = line.tax_rate ?? '0';
      const lineVat = parseFloat(line.tax_amount ?? '0');
      const lineNet = parseFloat(line.line_total ?? '0');
      const lineGross = lineNet + lineVat;

      const existing = vatByRate.get(rate) ?? { net: 0, vat: 0, gross: 0 };
      existing.net += lineNet;
      existing.vat += lineVat;
      existing.gross += lineGross;
      vatByRate.set(rate, existing);
    }

    // Payment method breakdown
    const methodCode = paymentMethodMap.get(receipt.payment_method_id) ?? 'UNKNOWN';
    const existing = paymentByType.get(methodCode) ?? { amount: 0, count: 0 };
    existing.amount += total;
    existing.count += 1;
    paymentByType.set(methodCode, existing);
  }

  const vatBreakdown: ZReportVatBreakdown[] = [];
  for (const [rate, totals] of vatByRate) {
    vatBreakdown.push({
      tax_rate: parseFloat(rate),
      net_amount: totals.net.toFixed(decimals),
      vat_amount: totals.vat.toFixed(decimals),
      gross_amount: totals.gross.toFixed(decimals),
    });
  }
  vatBreakdown.sort((a, b) => a.tax_rate - b.tax_rate);

  const paymentMethods: ZReportPaymentMethod[] = [];
  for (const [type, data] of paymentByType) {
    paymentMethods.push({
      payment_type: type,
      total_amount: data.amount.toFixed(decimals),
      transaction_count: data.count,
    });
  }

  return {
    sales_count: salesCount,
    gross_sales: grossSales.toFixed(decimals),
    net_sales: netSales.toFixed(decimals),
    tax_amount: taxAmount.toFixed(decimals),
    refunds_count: refundsCount,
    refunds_amount: refundsAmount.toFixed(decimals),
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

    // Build tax lines grouped by rate
    const taxByRate = new Map<number, ReceiptSnapshotTaxLine>();
    for (const line of lines) {
      const rate = parseFloat(line.tax_rate ?? '0');
      const lineNet = parseFloat(line.line_total ?? '0');
      const lineVat = parseFloat(line.tax_amount ?? '0');
      const lineGross = lineNet + lineVat;

      const existing = taxByRate.get(rate) ?? { rate, net_amount: 0, tax_amount: 0, gross_amount: 0 };
      existing.net_amount += lineNet;
      existing.tax_amount += lineVat;
      existing.gross_amount += lineGross;
      taxByRate.set(rate, existing);
    }

    const snapshotLines: ReceiptSnapshotLine[] = lines.map((line) => ({
      description: line.name ?? '',
      quantity: line.quantity ?? 1,
      unit_price: parseFloat(line.unit_price ?? '0'),
      total: parseFloat(line.line_total ?? '0'),
      tax_rate: parseFloat(line.tax_rate ?? '0'),
      discount_amount: parseFloat(line.discount_amount ?? '0'),
    }));

    return {
      receipt_number: receipt.receipt_number,
      fiscal_hash: receipt.fiscal_hash,
      hash_sequence: receipt.hash_sequence,
      created_at: receipt.created_at,

      total_ht: parseFloat(receipt.subtotal),
      total_ttc: parseFloat(receipt.total),
      total_tax: parseFloat(receipt.tax_amount),

      tax_lines: Array.from(taxByRate.values()),

      payment_type: methodCode,
      payment_amount: parseFloat(receipt.total),
      change_given: parseFloat(receipt.change_due ?? '0'),

      lines: snapshotLines,

      voided: false,
      void_reason: null,
      refund_of: null,

      operator_id: receipt.operator_id,
      operator_name: receipt.operator_name,
    };
  });
}
