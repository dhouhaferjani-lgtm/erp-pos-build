/**
 * Return Note TypeScript Types
 * Document Module - Return Note Features
 */

// ============================================================================
// Constants (instead of enums for erasableSyntaxOnly compatibility)
// ============================================================================

/**
 * Return reason values
 */
export const ReturnReason = {
  DEFECTIVE: 'defective',
  WRONG_ITEM: 'wrong_item',
  CUSTOMER_REGRET: 'customer_regret',
  DAMAGED_IN_TRANSIT: 'damaged_in_transit',
  WARRANTY: 'warranty',
  EXCHANGE: 'exchange',
  OTHER: 'other',
} as const

export type ReturnReason = (typeof ReturnReason)[keyof typeof ReturnReason]

/**
 * Return condition values
 */
export const ReturnCondition = {
  UNOPENED: 'unopened',
  USED: 'used',
  DAMAGED: 'damaged',
  UNUSABLE: 'unusable',
} as const

export type ReturnCondition = (typeof ReturnCondition)[keyof typeof ReturnCondition]

/**
 * Refund method values
 */
export const RefundMethod = {
  ORIGINAL_PAYMENT: 'original_payment',
  STORE_CREDIT: 'store_credit',
  EXCHANGE: 'exchange',
  NONE: 'none',
} as const

export type RefundMethod = (typeof RefundMethod)[keyof typeof RefundMethod]

/**
 * Document status values
 */
export const DocumentStatus = {
  DRAFT: 'draft',
  CONFIRMED: 'confirmed',
  CANCELLED: 'cancelled',
} as const

export type DocumentStatus = (typeof DocumentStatus)[keyof typeof DocumentStatus]

// ============================================================================
// Interfaces
// ============================================================================

/**
 * Partner minimal info
 */
export interface PartnerInfo {
  id: string
  name: string
}

/**
 * Return note from API response
 */
export interface ReturnNote {
  id: string
  /**
   * NULL while the return note is a DRAFT (R-2 / LEDGER D-T9-1): the number is
   * allocated at confirm. Render `sales:documents.draftNumberPlaceholder`.
   */
  document_number: string | null
  document_date: string // ISO 8601
  partner: PartnerInfo | null
  currency: string
  subtotal: string // Decimal string
  tax_amount: string // Decimal string
  total: string // Decimal string
  status: DocumentStatus
  /**
   * The invoice or delivery note this return came from.
   *
   * Plan CF T8. Replaces the deleted `metadata` property, which was the reason the
   * broken response read typechecked: `ReturnNoteMetadata` is instantiated NOWHERE in
   * `apps/api/app` and the endpoint returns `DocumentData::fromModel()`, which has no
   * `metadata` key at all. Every read of `returnNote.metadata.*` therefore threw at
   * runtime the moment a create ever succeeded — which, before T8, it never did.
   *
   * Reason and condition live in `documents.payload`, not on the response.
   */
  source_document_id: string | null
  /**
   * Where the return reason and condition actually live.
   *
   * `ReturnNoteController::store()` writes them into `documents.payload`, and the index
   * endpoint serialises raw `Document` models — so `payload` is what the list receives.
   * Optional because the detail endpoint returns `DocumentData`, which projects payload
   * rather than emitting it.
   */
  payload?: {
    return_reason?: ReturnReason | null
    return_condition?: ReturnCondition | null
  } | null
  created_at: string // ISO 8601
}

/**
 * Request payload for creating return note
 */
export interface CreateReturnNoteLine {
  product_id?: string
  description: string
  /**
   * A decimal STRING, never a number (rule 19). `number` silently truncated
   * fractional returns for any unit with `decimal_places > 0`, and the backend type is
   * `DocumentLineData.quantity: string`.
   */
  quantity: string
  /** Net/HT, copied verbatim from the source line — never round-tripped through a number. */
  unit_price: string
  tax_rate?: string
  location_id?: string
}

/**
 * The canonical document-create shape the backend has always accepted.
 *
 * Plan CF T8 / CF-D9. The previous interface described a payload NO SERVER ROUTE HAS
 * EVER ACCEPTED: `CreateDocumentRequest` requires `partner_id`, `document_date` and
 * `lines` (min:1) with per-line `description` + `unit_price`, knows only
 * `source_document_id`, and has no `prepareForValidation()` key mapping.
 * `auto_create_credit_note` matches ZERO occurrences anywhere in `apps/api/app`;
 * `source_invoice_id` exists only as an index-endpoint query FILTER; and
 * `lines[].line_id` is consumed by a different endpoint entirely
 * (`CreditNoteService`). Both the "full" and the "partial" mode 422'd, from both entry
 * points.
 *
 * Repaired towards the BACKEND (CF-D9), not the reverse: this is the tested shape every
 * document type shares, and teaching one controller a thin bespoke shape would fork
 * document-create validation for a single type.
 *
 * `lines` is KEPT here — this is the standalone partial-return surface
 * (`/sales/return-notes/new`). The guided cancel flow drops line selection entirely
 * (CF-D7) and does not use this request at all.
 */
export interface CreateReturnNoteRequest {
  partner_id: string
  document_date: string // YYYY-MM-DD
  currency?: string
  source_document_id?: string
  location_id?: string
  notes?: string
  return_reason?: ReturnReason
  return_condition?: ReturnCondition
  lines: CreateReturnNoteLine[]
}

/**
 * Response from creating return note
 */
export interface CreateReturnNoteResponse {
  data: ReturnNote
  message: string
}

/**
 * Return notes list response
 */
export interface ReturnNotesListResponse {
  data: ReturnNote[]
}

/**
 * Single return note response
 */
export interface ReturnNoteResponse {
  data: ReturnNote
}

/**
 * Source document info for return note creation
 */
export interface SourceDocumentForReturn {
  id: string
  document_number: string
  document_date: string
  partner: PartnerInfo
  total: string // Decimal string
  currency: string
  status: string
}

// ============================================================================
// Type Guards
// ============================================================================

/**
 * Check if return reason is valid
 */
export function isReturnReason(value: unknown): value is ReturnReason {
  return (
    typeof value === 'string' &&
    Object.values(ReturnReason).includes(value as ReturnReason)
  )
}

/**
 * Check if return condition is valid
 */
export function isReturnCondition(value: unknown): value is ReturnCondition {
  return (
    typeof value === 'string' &&
    Object.values(ReturnCondition).includes(value as ReturnCondition)
  )
}

/**
 * Check if refund method is valid
 */
export function isRefundMethod(value: unknown): value is RefundMethod {
  return (
    typeof value === 'string' &&
    Object.values(RefundMethod).includes(value as RefundMethod)
  )
}

/**
 * Check if document status is valid
 */
export function isDocumentStatus(value: unknown): value is DocumentStatus {
  return (
    typeof value === 'string' &&
    Object.values(DocumentStatus).includes(value as DocumentStatus)
  )
}

/**
 * Check if return note is valid
 */
export function isReturnNote(value: unknown): value is ReturnNote {
  if (typeof value !== 'object' || value === null) return false

  const obj = value as Record<string, unknown>
  return (
    typeof obj['id'] === 'string' &&
    (typeof obj['document_number'] === 'string' || obj['document_number'] === null) &&
    typeof obj['total'] === 'string' &&
    typeof obj['metadata'] === 'object' &&
    obj['metadata'] !== null
  )
}

// ============================================================================
// Utility Functions
// ============================================================================

/**
 * Check if source document can have return note
 */
export function canCreateReturnNote(_document: SourceDocumentForReturn): {
  allowed: boolean
  reason?: string
} {
  // Add validation logic as needed
  return { allowed: true }
}

/**
 * Get return note status badge color
 */
export function getReturnNoteStatusColor(status: DocumentStatus): string {
  switch (status) {
    case DocumentStatus.DRAFT:
      return 'gray'
    case DocumentStatus.CONFIRMED:
      return 'green'
    case DocumentStatus.CANCELLED:
      return 'red'
    default:
      return 'gray'
  }
}

/**
 * Get return reason color for visual indicator
 */
export function getReturnReasonColor(reason: ReturnReason): string {
  switch (reason) {
    case ReturnReason.DEFECTIVE:
      return 'red'
    case ReturnReason.WRONG_ITEM:
      return 'orange'
    case ReturnReason.CUSTOMER_REGRET:
      return 'blue'
    case ReturnReason.DAMAGED_IN_TRANSIT:
      return 'red'
    case ReturnReason.WARRANTY:
      return 'purple'
    case ReturnReason.EXCHANGE:
      return 'green'
    case ReturnReason.OTHER:
      return 'gray'
    default:
      return 'gray'
  }
}

/**
 * Get refund method description
 */
export function getRefundMethodRequiresCreditNote(method: RefundMethod | null | undefined): boolean {
  if (!method) return false

  return method === RefundMethod.ORIGINAL_PAYMENT || method === RefundMethod.STORE_CREDIT
}
