/**
 * Return Note API Functions
 * Document Module - Return Note Features
 */

import { apiGet, apiPost } from '@/lib/api'
import type {
  ReturnNote,
  CreateReturnNoteRequest,
} from '@/types/returnNote'

/**
 * Get return notes list.
 *
 * GET /api/v1/return-notes?source_invoice_id=uuid&source_delivery_note_id=uuid
 *
 * Fetches all return notes, optionally filtered by source document.
 */
export async function getReturnNotes(params?: {
  source_invoice_id?: string
  source_delivery_note_id?: string
}): Promise<ReturnNote[]> {
  return apiGet<ReturnNote[]>('/return-notes', params)
}

/**
 * Get a single return note by ID.
 *
 * GET /api/v1/return-notes/{id}
 *
 * Fetches full return note details including lines and metadata.
 */
export async function getReturnNote(id: string): Promise<ReturnNote> {
  return apiGet<ReturnNote>(`/return-notes/${id}`)
}

/**
 * Create a return note from an invoice or delivery note.
 *
 * POST /api/v1/return-notes
 *
 * Creates a return note as a draft.
 * **Stock operation** - uses pessimistic UI pattern.
 *
 * Validates:
 * - Source document exists (invoice or delivery note)
 * - Return reason is provided
 * - Return quantities do not exceed original quantities
 */
export async function createReturnNote(
  request: CreateReturnNoteRequest
): Promise<ReturnNote> {
  return apiPost<ReturnNote>('/return-notes', request)
}

/**
 * Confirm a draft return note (Draft -> Confirmed).
 *
 * POST /api/v1/return-notes/{id}/confirm
 *
 * NOTE: return notes are a separate resource from `/documents` — there is
 * no `/documents/{id}/confirm` route. Always go through this function (or
 * the matching real route directly) rather than reusing the generic
 * document confirm/post URL shape.
 */
export async function confirmReturnNote(id: string): Promise<ReturnNote> {
  return apiPost<ReturnNote>(`/return-notes/${id}/confirm`)
}
