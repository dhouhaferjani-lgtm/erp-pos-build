import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getHeldOrders,
  holdOrder,
  recallHeldOrder,
  discardHeldOrder,
  type HoldOrderRequest,
  type HeldOrderData,
} from '../api/heldOrderApi'

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
  return useQuery({
    queryKey: heldOrderKeys.list(terminalId ?? '', shiftId),
    queryFn: () => getHeldOrders(terminalId!, shiftId),
    enabled: !!terminalId,
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

  return useMutation({
    mutationFn: (data: HoldOrderRequest) => holdOrder(data),
    onSuccess: (_data: HeldOrderData) => {
      queryClient.invalidateQueries({ queryKey: heldOrderKeys.lists() })
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

  return useMutation({
    mutationFn: (id: string) => recallHeldOrder(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: heldOrderKeys.lists() })
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

  return useMutation({
    mutationFn: (id: string) => discardHeldOrder(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: heldOrderKeys.lists() })
    },
  })
}

export type { HeldOrderData, HoldOrderRequest }
