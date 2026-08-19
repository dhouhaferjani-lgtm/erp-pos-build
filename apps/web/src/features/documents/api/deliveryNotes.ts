/**
 * Delivery Note API Functions
 * Document Module - Delivery Note Features including Tunisia Model Consolidation
 */

import { api, apiGet, apiPost } from '@/lib/api'

/**
 * Delivery Note document type
 */
export type DeliveryNote = Pick<App.Modules.Document.Application.DTOs.DocumentData,
  | 'id'
  | 'document_number'
  | 'type'
  | 'status'
  | 'partner_id'
  | 'partner_name'
  | 'document_date'
  | 'subtotal'
  | 'tax_amount'
  | 'total'
  | 'currency'
  | 'lines'
  | 'invoiced_at'
  | 'invoiced_by_document_id'
  | 'invoiced_by_document_number'
  | 'invoiced_via'
  | 'created_at'
  | 'updated_at'
>
export type DeliveryNoteLine = App.Modules.Document.Application.DTOs.DocumentLineData

export type PartnerDeliveryNoteFilter = 'uninvoiced' | 'invoiced' | 'all'

export interface PartnerDeliveryNotesResponse {
  data: DeliveryNote[]
  meta: {
    current_page: number
    last_page: number
    total: number
    per_page: number
    from: number | null
    to: number | null
  }
  aggregates?: {
    count: number
    total: string
    currency: string
  }
}

/**
 * Response from consolidation endpoint
 */
export interface ConsolidationResponse {
  data: {
    id: string
    document_number: string
    type: string
    status: string
    subtotal: string
    tax_amount: string
    total: string
    lines: DeliveryNoteLine[]
    partner: {
      id: string
      name: string
    }
    reference?: string
  }
  message: string
  meta: {
    consolidated_delivery_notes: number
    source_delivery_note_ids: string[]
  }
}

/**
 * Get delivery notes list.
 *
 * GET /api/v1/delivery-notes
 *
 * Fetches all delivery notes, optionally filtered by status or partner.
 */
export async function getDeliveryNotes(params?: {
  status?: 'draft' | 'confirmed' | 'cancelled'
  partner_id?: string
}): Promise<DeliveryNote[]> {
  return apiGet<DeliveryNote[]>('/delivery-notes', params)
}

/**
 * Get confirmed delivery notes that are not yet invoiced.
 *
 * GET /api/v1/delivery-notes?status=confirmed
 *
 * Fetches delivery notes eligible for consolidation (confirmed and not invoiced).
 */
export async function getInvoiceableDeliveryNotes(partnerId?: string): Promise<DeliveryNote[]> {
  const deliveryNotes = await apiGet<DeliveryNote[]>('/delivery-notes', {
    status: 'confirmed',
    partner_id: partnerId,
    uninvoiced: 1,
  })

  return deliveryNotes
}

export async function getPartnerDeliveryNotes({
  partnerId,
  filter,
  page,
  perPage,
}: {
  partnerId: string
  filter: PartnerDeliveryNoteFilter
  page: number
  perPage: number
}): Promise<PartnerDeliveryNotesResponse> {
  const filterParam = filter === 'all' ? {} : { [filter]: 1 }
  const response = await api.get<PartnerDeliveryNotesResponse>('/delivery-notes', {
    params: {
      partner_id: partnerId,
      status: 'confirmed',
      ...filterParam,
      page,
      per_page: perPage,
      with_aggregates: 1,
    },
  })

  return response.data
}

/**
 * Get a single delivery note by ID.
 *
 * GET /api/v1/delivery-notes/{id}
 *
 * Fetches full delivery note details including lines.
 */
export async function getDeliveryNote(id: string): Promise<DeliveryNote> {
  return apiGet<DeliveryNote>(`/delivery-notes/${id}`)
}

/**
 * Confirm a draft delivery note (Draft -> Confirmed).
 *
 * POST /api/v1/delivery-notes/{id}/confirm
 *
 * NOTE: delivery notes are a separate resource from `/documents` — there is
 * no `/documents/{id}/confirm` route. Always go through this function (or
 * the matching real route directly) rather than reusing the generic
 * document confirm/post URL shape.
 */
export async function confirmDeliveryNote(id: string): Promise<DeliveryNote> {
  return apiPost<DeliveryNote>(`/delivery-notes/${id}/confirm`)
}

/**
 * Consolidate delivery notes into a single invoice (Tunisia model).
 *
 * POST /api/v1/delivery-notes/consolidate-to-invoice
 *
 * Creates a single invoice from one or more confirmed delivery notes.
 * All delivery notes must belong to the same partner and use the same currency.
 * **Financial operation** - uses pessimistic UI pattern.
 *
 * @param deliveryNoteIds - Array of delivery note UUIDs to consolidate
 * @returns The created invoice with metadata about the consolidation
 *
 * Validates:
 * - All delivery notes exist and are confirmed
 * - All belong to the same partner
 * - All use the same currency
 * - None have been previously invoiced
 */
export async function consolidateDeliveryNotesToInvoice(
  deliveryNoteIds: string[]
): Promise<ConsolidationResponse> {
  const response = await api.post<ConsolidationResponse>(
    '/delivery-notes/consolidate-to-invoice',
    { delivery_note_ids: deliveryNoteIds }
  )
  return response.data
}
