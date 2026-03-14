import { apiGet, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { generateZReport as generateLocalZReport } from '@/lib/offline/zReportService';
import type { LocalZReport } from '@/lib/offline/types';

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
  return apiPost<XReportResponse>('/pos/reports/x', { terminal_id: terminalId });
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
  openingCash: number,
): Promise<ZReportResponse> {
  const db = await getDatabase(companyId);
  const localReport = await generateLocalZReport(db, terminalId, shiftId, shiftOpenedAt, openingCash);
  return localZReportToResponse(localReport);
}

/** Map local Z-report to the same shape the UI expects. */
function localZReportToResponse(report: LocalZReport): ZReportResponse {
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
    sales_count: report.report_data.sales_count,
    gross_sales: report.report_data.gross_sales,
    opening_cash: report.opening_cash.toFixed(2),
    expected_cash: report.expected_cash.toFixed(2),
    actual_cash: '0.00',
    variance: '0.00',
    has_variance: false,
    report_data: {
      sales_count: report.report_data.sales_count,
      gross_sales: report.report_data.gross_sales,
      net_sales: report.report_data.net_sales,
      tax_amount: report.report_data.tax_amount,
      refunds_count: report.report_data.refunds_count,
      refunds_amount: report.report_data.refunds_amount,
      voided_count: report.report_data.voided_count,
      opening_cash: report.opening_cash.toFixed(2),
      expected_cash: report.expected_cash.toFixed(2),
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

/** Server-only Z-report generation (for admin dashboard fallback). */
export async function generateZReportServer(terminalId: string): Promise<ZReportResponse> {
  return apiPost<ZReportResponse>('/pos/reports/z', { terminal_id: terminalId });
}

export async function fetchShiftReceipts(shiftId: string): Promise<ShiftReceipt[]> {
  return apiGet<ShiftReceipt[]>(`/pos/shifts/${shiftId}/receipts`);
}

export async function fetchCashDrawerOps(shiftId: string): Promise<CashDrawerOperation[]> {
  return apiGet<CashDrawerOperation[]>(`/pos/cash-drawer/${shiftId}/operations`);
}
