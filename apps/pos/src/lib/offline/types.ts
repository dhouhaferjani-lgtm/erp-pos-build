/**
 * Types for offline Z-report generation and multi-country fiscal export.
 */

// ─── Receipt Snapshot (per-receipt data stored inside each Z-report) ─────────

export interface ReceiptSnapshotTaxLine {
  /** Tax rate percentage — stored as number for sorting (not a monetary value) */
  rate: number;
  net_amount: string;
  tax_amount: string;
  gross_amount: string;
}

export interface ReceiptSnapshotLine {
  description: string;
  quantity: number;
  unit_price: string;
  total: string;
  /** Tax rate percentage — stored as number (not a monetary value) */
  tax_rate: number;
  discount_amount: string;
}

export interface ReceiptSnapshot {
  receipt_number: string;
  fiscal_hash: string;
  hash_sequence: number;
  created_at: string;

  total_ht: string;
  total_ttc: string;
  total_tax: string;

  tax_lines: ReceiptSnapshotTaxLine[];

  payment_type: string;
  payment_amount: string;
  change_given: string;

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

export interface ZReportCountEntry {
  payment_method_id: string;
  currency_code: string;
  expected_amount: string;
  actual_amount: string;
  variance_amount: string;
  variance_direction: 'over' | 'under' | 'balanced';
  transaction_count: number;
}

export interface ZReportToleranceSummary {
  /** Decimal string with 3dp — must match server ZReportHashService shape */
  totalAmount: string;
  currencyCode: string;
  writeoffCount: number;
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
  /** Present only on schema_version 2 reports (when cash counts are provided). */
  schema_version?: number;
  /** Per-tender cash count data stamped into report_data for hash input. */
  cash_counts?: ZReportCountEntry[];
  /** Tolerance write-off summary (zero-shape when no write-offs occurred). */
  tolerance_summary?: ZReportToleranceSummary | null;
}

// ─── Grand Totals (perpetual counters) ───────────────────────────────────────

// Monetary cumulative fields are decimal strings to preserve multi-decimal
// precision (TND uses 3 decimals). Stored as TEXT in SQLite since v21.
export interface GrandTotals {
  cumulative_sales: string;
  cumulative_tax: string;
  cumulative_refunds: string;
  perpetual_grand_total: string;
  receipt_count_lifetime: number;
}

// ─── Local Z-Report (SQLite row) ────────────────────────────────────────────

export interface LocalZReportShiftFields {
  blind_count_used: boolean;
  variance_severity: string | null;
  variance_reason: string | null;
  manager_override_by: string | null;
}

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
  opening_cash: string;
  expected_cash: string;
  receipt_snapshots: ReceiptSnapshot[];
  grand_totals: GrandTotals;
  synced: boolean;
  synced_at: string | null;
  /** Per-tender cash count rows (present when cash counting was performed). */
  cash_counts?: ZReportCountEntry[];
  /** Shift-level fields from the v22 extended columns. */
  shift_fields?: LocalZReportShiftFields | null;
  /** UUID of the manager who authorized an over-threshold variance, if any. */
  manager_user_id?: string | null;
  /** Tolerance write-off summary. Present when schema_version === 2. */
  tolerance_summary?: ZReportToleranceSummary | null;
  /** ISO 4217 currency code for the shift (used in tolerance_summary fallback). */
  currency_code?: string;
}

// ─── Z-Chain State (subset of terminal_state for Z-report chain) ─────────────

export interface ZChainState {
  z_last_hash: string;
  z_hash_sequence: number;
  z_number: number;
  cumulative_sales: string;
  cumulative_tax: string;
  cumulative_refunds: string;
  perpetual_grand_total: string;
  receipt_count_lifetime: number;
}
