import { apiGet, apiPost } from '@/lib/api'

export interface CurrentShift {
  id: string
  terminal_id: string
  shift_number: number
  status: 'OPEN' | 'CLOSED'
  opening_cash: string
  opened_at: string
  user: {
    id: string
    name: string
  }
}

export interface OpenShiftData {
  terminal_code: string
  opening_cash: string
}

export interface ShiftBalance {
  opening_cash: string
  total_sales: string
  total_refunds: string
  total_deposits: string
  total_payouts: string
  expected_cash: string
}

export interface CashOperationData {
  shift_id: string
  amount: string
  reason: string
}

export interface XReportData {
  terminal_id: string
}

export interface VatBreakdownEntry {
  rate: string
  net: string
  vat: string
  gross: string
}

export interface PaymentMethodEntry {
  method: string
  count: number
  amount: string
}

export interface XReportResponse {
  id: string
  terminal_id: string
  shift_id: string
  generated_by: string
  generated_at: string
  sales_count: number
  gross_sales: string
  net_sales: string
  tax_amount: string
  refunds_count: number
  vat_breakdown: VatBreakdownEntry[]
  payment_methods: PaymentMethodEntry[]
}

export interface ZReportData {
  terminal_id: string
}

/**
 * Get the current shift for a terminal
 */
export async function getCurrentShift(terminalId: string): Promise<CurrentShift | null> {
  try {
    return await apiGet<CurrentShift>(`/pos/shifts/current/${terminalId}`)
  } catch (error) {
    // If no shift exists, return null instead of throwing
    return null
  }
}

/**
 * Open a new shift with opening cash amount
 */
export async function openShift(data: OpenShiftData): Promise<CurrentShift> {
  return apiPost<CurrentShift>('/pos/shifts/open', data)
}

/**
 * Close a shift with actual cash count
 */
export async function closeShift(shiftId: string, actualCash: string): Promise<CurrentShift> {
  return apiPost<CurrentShift>(`/pos/shifts/${shiftId}/close`, { actual_cash: actualCash })
}

/**
 * Generate X-report for current shift (mid-shift report, doesn't close shift)
 */
export async function generateXReport(data: XReportData): Promise<XReportResponse> {
  return apiPost<XReportResponse>('/pos/reports/x', data)
}

/**
 * Generate Z-report and close shift (end-of-shift report)
 */
export async function generateZReport(data: ZReportData): Promise<void> {
  return apiPost<void>('/pos/reports/z', data)
}

/**
 * Record a cash deposit (adding cash to drawer)
 */
export async function recordCashDeposit(data: CashOperationData): Promise<void> {
  return apiPost<void>('/pos/cash-drawer/deposit', data)
}

/**
 * Record a cash payout (removing cash from drawer)
 */
export async function recordCashPayout(data: CashOperationData): Promise<void> {
  return apiPost<void>('/pos/cash-drawer/payout', data)
}

/**
 * Get current cash balance and shift totals
 */
export async function getShiftBalance(shiftId: string): Promise<ShiftBalance> {
  return apiGet<ShiftBalance>(`/pos/cash-drawer/${shiftId}/balance`)
}

export interface ShiftReceipt {
  id: string
  receipt_number: string
  total: string
  subtotal: string
  tax_amount: string
  currency: string
  posted_at: string
  is_voided: boolean
  cashier_name: string
}

/**
 * Get receipts for a shift (transaction history)
 *
 * Note: apiGet unwraps the outer { data: ... } envelope, so this
 * returns the receipts array directly. Pagination meta is not available
 * through apiGet — use the raw axios client if pagination is needed.
 */
export async function getShiftReceipts(
  shiftId: string,
  page: number = 1
): Promise<ShiftReceipt[]> {
  return apiGet<ShiftReceipt[]>(`/pos/shifts/${shiftId}/receipts?page=${String(page)}`)
}
