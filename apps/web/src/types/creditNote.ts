/**
 * Credit Note TypeScript Types
 * Document Module - Credit Note Features
 */

import { bcadd, bcsub } from '@/lib/decimal';

// ============================================================================
// Constants (instead of enums for erasableSyntaxOnly compatibility)
// ============================================================================

/**
 * Credit note reason values
 */
export const CreditNoteReason = {
  RETURN: 'return',
  PRICE_ADJUSTMENT: 'price_adjustment',
  BILLING_ERROR: 'billing_error',
  DAMAGED_GOODS: 'damaged_goods',
  SERVICE_ISSUE: 'service_issue',
  OTHER: 'other',
} as const;

export type CreditNoteReason = (typeof CreditNoteReason)[keyof typeof CreditNoteReason];

/**
 * Credit note reason labels for i18n
 */
export const CreditNoteReasonLabels: Record<CreditNoteReason, string> = {
  [CreditNoteReason.RETURN]: 'sales.creditNote.reason.return',
  [CreditNoteReason.PRICE_ADJUSTMENT]: 'sales.creditNote.reason.priceAdjustment',
  [CreditNoteReason.BILLING_ERROR]: 'sales.creditNote.reason.billingError',
  [CreditNoteReason.DAMAGED_GOODS]: 'sales.creditNote.reason.damagedGoods',
  [CreditNoteReason.SERVICE_ISSUE]: 'sales.creditNote.reason.serviceIssue',
  [CreditNoteReason.OTHER]: 'sales.creditNote.reason.other',
};

/**
 * Document status values
 */
export const DocumentStatus = {
  DRAFT: 'draft',
  CONFIRMED: 'confirmed',
  POSTED: 'posted',
  CANCELLED: 'cancelled',
} as const;

export type DocumentStatus = (typeof DocumentStatus)[keyof typeof DocumentStatus];

// ============================================================================
// Interfaces
// ============================================================================

/**
 * Partner minimal info
 */
export interface PartnerInfo {
  id: string;
  name: string;
}

/**
 * Credit note from API response
 */
export interface CreditNote {
  id: string;
  document_number: string;
  document_date: string; // ISO 8601
  source_invoice_id: string;
  source_invoice_number: string;
  partner: PartnerInfo | null;
  currency: string;
  subtotal: string; // Decimal string
  tax_amount: string; // Decimal string
  total: string; // Decimal string
  reason: CreditNoteReason;
  reason_label: string;
  notes: string | null;
  status: DocumentStatus;
  /**
   * C-F0w / SPEC §2.4 — the SERVER's proforma predicate
   * (`ProformaOutputPolicy`, keyed on the fiscal seal), emitted by
   * `CreditNoteController::formatCreditNote()`.
   *
   * This view used to guess from `status` and its own comment conceded it "cannot
   * tell" whether a `posted` credit note was ever sealed. It is told now. Never
   * re-derive it. Optional only because existing fixtures predate the field.
   *
   * Compare with `!== false`, NOT `=== true` (fiscal gate r1 F-3, and what
   * `CreditNoteDetailPage.tsx` does). An omitted field must degrade toward
   * "print less" — treated as a proforma — rather than toward claiming a seal on
   * a document nothing has sealed. (`components/CreditNoteDetail.tsx` still reads
   * `=== true`; it is exported but mounted by no route, per its own docblock, and
   * aligning it belongs with the mount-or-delete ticket.)
   */
  is_proforma?: boolean;
  created_at: string; // ISO 8601
}

/**
 * Request payload for creating credit note
 */
export interface CreateCreditNoteRequest {
  source_invoice_id: string;
  amount: string; // Decimal string
  reason: CreditNoteReason;
  notes?: string;
}

/**
 * Response from creating credit note
 */
export interface CreateCreditNoteResponse {
  data: CreditNote;
  message: string;
}

/**
 * Credit notes list response
 */
export interface CreditNotesListResponse {
  data: CreditNote[];
}

/**
 * Single credit note response
 */
export interface CreditNoteResponse {
  data: CreditNote;
}

/**
 * Invoice info for credit note creation
 */
export interface InvoiceForCreditNote {
  id: string;
  document_number: string;
  document_date: string;
  partner: PartnerInfo;
  total: string; // Decimal string
  balance_due: string; // Decimal string (after existing credit notes)
  currency: string;
  status: DocumentStatus;
}

/**
 * Credit note summary for invoice
 */
export interface CreditNoteSummary {
  total_credit_notes: number;
  total_credited_amount: string; // Decimal string
  remaining_creditable_amount: string; // Decimal string
  credit_notes: CreditNote[];
}

// ============================================================================
// Type Guards
// ============================================================================

/**
 * Check if credit note reason is valid
 */
export function isCreditNoteReason(value: unknown): value is CreditNoteReason {
  return (
    typeof value === 'string' &&
    Object.values(CreditNoteReason).includes(value as CreditNoteReason)
  );
}

/**
 * Check if document status is valid
 */
export function isDocumentStatus(value: unknown): value is DocumentStatus {
  return (
    typeof value === 'string' &&
    Object.values(DocumentStatus).includes(value as DocumentStatus)
  );
}

/**
 * Check if credit note is valid
 */
export function isCreditNote(value: unknown): value is CreditNote {
  if (typeof value !== 'object' || value === null) return false;

  const obj = value as Record<string, unknown>;
  return (
    typeof obj['id'] === 'string' &&
    typeof obj['document_number'] === 'string' &&
    typeof obj['source_invoice_id'] === 'string' &&
    typeof obj['total'] === 'string' &&
    isCreditNoteReason(obj['reason'])
  );
}

// ============================================================================
// Utility Functions
// ============================================================================

/**
 * Format credit note reason for display
 */
export function formatCreditNoteReason(reason: CreditNoteReason): string {
  return CreditNoteReasonLabels[reason] || reason;
}

/**
 * Check if invoice can have credit note
 */
export function canCreateCreditNote(invoice: InvoiceForCreditNote): {
  allowed: boolean;
  reason?: string;
} {
  if (invoice.status !== DocumentStatus.POSTED) {
    return {
      allowed: false,
      reason: 'sales.creditNote.errors.invoiceNotPosted',
    };
  }

  const remaining = parseFloat(invoice.balance_due);
  if (remaining <= 0) {
    return {
      allowed: false,
      reason: 'sales.creditNote.errors.invoiceFullyCredited',
    };
  }

  return { allowed: true };
}

/**
 * Validate credit note amount
 */
export function validateCreditNoteAmount(
  amount: string,
  invoiceTotal: string,
  existingCredits: string
): { valid: boolean; error?: string } {
  const amountNum = parseFloat(amount);
  const totalNum = parseFloat(invoiceTotal);
  const creditsNum = parseFloat(existingCredits);

  if (isNaN(amountNum)) {
    return { valid: false, error: 'sales.creditNote.errors.invalidAmount' };
  }

  if (amountNum <= 0) {
    return { valid: false, error: 'sales.creditNote.errors.amountMustBePositive' };
  }

  if (amountNum > totalNum) {
    return { valid: false, error: 'sales.creditNote.errors.exceedsInvoiceTotal' };
  }

  const remaining = totalNum - creditsNum;
  if (amountNum > remaining) {
    return { valid: false, error: 'sales.creditNote.errors.exceedsRemainingBalance' };
  }

  return { valid: true };
}

/**
 * Calculate remaining creditable amount
 */
export function calculateRemainingCreditableAmount(
  invoiceTotal: string,
  existingCredits: CreditNote[],
  scale: number = 3
): string {
  const creditsTotal = existingCredits.reduce((sum, cn) => {
    return bcadd(sum, cn.total || '0', scale);
  }, '0');

  return bcsub(invoiceTotal, creditsTotal, scale);
}

/**
 * Get credit note percentage of invoice
 */
export function getCreditNotePercentage(
  creditNoteAmount: string,
  invoiceTotal: string
): number {
  const creditNum = parseFloat(creditNoteAmount);
  const totalNum = parseFloat(invoiceTotal);

  if (totalNum === 0) return 0;

  return Math.round((creditNum / totalNum) * 100);
}

/**
 * Check if credit note is full refund
 */
export function isFullRefund(creditNote: CreditNote, invoiceTotal: string): boolean {
  const creditNum = parseFloat(creditNote.total);
  const totalNum = parseFloat(invoiceTotal);

  // Consider it full refund if within 0.01 (1 cent) difference
  return Math.abs(creditNum - totalNum) < 0.01;
}

/**
 * Format credit note status badge color
 */
export function getCreditNoteStatusColor(status: DocumentStatus): string {
  switch (status) {
    case DocumentStatus.DRAFT:
      return 'gray';
    case DocumentStatus.CONFIRMED:
      return 'blue';
    case DocumentStatus.POSTED:
      return 'green';
    case DocumentStatus.CANCELLED:
      return 'red';
    default:
      return 'gray';
  }
}

/**
 * Get credit note reason color for visual indicator
 */
export function getCreditNoteReasonColor(reason: CreditNoteReason): string {
  switch (reason) {
    case CreditNoteReason.RETURN:
      return 'blue';
    case CreditNoteReason.PRICE_ADJUSTMENT:
      return 'purple';
    case CreditNoteReason.BILLING_ERROR:
      return 'orange';
    case CreditNoteReason.DAMAGED_GOODS:
      return 'red';
    case CreditNoteReason.SERVICE_ISSUE:
      return 'yellow';
    case CreditNoteReason.OTHER:
      return 'gray';
    default:
      return 'gray';
  }
}
