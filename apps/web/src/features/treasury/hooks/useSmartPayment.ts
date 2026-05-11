/**
 * Smart Payment React Query Hooks
 * Treasury Module - Payment Allocation & Tolerance Features
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  getToleranceSettings,
  previewPaymentAllocation,
  applyPaymentAllocation,
} from '../api/smartPayment'
import { getErrorMessage } from '@/lib/api'
import type {
  PaymentAllocationPreviewRequest,
  ApplyAllocationRequest,
} from '@/types/treasury'

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
 * Query hook: Get tolerance settings for current company.
 *
 * Fetches the effective tolerance settings (company → country → system).
 * This is a read-only operation with optimistic caching.
 *
 * @example
 * const { data: settings, isLoading } = useToleranceSettings()
 * if (settings?.enabled) {
 *   console.log(`Tolerance: ${settings.percentage}% / ${settings.max_amount} max`)
 * }
 */
export function useToleranceSettings() {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['smart-payment', 'tolerance-settings']),
    queryFn: () => getToleranceSettings(),
    enabled: tenantId !== null && companyId !== null,
    staleTime: 5 * 60 * 1000, // 5 minutes (rarely changes)
  })
}

/**
 * Mutation hook: Preview payment allocation.
 *
 * Shows how a payment would be allocated across open invoices
 * without actually creating allocation records.
 * This is a preview operation - safe for optimistic UI.
 *
 * @example
 * const previewMutation = usePaymentAllocationPreview()
 *
 * previewMutation.mutate({
 *   partner_id: '123',
 *   payment_amount: '1500.00',
 *   allocation_method: 'fifo',
 * })
 */
export function usePaymentAllocationPreview() {
  return useMutation({
    mutationFn: (request: PaymentAllocationPreviewRequest) =>
      previewPaymentAllocation(request),
    // No cache invalidation needed - this is a preview operation
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Mutation hook: Apply payment allocation.
 *
 * Creates PaymentAllocation records linking a payment to invoices.
 * **Financial operation** - uses pessimistic UI (no optimistic updates).
 *
 * Invalidates related queries on success:
 * - Payment details
 * - Invoice details (for all allocated invoices)
 * - Partner balance
 *
 * @example
 * const applyMutation = useApplyAllocation()
 *
 * applyMutation.mutate({
 *   payment_id: '456',
 *   allocation_method: 'fifo',
 * }, {
 *   onSuccess: (result) => {
 *     toast.success('Payment allocated successfully')
 *     console.log('Allocated to', result.allocations.length, 'invoices')
 *   },
 *   onError: (error) => {
 *     toast.error(error.message)
 *   },
 * })
 */
export function useApplyAllocation() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (request: ApplyAllocationRequest) => applyPaymentAllocation(request),
    onSuccess: async (result) => {
      // Invalidate payment queries
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('payments', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey(['payment', result.payment_id]),
        }),
        ...result.allocations.map((allocation) =>
          queryClient.invalidateQueries({
            queryKey: tenantScopedKey(['invoice', allocation.document_id]),
          }),
        ),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('partner-balance', tenantId, companyId),
        }),
      ])
      toast.success('Payment allocated successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
