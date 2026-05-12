import Big from 'big.js';
import { apiGet, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { queryAll } from '@/lib/db';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bcformat } from '@/lib/decimal';
import { useAuthStore } from '@/stores/authStore';
import { generateZReport as generateLocalZReport } from '@/lib/offline/zReportService';
import type { GenerateZReportOpts } from '@/lib/offline/zReportService';
import { getAllPaymentMethods } from '@/lib/db/repositories/paymentRepository';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import type { LocalZReport } from '@/lib/offline/types';

export type { GenerateZReportOpts };

export interface VatBreakdownItem {
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
  vat_breakdown: VatBreakdownItem[];
  payment_methods: PaymentMethodItem[];
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

export async function generateXReport(terminalId: string): Promise<XReportResponse> {
  try {
    return await apiPost<XReportResponse>('/pos/reports/x', { terminal_id: terminalId });
  } catch {
    // Offline fallback: generate X report from local SQLite data
    return await generateLocalXReport(terminalId);
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
  const localReport = await generateLocalZReport(db, terminalId, shiftId, shiftOpenedAt, openingCash, opts);
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

export async function fetchShiftReceipts(shiftId: string): Promise<ShiftReceipt[]> {
  try {
    return await apiGet<ShiftReceipt[]>(`/pos/shifts/${shiftId}/receipts`);
  } catch (err) {
    const { useConnectivityStore } = await import('@/stores/connectivityStore');
    if (!useConnectivityStore.getState().isOnline) {
      // Offline: silent fallback to local SQLite.
      return await fetchLocalShiftReceipts();
    }
    // Online error: bubble up so the UI can surface it as a toast.
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
async function generateLocalXReport(terminalId: string): Promise<XReportResponse> {
  const db = await getDb();
  const decimals = getDecimals();

  // Get current shift open time from terminal store
  const { useTerminalStore } = await import('@/stores/terminalStore');
  const shift = useTerminalStore.getState().shift;
  const shiftOpenedAt = shift?.opened_at ?? new Date(0).toISOString();

  const receipts = await queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE terminal_id = $1 AND created_at >= $2
     ORDER BY hash_sequence ASC`,
    [terminalId, shiftOpenedAt],
  );

  // Build payment method lookup
  const methods = await getAllPaymentMethods(db);
  const methodMap = new Map<string, string>();
  for (const m of methods) {
    methodMap.set(m.id, m.code);
  }

  // Aggregate
  let grossSales = '0';
  let netSales = '0';
  let taxAmount = '0';
  const vatByRate = new Map<string, { net: string; vat: string; gross: string }>();
  const paymentByType = new Map<string, { amount: string; count: number }>();

  for (const receipt of receipts) {
    grossSales = bcadd(grossSales, receipt.total);
    netSales = bcadd(netSales, receipt.subtotal);
    taxAmount = bcadd(taxAmount, receipt.tax_amount);

    const lines = JSON.parse(receipt.lines) as ReceiptLineJson[];
    for (const line of lines) {
      const rate = line.tax_rate ?? '0';
      const lineVat = line.tax_amount ?? '0';
      const lineNet = line.line_total ?? '0';
      const existing = vatByRate.get(rate) ?? { net: '0', vat: '0', gross: '0' };
      existing.net = bcadd(existing.net, lineNet);
      existing.vat = bcadd(existing.vat, lineVat);
      existing.gross = bcadd(existing.gross, bcadd(lineNet, lineVat));
      vatByRate.set(rate, existing);
    }

    const methodCode = methodMap.get(receipt.payment_method_id) ?? 'UNKNOWN';
    const payExisting = paymentByType.get(methodCode) ?? { amount: '0', count: 0 };
    payExisting.amount = bcadd(payExisting.amount, receipt.total);
    payExisting.count += 1;
    paymentByType.set(methodCode, payExisting);
  }

  return {
    id: `local-x-${Date.now()}`,
    terminal_id: terminalId,
    shift_id: shift?.id ?? null,
    generated_by: 'local',
    generated_at: new Date().toISOString(),
    sales_count: receipts.length,
    gross_sales: bcformat(grossSales, decimals),
    net_sales: bcformat(netSales, decimals),
    tax_amount: bcformat(taxAmount, decimals),
    refunds_count: 0,
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
    [terminal.id, shiftOpenedAt],
  );

  // Build payment method lookup for labels
  const methods = await getAllPaymentMethods(db);
  const methodMap = new Map<string, string>();
  for (const m of methods) {
    methodMap.set(m.id, m.code);
  }

  return receipts.map((receipt): ShiftReceipt => {
    const lines = JSON.parse(receipt.lines) as ReceiptLineJson[];
    const methodCode = methodMap.get(receipt.payment_method_id) ?? 'CASH';

    return {
      id: receipt.id,
      receipt_number: receipt.receipt_number,
      receipt_type: 'sale',
      total: receipt.total,
      subtotal: receipt.subtotal,
      tax_amount: receipt.tax_amount,
      is_voided: false,
      posted_at: receipt.created_at,
      payments: [{
        id: `local-pay-${receipt.id}`,
        payment_type: methodCode,
        amount: receipt.total,
      }],
      lines: lines.map((line, idx) => ({
        id: `local-line-${receipt.id}-${String(idx)}`,
        quantity: line.quantity,
        unit_price: line.unit_price,
        line_total: line.line_total,
        product_name: line.name,
      })),
    };
  });
}
