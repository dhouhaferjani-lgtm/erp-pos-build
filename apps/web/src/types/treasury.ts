/**
 * Treasury Module TypeScript Types
 * Smart Payment Features
 */

import { bcadd } from '@/lib/decimal'

// ============================================================================
// Constants (instead of enums for erasableSyntaxOnly compatibility)
// ============================================================================

/**
 * Payment allocation method values
 */
export const AllocationMethod = {
  FIFO: 'fifo',
  DUE_DATE: 'due_date',
  MANUAL: 'manual',
} as const;

export type AllocationMethod = (typeof AllocationMethod)[keyof typeof AllocationMethod];

/**
 * Payment allocation method labels
 */
export const AllocationMethodLabels: Record<AllocationMethod, string> = {
  [AllocationMethod.FIFO]: 'treasury.payment.allocationMethod.fifo',
  [AllocationMethod.DUE_DATE]: 'treasury.payment.allocationMethod.dueDate',
  [AllocationMethod.MANUAL]: 'treasury.payment.allocationMethod.manual',
};

// ============================================================================
// Interfaces
// ============================================================================

/**
 * Payment tolerance settings for a company.
 *
 * `source` matches PaymentToleranceService::determineSettingsSource which
 * returns 'system_default' (not 'system') when neither company nor country
 * settings are present. The legacy 'system' value is kept here so older
 * stored cache snapshots still parse if any are encountered.
 */
export interface ToleranceSettings {
  enabled: boolean;
  percentage: string; // Decimal string (e.g., "0.0050" for 0.5%)
  max_amount: string; // Decimal string (e.g., "5.0000")
  source: 'company' | 'country' | 'system' | 'system_default';
}

/**
 * Manual allocation for specific invoice
 */
export interface ManualAllocation {
  document_id: string;
  amount: string; // Decimal string
}

/**
 * Payment allocation result for a single document
 */
export interface PaymentAllocationItem {
  document_id: string;
  document_number: string;
  amount: string; // Decimal string - amount allocated to this document
  original_balance?: string; // Decimal string - original document balance
  tolerance_writeoff?: string; // Decimal string - tolerance amount written off
  days_overdue?: number;
}

/**
 * Payment allocation preview response
 */
export interface PaymentAllocationPreview {
  allocation_method: AllocationMethod;
  allocations: PaymentAllocationItem[];
  total_to_invoices: string; // Decimal string
  excess_amount: string; // Decimal string
  excess_handling: 'credit_balance' | 'tolerance_writeoff' | 'none';
  tolerance_settings?: ToleranceSettings;
}

/**
 * Request payload for previewing payment allocation
 */
export interface PaymentAllocationPreviewRequest {
  partner_id: string;
  payment_amount: string; // Decimal string
  allocation_method: AllocationMethod;
  manual_allocations?: ManualAllocation[];
}

/**
 * Request payload for applying payment allocation
 */
export interface ApplyAllocationRequest {
  payment_id: string;
  allocation_method: AllocationMethod;
  manual_allocations?: ManualAllocation[];
}

/**
 * Response from applying payment allocation
 */
export interface ApplyAllocationResponse {
  payment_id: string;
  allocations: PaymentAllocationItem[];
  total_allocated: string; // Decimal string
  excess_amount: string; // Decimal string
  message: string;
}

/**
 * Open invoice for payment allocation
 */
export interface OpenInvoice {
  id: string;
  document_number: string;
  document_date: string; // ISO 8601
  due_date: string; // ISO 8601
  total: string; // Decimal string
  balance_due: string; // Decimal string
  currency: string;
  days_overdue: number;
  partner: {
    id: string;
    name: string;
  };
}

// ============================================================================
// Type Guards
// ============================================================================

/**
 * Check if allocation method is valid
 */
export function isAllocationMethod(value: unknown): value is AllocationMethod {
  return (
    typeof value === 'string' &&
    Object.values(AllocationMethod).includes(value as AllocationMethod)
  );
}

/**
 * Check if tolerance settings are valid
 */
export function isToleranceSettings(value: unknown): value is ToleranceSettings {
  if (typeof value !== 'object' || value === null) return false;

  const obj = value as Record<string, unknown>;
  return (
    typeof obj['enabled'] === 'boolean' &&
    typeof obj['percentage'] === 'string' &&
    typeof obj['max_amount'] === 'string' &&
    (obj['source'] === 'company'
      || obj['source'] === 'country'
      || obj['source'] === 'system'
      || obj['source'] === 'system_default')
  );
}

// ============================================================================
// Utility Functions
// ============================================================================

/**
 * Calculate total manual allocations
 */
export function calculateManualAllocationsTotal(allocations: ManualAllocation[], scale: number = 3): string {
  return allocations.reduce((total, allocation) => {
    return bcadd(total, allocation.amount || '0', scale);
  }, '0.' + '0'.repeat(scale));
}

/**
 * Validate manual allocation amount
 */
export function validateAllocationAmount(
  amount: string,
  invoiceBalance: string
): { valid: boolean; error?: string } {
  const amountNum = parseFloat(amount);
  const balanceNum = parseFloat(invoiceBalance);

  if (isNaN(amountNum)) {
    return { valid: false, error: 'treasury.payment.errors.invalidAmount' };
  }

  if (amountNum <= 0) {
    return { valid: false, error: 'treasury.payment.errors.amountMustBePositive' };
  }

  if (amountNum > balanceNum) {
    return { valid: false, error: 'treasury.payment.errors.amountExceedsBalance' };
  }

  return { valid: true };
}

/**
 * Format allocation method for display
 */
export function formatAllocationMethod(method: AllocationMethod): string {
  return AllocationMethodLabels[method] || method;
}

/**
 * Check if payment has tolerance write-off
 */
export function hasToleranceWriteoff(preview: PaymentAllocationPreview): boolean {
  return preview.excess_handling === 'tolerance_writeoff';
}

/**
 * Get total tolerance writeoff amount
 */
export function getTotalToleranceWriteoff(preview: PaymentAllocationPreview, scale: number = 3): string {
  return preview.allocations.reduce((total, item) => {
    if (item.tolerance_writeoff) {
      return bcadd(total, item.tolerance_writeoff, scale);
    }
    return total;
  }, '0.' + '0'.repeat(scale));
}

// ============================================================================
// Bank Reconciliation Types
// ============================================================================

/**
 * Reconciliation status values
 */
export const ReconciliationStatus = {
  DRAFT: 'draft',
  COMPLETED: 'completed',
  CANCELLED: 'cancelled',
} as const;

export type ReconciliationStatus = (typeof ReconciliationStatus)[keyof typeof ReconciliationStatus];

/**
 * Bank reconciliation item (payment to reconcile)
 */
export interface BankReconciliationItem {
  id: string;
  payment_id: string;
  payment_date: string;
  payment_reference: string;
  payment_amount: string;
  partner_name: string | null;
  is_matched: boolean;
  bank_reference: string | null;
  notes: string | null;
  matched_at: string | null;
}

/**
 * Bank reconciliation session
 */
export interface BankReconciliation {
  id: string;
  repository_id: string;
  repository_name: string | null;
  statement_date: string;
  opening_balance: string;
  closing_balance: string;
  statement_balance: string;
  difference: string;
  status: ReconciliationStatus;
  created_by: string | null;
  completed_by: string | null;
  completed_at: string | null;
  notes: string | null;
  created_at: string | null;
  items?: BankReconciliationItem[];
}

/**
 * Bank reconciliation summary
 */
export interface ReconciliationSummary {
  reconciliation_id: string;
  repository_name: string;
  statement_date: string;
  opening_balance: string;
  closing_balance: string;
  statement_balance: string;
  difference: string;
  status: ReconciliationStatus;
  matched_count: number;
  unmatched_count: number;
  matched_total: string;
  unmatched_total: string;
  can_complete: boolean;
}

/**
 * Request to start a new reconciliation
 */
export interface StartReconciliationRequest {
  repository_id: string;
  statement_date: string;
  opening_balance?: string;
  statement_balance: string;
  notes?: string;
}

/**
 * Request to match an item
 */
export interface MatchItemRequest {
  bank_reference?: string;
  notes?: string;
}

/**
 * Payment repository for reconciliation
 */
export interface PaymentRepository {
  id: string;
  name: string;
  type: string;
  balance: string;
  currency: string;
  last_reconciled_at: string | null;
  last_reconciled_balance: string | null;
}
