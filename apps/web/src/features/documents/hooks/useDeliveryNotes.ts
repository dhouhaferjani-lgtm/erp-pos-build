/**
 * Delivery Note React Query Hooks
 * Document Module - Delivery Note Features including Tunisia Model Consolidation
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  getDeliveryNotes,
  getInvoiceableDeliveryNotes,
  getDeliveryNote,
  getPartnerDeliveryNotes,
  getToBillPartnerRows,
  getToBillQueue,
  consolidateDeliveryNotesToInvoice,
  type DeliveryNote,
  type PartnerDeliveryNoteFilter,
  type ToBillQueueParams,
} from '../api/deliveryNotes'
import { getErrorMessage } from '@/lib/api'
import { parseDeliveryNoteBillingRefusal } from '../deliveryNoteBillingRefusal'
import { useTranslation } from 'react-i18next'

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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['delivery-notes', params]),
    queryFn: () => getDeliveryNotes(params),
    enabled: tenantId !== null && companyId !== null,
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['delivery-notes', 'invoiceable', partnerId]),
    queryFn: () => getInvoiceableDeliveryNotes(partnerId),
    enabled: tenantId !== null && companyId !== null,
  })
}

export function usePartnerDeliveryNotes({
  partnerId,
  filter,
  page,
  perPage,
  enabled = true,
}: {
  partnerId: string
  filter: PartnerDeliveryNoteFilter
  page: number
  perPage: number
  enabled?: boolean
}) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['delivery-notes', 'partner', partnerId, filter, page, perPage]),
    queryFn: () => getPartnerDeliveryNotes({ partnerId, filter, page, perPage }),
    enabled: enabled && tenantId !== null && companyId !== null,
  })
}

export function useToBillQueue(params: ToBillQueueParams) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const locationScope = params.locationId === null ? [] : [params.locationId]

  return useQuery({
    queryKey: locationScopedKey(['delivery-notes-to-bill', 'summary', params], locationScope),
    queryFn: () => getToBillQueue(params),
    enabled: tenantId !== null && companyId !== null && params.locationId !== null,
  })
}

export function useToBillPartnerRows(
  partnerId: string,
  params: ToBillQueueParams,
  enabled: boolean,
) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const locationScope = params.locationId === null ? [] : [params.locationId]

  return useQuery({
    queryKey: locationScopedKey(
      ['delivery-notes-to-bill', 'partner', partnerId, params],
      locationScope,
    ),
    queryFn: () => getToBillPartnerRows(partnerId, params),
    enabled: enabled && tenantId !== null && companyId !== null && params.locationId !== null,
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['delivery-note', id]),
    queryFn: () => getDeliveryNote(id!),
    enabled: !!id && tenantId !== null && companyId !== null,
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
  const { t } = useTranslation('sales')
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (deliveryNoteIds: string[]) =>
      consolidateDeliveryNotesToInvoice(deliveryNoteIds),
    onSuccess: async () => {
      // Invalidate delivery notes lists
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('delivery-notes', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('invoices', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('delivery-note', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('document', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('partner-account-balance', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('delivery-notes-to-bill', tenantId, companyId),
        }),
      ])
      toast.success(t('deliveryNotes.partnerTab.created'))
    },
    onError: (error) => {
      if (parseDeliveryNoteBillingRefusal(error) === null) {
        toast.error(getErrorMessage(error))
      }
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
    const partnerId = dn.partner_id ?? 'unassigned'
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
