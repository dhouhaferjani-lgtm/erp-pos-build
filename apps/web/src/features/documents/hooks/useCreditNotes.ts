/**
 * Credit Note React Query Hooks
 * Document Module - Credit Note Features
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getCreditNotes, getCreditNote, createCreditNote } from '../api/creditNotes'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { CreateCreditNoteRequest } from '@/types/creditNote'

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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['credit-notes', params]),
    queryFn: () => getCreditNotes(params),
    enabled: tenantId !== null && companyId !== null,
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['credit-note', id]),
    queryFn: () => getCreditNote(id!),
    enabled: !!id && tenantId !== null && companyId !== null,
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (request: CreateCreditNoteRequest) => createCreditNote(request),
    onSuccess: async (creditNote) => {
      // Invalidate credit notes list
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('credit-notes', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: ['invoice', creditNote.source_invoice_id],
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
      ])
      toast.success('Credit note created')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
