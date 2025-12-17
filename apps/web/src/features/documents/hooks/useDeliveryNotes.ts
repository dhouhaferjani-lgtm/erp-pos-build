/**
 * Delivery Note React Query Hooks
 * Document Module - Delivery Note Features including Tunisia Model Consolidation
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  getDeliveryNotes,
  getInvoiceableDeliveryNotes,
  getDeliveryNote,
  consolidateDeliveryNotesToInvoice,
  type DeliveryNote,
} from '../api/deliveryNotes'
import { getErrorMessage } from '@/lib/api'

/**
 * Query hook: Get delivery notes list.
 *
 * Fetches all delivery notes, optionally filtered by status or partner.
 *
 * @example
 * // Get all delivery notes
 * const { data: deliveryNotes } = useDeliveryNotes()
 *
 * // Get confirmed delivery notes
 * const { data: deliveryNotes } = useDeliveryNotes({ status: 'confirmed' })
 */
export function useDeliveryNotes(params?: {
  status?: 'draft' | 'confirmed' | 'cancelled'
  partner_id?: string
}) {
  return useQuery({
    queryKey: ['delivery-notes', params],
    queryFn: () => getDeliveryNotes(params),
  })
}

/**
 * Query hook: Get invoiceable delivery notes.
 *
 * Fetches confirmed delivery notes that have not been invoiced yet.
 * Used for the consolidation feature.
 *
 * @example
 * // Get all invoiceable delivery notes
 * const { data: deliveryNotes } = useInvoiceableDeliveryNotes()
 *
 * // Get invoiceable delivery notes for a specific partner
 * const { data: deliveryNotes } = useInvoiceableDeliveryNotes(partnerId)
 */
export function useInvoiceableDeliveryNotes(partnerId?: string) {
  return useQuery({
    queryKey: ['delivery-notes', 'invoiceable', partnerId],
    queryFn: () => getInvoiceableDeliveryNotes(partnerId),
  })
}

/**
 * Query hook: Get a single delivery note by ID.
 *
 * Fetches full delivery note details including lines.
 *
 * @example
 * const { data: deliveryNote, isLoading } = useDeliveryNote(deliveryNoteId)
 */
export function useDeliveryNote(id: string | undefined) {
  return useQuery({
    queryKey: ['delivery-note', id],
    queryFn: () => getDeliveryNote(id!),
    enabled: !!id,
  })
}

/**
 * Mutation hook: Consolidate delivery notes to invoice.
 *
 * Creates a single invoice from one or more confirmed delivery notes (Tunisia model).
 * **Financial operation** - uses pessimistic UI (no optimistic updates).
 *
 * Invalidates related queries on success:
 * - Delivery notes list (status updated to invoiced)
 * - Invoiceable delivery notes list
 * - Documents list
 * - Invoices list
 *
 * @example
 * const consolidateMutation = useConsolidateDeliveryNotes()
 *
 * consolidateMutation.mutate(['dn-id-1', 'dn-id-2', 'dn-id-3'], {
 *   onSuccess: (response) => {
 *     toast.success(`Invoice ${response.data.document_number} created from ${response.meta.consolidated_delivery_notes} delivery notes`)
 *     navigate(`/sales/invoices/${response.data.id}`)
 *   },
 *   onError: (error) => {
 *     toast.error(error.message)
 *   },
 * })
 */
export function useConsolidateDeliveryNotes() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (deliveryNoteIds: string[]) =>
      consolidateDeliveryNotesToInvoice(deliveryNoteIds),
    onSuccess: () => {
      // Invalidate delivery notes lists
      void queryClient.invalidateQueries({ queryKey: ['delivery-notes'] })

      // Invalidate documents lists
      void queryClient.invalidateQueries({ queryKey: ['documents'] })

      // Invalidate invoices list
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
      toast.success('Invoice created from delivery notes')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Helper to group delivery notes by partner.
 *
 * Since consolidation requires all DNs to be from the same partner,
 * this helper groups them for easier selection UI.
 */
export function groupDeliveryNotesByPartner(
  deliveryNotes: DeliveryNote[]
): Map<string, DeliveryNote[]> {
  const grouped = new Map<string, DeliveryNote[]>()

  for (const dn of deliveryNotes) {
    const partnerId = dn.partner_id
    const existing = grouped.get(partnerId) ?? []
    grouped.set(partnerId, [...existing, dn])
  }

  return grouped
}

/**
 * Calculate totals for selected delivery notes.
 *
 * Useful for showing preview of what the consolidated invoice will total.
 */
export function calculateConsolidationTotals(deliveryNotes: DeliveryNote[]): {
  subtotal: number
  taxAmount: number
  total: number
  lineCount: number
} {
  let subtotal = 0
  let taxAmount = 0
  let total = 0
  let lineCount = 0

  for (const dn of deliveryNotes) {
    subtotal += parseFloat(dn.subtotal ?? '0')
    taxAmount += parseFloat(dn.tax_amount ?? '0')
    total += parseFloat(dn.total ?? '0')
    lineCount += dn.lines?.length ?? 0
  }

  return { subtotal, taxAmount, total, lineCount }
}
