/**
 * Types for offline Z-report generation and multi-country fiscal export.
 */

// ─── Receipt Snapshot (per-receipt data stored inside each Z-report) ─────────

export interface ReceiptSnapshotTaxLine {
  rate: number;
  net_amount: number;
  tax_amount: number;
  gross_amount: number;
}

export interface ReceiptSnapshotLine {
  description: string;
  quantity: number;
  unit_price: number;
  total: number;
  tax_rate: number;
  discount_amount: number;
}

export interface ReceiptSnapshot {
  receipt_number: string;
  fiscal_hash: string;
  hash_sequence: number;
  created_at: string;

  total_ht: number;
  total_ttc: number;
  total_tax: number;

  tax_lines: ReceiptSnapshotTaxLine[];

  payment_type: string;
  payment_amount: number;
  change_given: number;

  lines: ReceiptSnapshotLine[];

  voided: boolean;
  void_reason: string | null;
  refund_of: string | null;

  operator_id: string;
  operator_name: string;
}

// ─── Z-Report Data (aggregated shift totals) ────────────────────────────────

export interface ZReportVatBreakdown {
  tax_rate: number;
  net_amount: string;
  vat_amount: string;
  gross_amount: string;
}

export interface ZReportPaymentMethod {
  payment_type: string;
  total_amount: string;
  transaction_count: number;
}

export interface ZReportData {
  sales_count: number;
  gross_sales: string;
  net_sales: string;
  tax_amount: string;
  refunds_count: number;
  refunds_amount: string;
  voided_count: number;
  vat_breakdown: ZReportVatBreakdown[];
  payment_methods: ZReportPaymentMethod[];
  opening_cash?: string;
  expected_cash?: string;
  variance?: string | null;
}

// ─── Grand Totals (perpetual counters) ───────────────────────────────────────

export interface GrandTotals {
  cumulative_sales: number;
  cumulative_tax: number;
  cumulative_refunds: number;
  perpetual_grand_total: number;
  receipt_count_lifetime: number;
}

// ─── Local Z-Report (SQLite row) ────────────────────────────────────────────

export interface LocalZReport {
  id: string;
  terminal_id: string;
  shift_id: string;
  z_number: number;
  formatted_z_number: string;
  generated_at: string;
  fiscal_hash: string;
  previous_hash: string;
  hash_sequence: number;
  report_data: ZReportData;
  opening_cash: number;
  expected_cash: number;
  receipt_snapshots: ReceiptSnapshot[];
  grand_totals: GrandTotals;
  synced: boolean;
  synced_at: string | null;
}

// ─── Z-Chain State (subset of terminal_state for Z-report chain) ─────────────

export interface ZChainState {
  z_last_hash: string;
  z_hash_sequence: number;
  z_number: number;
  cumulative_sales: number;
  cumulative_tax: number;
  cumulative_refunds: number;
  perpetual_grand_total: number;
  receipt_count_lifetime: number;
}
