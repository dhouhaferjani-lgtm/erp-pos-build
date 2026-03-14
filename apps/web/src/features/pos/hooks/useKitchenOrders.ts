import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getKitchenOrders,
  updateLineStatus,
  bumpOrder,
  markOrderServed,
  type KitchenLineUpdateResponse,
} from '../api/kitchenApi'
import type { OrderData } from '../api/orderApi'

export const kitchenKeys = {
  all: ['kitchen'] as const,
  orders: () => [...kitchenKeys.all, 'orders'] as const,
}

/**
 * Fetch active kitchen orders with 30s fallback polling.
 * Primary updates come via WebSocket (useKitchenChannel).
 */
export function useKitchenOrders() {
  return useQuery<OrderData[]>({
    queryKey: kitchenKeys.orders(),
    queryFn: getKitchenOrders,
    refetchInterval: 30000, // 30s safety net polling
  })
}

export function useUpdateLineStatus() {
  const queryClient = useQueryClient()

  return useMutation<
    KitchenLineUpdateResponse,
    Error,
    { orderId: string; lineId: string; status: string }
  >({
    mutationFn: ({ orderId, lineId, status }) =>
      updateLineStatus(orderId, lineId, status),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: kitchenKeys.orders() })
    },
  })
}

export function useBumpOrder() {
  const queryClient = useQueryClient()

  return useMutation<OrderData, Error, string>({
    mutationFn: bumpOrder,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: kitchenKeys.orders() })
    },
  })
}

export function useMarkOrderServed() {
  const queryClient = useQueryClient()

  return useMutation<OrderData, Error, string>({
    mutationFn: markOrderServed,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: kitchenKeys.orders() })
      void queryClient.invalidateQueries({ queryKey: ['orders'] })
    },
  })
}
