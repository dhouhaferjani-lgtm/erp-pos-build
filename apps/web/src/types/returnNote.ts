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
 * Return note metadata
 */
export interface ReturnNoteMetadata {
  return_reason: ReturnReason
  return_condition?: ReturnCondition | null
  refund_method?: RefundMethod | null
  source_delivery_note_id?: string | null
  source_invoice_id?: string | null
  linked_credit_note_id?: string | null
  notes?: string | null
}

/**
 * Return note from API response
 */
export interface ReturnNote {
  id: string
  document_number: string
  document_date: string // ISO 8601
  partner: PartnerInfo | null
  currency: string
  subtotal: string // Decimal string
  tax_amount: string // Decimal string
  total: string // Decimal string
  status: DocumentStatus
  metadata: ReturnNoteMetadata
  created_at: string // ISO 8601
}

/**
 * Request payload for creating return note
 */
export interface CreateReturnNoteRequest {
  source_delivery_note_id?: string
  source_invoice_id?: string
  return_reason: ReturnReason
  return_condition?: ReturnCondition
  refund_method?: RefundMethod
  notes?: string
  auto_create_credit_note?: boolean
  lines?: Array<{
    line_id: string
    quantity: number
  }>
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
    typeof obj['document_number'] === 'string' &&
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
