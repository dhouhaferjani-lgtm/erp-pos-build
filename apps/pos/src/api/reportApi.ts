import { apiGet, apiPost } from '@/lib/api';

export interface XReportResponse {
  id: string;
  shift_id: string;
  generated_at: string;
  total_sales: string;
  total_returns: string;
  net_sales: string;
  cash_in_drawer: string;
  transaction_count: number;
  payment_breakdown: Array<{
    method: string;
    amount: string;
    count: number;
  }>;
}

export interface ZReportResponse {
  id: string;
  shift_id: string;
  generated_at: string;
  total_sales: string;
  total_returns: string;
  net_sales: string;
  opening_cash: string;
  closing_cash: string;
  expected_cash: string;
  difference: string;
  transaction_count: number;
  payment_breakdown: Array<{
    method: string;
    amount: string;
    count: number;
  }>;
}

export interface ShiftReceipt {
  id: string;
  receipt_number: string;
  total: string;
  status: string;
  created_at: string;
  payment_method: string;
}

export interface CashDrawerOperation {
  id: string;
  type: 'deposit' | 'payout';
  amount: string;
  reason: string;
  created_at: string;
  user_name: string;
}

export async function generateXReport(): Promise<XReportResponse> {
  return apiPost<XReportResponse>('/pos/reports/x');
}

export async function generateZReport(): Promise<ZReportResponse> {
  return apiPost<ZReportResponse>('/pos/reports/z');
}

export async function fetchShiftReceipts(shiftId: string): Promise<ShiftReceipt[]> {
  return apiGet<ShiftReceipt[]>(`/pos/shifts/${shiftId}/receipts`);
}

export async function fetchCashDrawerOps(shiftId: string): Promise<CashDrawerOperation[]> {
  return apiGet<CashDrawerOperation[]>(`/pos/cash-drawer/${shiftId}/operations`);
}
