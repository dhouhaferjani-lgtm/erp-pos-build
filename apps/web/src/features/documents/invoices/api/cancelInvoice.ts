import { api, apiGet } from '@/lib/api'

/**
 * What the user said about the goods when cancelling the invoice.
 *
 * Mirrors the backend `ReturnDecisionMode` enum. `no_goods_issued` and
 * `not_applicable` are NOT extra flavours of "no return" — they are different facts,
 * and recording the wrong one tells an auditor the customer kept units that never
 * shipped. See `CancelInvoiceModal` for which branch posts which.
 */
export type ReturnDecisionMode =
  | 'will_return'
  | 'already_returned'
  | 'no_return'
  | 'no_goods_issued'
  | 'not_applicable'

export interface ReturnDecisionInput {
  mode: ReturnDecisionMode
  /** Required for `already_returned`, PROHIBITED for every other mode. */
  returned_on?: string
}

export interface CancelInvoiceRequest {
  reason: string
  /** Absent keeps the server's pre-existing behaviour byte for byte. */
  return_decision?: ReturnDecisionInput
}

export interface CancelInvoiceResponse {
  data: unknown
  return_decision: {
    mode: ReturnDecisionMode
    returned_on: string | null
    return_note: {
      id: string
      document_number: string
      status: 'draft' | 'confirmed'
    } | null
  } | null
  message: string
}

/**
 * Delivered quantity for one `(product, location)` tuple.
 *
 * Reported per LOCATION, not merely per product: a product's delivered quantity can
 * legitimately span two warehouses, and the modal has to be able to explain that the
 * return will restock into both.
 */
export interface DeliveredQuantity {
  product_id: string
  location_id: string
  delivered: string
  already_returned: string
  remaining: string
}

export interface CanCancelResponse {
  can_cancel: boolean
  reason_code: string | null
  status: string
  /** TRUE when the invoice has at least one physical line. */
  requires_return_decision: boolean
  /**
   * TRUE only when units actually left the building and have not all come back.
   * FAILS CLOSED — no delivery linkage, an unconfirmed delivery note or an
   * unresolvable location all yield false, because posting an invoice moves no stock
   * and offering a restock for goods that never shipped would create inventory.
   */
  goods_issued: boolean
  delivered_quantities: DeliveredQuantity[]
  /** A decision already recorded for this cancellation, if any. */
  return_decision: {
    mode: ReturnDecisionMode
    returned_on: string | null
    return_note_id: string | null
    decided_by: string | null
    decided_at: string
    accepted: boolean
  } | null
}

/** Every typed refusal the guided cancel flow can return. */
export const CancelErrorCodes = {
  DOCUMENT_HAS_PAYMENTS: 'DOCUMENT_HAS_PAYMENTS',
  DOCUMENT_PERIOD_CLOSED: 'DOCUMENT_PERIOD_CLOSED',
  DOCUMENT_PERIOD_FILED: 'DOCUMENT_PERIOD_FILED',
  RETURN_PERIOD_CLOSED: 'RETURN_PERIOD_CLOSED',
  RETURN_PERIOD_FILED: 'RETURN_PERIOD_FILED',
  RETURN_PERIOD_LOCKED: 'RETURN_PERIOD_LOCKED',
  RETURN_NOTHING_DELIVERED: 'RETURN_NOTHING_DELIVERED',
  RETURN_LOCATION_UNRESOLVED: 'RETURN_LOCATION_UNRESOLVED',
  RETURN_LOCATION_AMBIGUOUS: 'RETURN_LOCATION_AMBIGUOUS',
  RETURN_EXCEEDS_DELIVERED_QUANTITY: 'RETURN_EXCEEDS_DELIVERED_QUANTITY',
  RETURN_EXCEEDS_INVOICED_QUANTITY: 'RETURN_EXCEEDS_INVOICED_QUANTITY',
  RETURN_DECISION_ALREADY_RECORDED: 'RETURN_DECISION_ALREADY_RECORDED',
  RETURN_DECISION_FORBIDDEN: 'RETURN_DECISION_FORBIDDEN',
} as const

export type CancelErrorCode = (typeof CancelErrorCodes)[keyof typeof CancelErrorCodes]

/**
 * Cancel an invoice, optionally recording an explicit decision about the goods.
 *
 * ONE composite call, not three (plan CF CF-D1): a crash between a cancel, a
 * return-note create and a return-note confirm would leave a cancelled invoice with no
 * return note and no record of what the user chose — the silent outcome the owner
 * ruling forbids, reached by accident.
 *
 * Uses `api.post` rather than `apiPost` DELIBERATELY: the response's `return_decision`
 * sits alongside `data`, and `apiPost` unwraps `data.data` and would drop it.
 */
export async function cancelInvoice(
  invoiceId: string,
  request: CancelInvoiceRequest,
): Promise<CancelInvoiceResponse> {
  const response = await api.post<CancelInvoiceResponse>(
    `/invoices/${invoiceId}/cancel`,
    request,
  )
  return response.data
}

/**
 * Whether the invoice can be cancelled, AND everything the modal needs to render.
 *
 * `apiGet` single-unwraps `data.data` — return it directly (rule 14). This endpoint
 * wraps its whole body in `data`, so single-unwrapping is correct here.
 */
export async function getCanCancel(invoiceId: string): Promise<CanCancelResponse> {
  return apiGet<CanCancelResponse>(`/invoices/${invoiceId}/can-cancel`)
}
