import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import {
  getHeldOrders,
  holdOrder,
  recallHeldOrder,
  discardHeldOrder,
  type HoldOrderRequest,
  type HeldOrderData,
} from '../api/heldOrderApi'
import { scopedKeyPredicate, usePosTenantScope } from './usePosTenantScope'

/**
 * Query key factory for held-order-related queries.
 */
export const heldOrderKeys = {
  all: ['held-orders'] as const,
  lists: () => [...heldOrderKeys.all, 'list'] as const,
  list: (terminalId: string, shiftId?: string) =>
    [...heldOrderKeys.lists(), terminalId, shiftId] as const,
}

/**
 * Fetch held orders for a terminal, optionally filtered by shift.
 *
 * Only returns active (held, non-expired) orders.
 */
export function useHeldOrders(terminalId: string | undefined, shiftId?: string) {
  const { hasTenantScope } = usePosTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...heldOrderKeys.list(terminalId ?? '', shiftId)]),
    queryFn: () => getHeldOrders(terminalId!, shiftId),
    enabled: !!terminalId && hasTenantScope,
    refetchInterval: 30_000, // Refresh every 30 seconds to catch expiry
  })
}

/**
 * Mutation to hold the current cart as a new held order.
 *
 * Invalidates the held orders list on success.
 */
export function useHoldOrder() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation({
    mutationFn: (data: HoldOrderRequest) => holdOrder(data),
    onSuccess: async (_data: HeldOrderData) => {
      await queryClient.invalidateQueries({
        predicate: scopedKeyPredicate('held-orders', tenantId, companyId),
      })
    },
  })
}

/**
 * Mutation to recall a held order back to the cart.
 *
 * Invalidates the held orders list on success so recalled orders
 * disappear from the list immediately.
 */
export function useRecallOrder() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation({
    mutationFn: (id: string) => recallHeldOrder(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedKeyPredicate('held-orders', tenantId, companyId),
      })
    },
  })
}

/**
 * Mutation to discard (permanently delete) a held order.
 *
 * Invalidates the held orders list on success.
 */
export function useDiscardOrder() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation({
    mutationFn: (id: string) => discardHeldOrder(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedKeyPredicate('held-orders', tenantId, companyId),
      })
    },
  })
}

export type { HeldOrderData, HoldOrderRequest }
