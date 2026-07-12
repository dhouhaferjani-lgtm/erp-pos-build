import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import {
  getKitchenOrders,
  updateLineStatus,
  bumpOrder,
  markOrderServed,
  type KitchenLineUpdateResponse,
} from '../api/kitchenApi'
import type { OrderData } from '../api/orderApi'
import { scopedKeyPredicate, usePosTenantScope } from './usePosTenantScope'

export const kitchenKeys = {
  all: ['kitchen'] as const,
  orders: () => [...kitchenKeys.all, 'orders'] as const,
}

/**
 * Fetch active kitchen orders with 30s fallback polling.
 * Primary updates come via WebSocket (useKitchenChannel).
 */
export function useKitchenOrders() {
  const { hasTenantScope } = usePosTenantScope()

  return useQuery<OrderData[]>({
    queryKey: tenantScopedKey([...kitchenKeys.orders()]),
    queryFn: getKitchenOrders,
    enabled: hasTenantScope,
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
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...kitchenKeys.orders()] })
    },
  })
}

export function useBumpOrder() {
  const queryClient = useQueryClient()

  return useMutation<OrderData, Error, string>({
    mutationFn: bumpOrder,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...kitchenKeys.orders()] })
    },
  })
}

export function useMarkOrderServed() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<OrderData, Error, string>({
    mutationFn: markOrderServed,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...kitchenKeys.orders()] }),
        queryClient.invalidateQueries({
          predicate: scopedKeyPredicate('orders', tenantId, companyId),
        }),
      ])
    },
  })
}
