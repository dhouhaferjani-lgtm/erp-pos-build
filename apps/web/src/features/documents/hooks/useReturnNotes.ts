/**
 * Return Note React Query Hooks
 * Document Module - Return Note Features
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getReturnNotes, getReturnNote, createReturnNote } from '../api/returnNotes'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { CreateReturnNoteRequest } from '@/types/returnNote'

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

/**
 * Query hook: Get return notes list.
 *
 * Fetches all return notes, optionally filtered by source document.
 *
 * @example
 * // Get all return notes
 * const { data: returnNotes } = useReturnNotes()
 *
 * // Get return notes for a specific invoice
 * const { data: returnNotes } = useReturnNotes({ source_invoice_id: '123' })
 *
 * // Get return notes for a specific delivery note
 * const { data: returnNotes } = useReturnNotes({ source_delivery_note_id: '123' })
 */
export function useReturnNotes(params?: {
  source_invoice_id?: string
  source_delivery_note_id?: string
}) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['return-notes', params]),
    queryFn: () => getReturnNotes(params),
    enabled: tenantId !== null && companyId !== null,
  })
}

/**
 * Query hook: Get a single return note by ID.
 *
 * Fetches full return note details including lines and metadata.
 *
 * @example
 * const { data: returnNote, isLoading } = useReturnNote(returnNoteId)
 */
export function useReturnNote(id: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['return-note', id]),
    queryFn: () => getReturnNote(id!),
    enabled: !!id && tenantId !== null && companyId !== null,
  })
}

/**
 * Mutation hook: Create a return note.
 *
 * Creates a return note from an invoice or delivery note as a draft.
 * **Stock operation** - uses pessimistic UI (no optimistic updates).
 *
 * Invalidates related queries on success:
 * - Return notes list
 * - Source document
 * - Documents list
 *
 * @example
 * const createMutation = useCreateReturnNote()
 *
 * createMutation.mutate({
 *   source_invoice_id: '123',
 *   return_reason: 'defective',
 *   return_condition: 'damaged',
 *   refund_method: 'original_payment',
 *   notes: 'Product was damaged on arrival',
 * }, {
 *   onSuccess: (returnNote) => {
 *     toast.success('Return note created successfully')
 *     navigate(`/documents/return-notes/${returnNote.id}`)
 *   },
 *   onError: (error) => {
 *     toast.error(error.message)
 *   },
 * })
 */
export function useCreateReturnNote() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (request: CreateReturnNoteRequest) => createReturnNote(request),
    onSuccess: async (returnNote) => {
      // Invalidate return notes list
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('return-notes', tenantId, companyId),
        }),
        // Plan CF T8. `returnNote.metadata` never existed on any response — the
        // endpoint returns `DocumentData::fromModel()`, which has no `metadata` key,
        // and `ReturnNoteMetadata` is instantiated nowhere in `apps/api/app`. Reading
        // it threw the moment a create succeeded, which before T8 it never did.
        //
        // One `source_document_id` replaces the two invented keys, and the source could
        // be either an invoice or a delivery note, so both prefixes are invalidated.
        //
        // These stay BARE literal prefixes on purpose: `invalidateQueries` is a cache
        // FILTER factory whose `queryKey` is a positional PREFIX matcher, and
        // `tenantScopedKey` appends SUFFIXES — wrapping a filter is a proven no-op that
        // `audit-tanstack-keys.mjs` flags as an error. Storage keys get
        // `tenantScopedKey`; cache filters stay bare.
        returnNote.source_document_id
          ? queryClient.invalidateQueries({
            queryKey: ['invoice', returnNote.source_document_id],
          })
          : Promise.resolve(),
        returnNote.source_document_id
          ? queryClient.invalidateQueries({
            queryKey: ['delivery-note', returnNote.source_document_id],
          })
          : Promise.resolve(),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
      ])

      toast.success('Return note created')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
