/**
 * Bank Reconciliation React Query Hooks
 * Treasury Module - Bank Reconciliation Features
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
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
import type {
  StartReconciliationRequest,
  MatchItemRequest,
} from '@/types/treasury'

/**
 * Query hook: List all reconciliations.
 */
export function useReconciliations(filters?: {
  repository_id?: string;
  status?: string;
}) {
  return useQuery({
    queryKey: ['reconciliations', filters],
    queryFn: () => listReconciliations(filters),
    staleTime: 30 * 1000, // 30 seconds
  })
}

/**
 * Query hook: Get a single reconciliation with items.
 */
export function useReconciliation(id: string | undefined) {
  return useQuery({
    queryKey: ['reconciliation', id],
    queryFn: () => getReconciliation(id!),
    enabled: !!id,
    staleTime: 10 * 1000, // 10 seconds
  })
}

/**
 * Query hook: Get reconciliation summary.
 */
export function useReconciliationSummary(id: string | undefined) {
  return useQuery({
    queryKey: ['reconciliation-summary', id],
    queryFn: () => getReconciliationSummary(id!),
    enabled: !!id,
    staleTime: 10 * 1000, // 10 seconds
  })
}

/**
 * Query hook: List payment repositories.
 */
export function usePaymentRepositories() {
  return useQuery({
    queryKey: ['payment-repositories'],
    queryFn: () => listRepositories(),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

/**
 * Mutation hook: Start a new reconciliation.
 */
export function useStartReconciliation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (request: StartReconciliationRequest) => startReconciliation(request),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['reconciliations'] })
    },
  })
}

/**
 * Mutation hook: Match an item in a reconciliation.
 */
export function useMatchItem() {
  const queryClient = useQueryClient()

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
    onSuccess: (_, variables) => {
      void queryClient.invalidateQueries({
        queryKey: ['reconciliation', variables.reconciliationId],
      })
      void queryClient.invalidateQueries({
        queryKey: ['reconciliation-summary', variables.reconciliationId],
      })
    },
  })
}

/**
 * Mutation hook: Unmatch an item in a reconciliation.
 */
export function useUnmatchItem() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      reconciliationId,
      paymentId,
    }: {
      reconciliationId: string;
      paymentId: string;
    }) => unmatchItem(reconciliationId, paymentId),
    onSuccess: (_, variables) => {
      void queryClient.invalidateQueries({
        queryKey: ['reconciliation', variables.reconciliationId],
      })
      void queryClient.invalidateQueries({
        queryKey: ['reconciliation-summary', variables.reconciliationId],
      })
    },
  })
}

/**
 * Mutation hook: Complete a reconciliation.
 * Financial operation - uses pessimistic UI.
 */
export function useCompleteReconciliation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => completeReconciliation(id),
    onSuccess: (_, id) => {
      void queryClient.invalidateQueries({ queryKey: ['reconciliations'] })
      void queryClient.invalidateQueries({ queryKey: ['reconciliation', id] })
      void queryClient.invalidateQueries({ queryKey: ['reconciliation-summary', id] })
      void queryClient.invalidateQueries({ queryKey: ['payment-repositories'] })
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
    },
  })
}

/**
 * Mutation hook: Cancel a reconciliation.
 */
export function useCancelReconciliation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => cancelReconciliation(id),
    onSuccess: (_, id) => {
      void queryClient.invalidateQueries({ queryKey: ['reconciliations'] })
      void queryClient.invalidateQueries({ queryKey: ['reconciliation', id] })
      void queryClient.invalidateQueries({ queryKey: ['reconciliation-summary', id] })
    },
  })
}
