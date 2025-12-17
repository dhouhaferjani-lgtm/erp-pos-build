/**
 * Credit Note React Query Hooks
 * Document Module - Credit Note Features
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getCreditNotes, getCreditNote, createCreditNote } from '../api/creditNotes'
import { getErrorMessage } from '@/lib/api'
import type { CreateCreditNoteRequest } from '@/types/creditNote'

/**
 * Query hook: Get credit notes list.
 *
 * Fetches all credit notes, optionally filtered by source invoice.
 *
 * @example
 * // Get all credit notes
 * const { data: creditNotes } = useCreditNotes()
 *
 * // Get credit notes for a specific invoice
 * const { data: creditNotes } = useCreditNotes({ source_invoice_id: '123' })
 */
export function useCreditNotes(params?: { source_invoice_id?: string }) {
  return useQuery({
    queryKey: ['credit-notes', params],
    queryFn: () => getCreditNotes(params),
  })
}

/**
 * Query hook: Get a single credit note by ID.
 *
 * Fetches full credit note details including lines.
 *
 * @example
 * const { data: creditNote, isLoading } = useCreditNote(creditNoteId)
 */
export function useCreditNote(id: string | undefined) {
  return useQuery({
    queryKey: ['credit-note', id],
    queryFn: () => getCreditNote(id!),
    enabled: !!id,
  })
}

/**
 * Mutation hook: Create a credit note.
 *
 * Creates a credit note from an invoice as a draft.
 * **Financial operation** - uses pessimistic UI (no optimistic updates).
 *
 * Invalidates related queries on success:
 * - Credit notes list
 * - Source invoice (balance_due updated)
 * - Invoice credit note summary
 *
 * @example
 * const createMutation = useCreateCreditNote()
 *
 * createMutation.mutate({
 *   source_invoice_id: '123',
 *   amount: '500.00',
 *   reason: 'return',
 *   notes: 'Product returned',
 * }, {
 *   onSuccess: (creditNote) => {
 *     toast.success('Credit note created successfully')
 *     navigate(`/documents/credit-notes/${creditNote.id}`)
 *   },
 *   onError: (error) => {
 *     toast.error(error.message)
 *   },
 * })
 */
export function useCreateCreditNote() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (request: CreateCreditNoteRequest) => createCreditNote(request),
    onSuccess: (creditNote) => {
      // Invalidate credit notes list
      void queryClient.invalidateQueries({ queryKey: ['credit-notes'] })

      // Invalidate source invoice (balance_due updated)
      void queryClient.invalidateQueries({
        queryKey: ['invoice', creditNote.source_invoice_id],
      })

      // Invalidate documents list
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      toast.success('Credit note created')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
