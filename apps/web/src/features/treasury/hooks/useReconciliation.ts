/**
 * Bank Reconciliation React Query Hooks
 * Treasury Module - Bank Reconciliation Features
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { toast } from 'sonner'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  listReconciliations,
  getReconciliation,
  getReconciliationSummary,
  startReconciliation,
  matchItem,
  unmatchItem,
  completeReconciliation,
  cancelReconciliation,
  listRepositories,
} from '../api/reconciliation'
import { getErrorMessage } from '@/lib/api'
import type {
  StartReconciliationRequest,
  MatchItemRequest,
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
 * Query hook: List all reconciliations.
 */
export function useReconciliations(filters?: {
  repository_id?: string;
  status?: string;
}) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['reconciliations', filters]),
    queryFn: () => listReconciliations(filters),
    enabled: tenantId !== null && companyId !== null,
    staleTime: 30 * 1000, // 30 seconds
  })
}

/**
 * Query hook: Get a single reconciliation with items.
 */
export function useReconciliation(id: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['reconciliation', id]),
    queryFn: () => getReconciliation(id!),
    enabled: !!id && tenantId !== null && companyId !== null,
    staleTime: 10 * 1000, // 10 seconds
  })
}

/**
 * Query hook: Get reconciliation summary.
 */
export function useReconciliationSummary(id: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['reconciliation-summary', id]),
    queryFn: () => getReconciliationSummary(id!),
    enabled: !!id && tenantId !== null && companyId !== null,
    staleTime: 10 * 1000, // 10 seconds
  })
}

/**
 * Query hook: List payment repositories.
 */
export function usePaymentRepositories() {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['payment-repositories']),
    queryFn: () => listRepositories(),
    enabled: tenantId !== null && companyId !== null,
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

/**
 * Mutation hook: Start a new reconciliation.
 */
export function useStartReconciliation() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (request: StartReconciliationRequest) => startReconciliation(request),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('reconciliations', tenantId, companyId),
      })
      toast.success('Reconciliation started')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Mutation hook: Match an item in a reconciliation.
 */
export function useMatchItem() {
  const queryClient = useQueryClient()
  useAuthStore((state) => state.user?.tenant_id ?? null)
  useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({
      reconciliationId,
      paymentId,
      request,
    }: {
      reconciliationId: string;
      paymentId: string;
      request?: MatchItemRequest;
    }) => matchItem(reconciliationId, paymentId, request),
    onSuccess: async (_, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['reconciliation', variables.reconciliationId],
        }),
        queryClient.invalidateQueries({
          queryKey: ['reconciliation-summary', variables.reconciliationId],
        }),
      ])
      toast.success('Item matched')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Mutation hook: Unmatch an item in a reconciliation.
 */
export function useUnmatchItem() {
  const queryClient = useQueryClient()
  useAuthStore((state) => state.user?.tenant_id ?? null)
  useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({
      reconciliationId,
      paymentId,
    }: {
      reconciliationId: string;
      paymentId: string;
    }) => unmatchItem(reconciliationId, paymentId),
    onSuccess: async (_, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['reconciliation', variables.reconciliationId],
        }),
        queryClient.invalidateQueries({
          queryKey: ['reconciliation-summary', variables.reconciliationId],
        }),
      ])
      toast.success('Item unmatched')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Mutation hook: Complete a reconciliation.
 * Financial operation - uses pessimistic UI.
 */
export function useCompleteReconciliation() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (id: string) => completeReconciliation(id),
    onSuccess: async (_, id) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('reconciliations', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: ['reconciliation', id] }),
        queryClient.invalidateQueries({ queryKey: ['reconciliation-summary', id] }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('payment-repositories', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('payments', tenantId, companyId),
        }),
      ])
      toast.success('Reconciliation completed')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Mutation hook: Cancel a reconciliation.
 */
export function useCancelReconciliation() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (id: string) => cancelReconciliation(id),
    onSuccess: async (_, id) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('reconciliations', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: ['reconciliation', id] }),
        queryClient.invalidateQueries({ queryKey: ['reconciliation-summary', id] }),
      ])
      toast.success('Reconciliation cancelled')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
