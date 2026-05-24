import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import {
  getOrders,
  getOrder,
  createOrder,
  addOrderLine,
  modifyOrderLine,
  removeOrderLine,
  sendToKitchen,
  cancelOrder,
  type OrderListParams,
  type CreateOrderRequest,
  type AddOrderLineRequest,
  type ModifyOrderLineRequest,
  type OrderData,
  type OrderListResponse,
  type AddLineResponse,
  type ModifyLineResponse,
} from '../api/orderApi'
// §14.2 — `closeOrder` import removed: the order-close → SALE_RECEIPT
// path is retired (the backend route returns HTTP 410). Use `cancelOrder`
// for non-receipt order termination.
import { usePosTenantScope } from './usePosTenantScope'

/**
 * Query key factory for order-related queries.
 */
export const orderKeys = {
  all: ['orders'] as const,
  lists: () => [...orderKeys.all, 'list'] as const,
  list: (params?: OrderListParams) => [...orderKeys.lists(), params] as const,
  details: () => [...orderKeys.all, 'detail'] as const,
  detail: (id: string) => [...orderKeys.details(), id] as const,
}

function scopedOrderListPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'orders' &&
      k[1] === 'list' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

/**
 * Fetch orders with optional filters.
 */
export function useOrders(params?: OrderListParams) {
  const { hasTenantScope } = usePosTenantScope()

  return useQuery<OrderListResponse>({
    queryKey: tenantScopedKey([...orderKeys.list(params)]),
    queryFn: () => getOrders(params),
    enabled: hasTenantScope,
    refetchInterval: 10000, // Poll every 10s for active orders
  })
}

/**
 * Fetch a single order by ID.
 */
export function useOrder(id: string | undefined) {
  const { hasTenantScope } = usePosTenantScope()

  return useQuery<OrderData>({
    queryKey: tenantScopedKey([...orderKeys.detail(id!)]),
    queryFn: () => getOrder(id!),
    enabled: !!id && hasTenantScope,
  })
}

/**
 * Create a new order.
 */
export function useCreateOrder() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<OrderData, Error, CreateOrderRequest>({
    mutationFn: (data) => createOrder(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedOrderListPredicate(tenantId, companyId),
      })
    },
  })
}

/**
 * Add a line to an order.
 */
export function useAddOrderLine() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<
    AddLineResponse,
    Error,
    { orderId: string; data: AddOrderLineRequest }
  >({
    mutationFn: ({ orderId, data }) => addOrderLine(orderId, data),
    onSuccess: async (_data, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...orderKeys.detail(variables.orderId)]),
        }),
        queryClient.invalidateQueries({
          predicate: scopedOrderListPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

/**
 * Modify a line on an order.
 */
export function useModifyOrderLine() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<
    ModifyLineResponse,
    Error,
    { orderId: string; lineId: string; data: ModifyOrderLineRequest }
  >({
    mutationFn: ({ orderId, lineId, data }) =>
      modifyOrderLine(orderId, lineId, data),
    onSuccess: async (_data, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...orderKeys.detail(variables.orderId)]),
        }),
        queryClient.invalidateQueries({
          predicate: scopedOrderListPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

/**
 * Remove a line from an order.
 */
export function useRemoveOrderLine() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<OrderData, Error, { orderId: string; lineId: string }>({
    mutationFn: ({ orderId, lineId }) => removeOrderLine(orderId, lineId),
    onSuccess: async (_data, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...orderKeys.detail(variables.orderId)]),
        }),
        queryClient.invalidateQueries({
          predicate: scopedOrderListPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

/**
 * Send an order to the kitchen.
 */
export function useSendToKitchen() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<OrderData, Error, string>({
    mutationFn: (orderId) => sendToKitchen(orderId),
    onSuccess: async (_data, orderId) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...orderKeys.detail(orderId)]),
        }),
        queryClient.invalidateQueries({
          predicate: scopedOrderListPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

// §14.2 — `useCloseOrder` hook removed as part of the new-sale
// server-authoring disposition. The order-close → SALE_RECEIPT path is
// retired; the backend route returns HTTP 410. Use `useCancelOrder` for
// non-receipt order termination.

/**
 * Cancel an order.
 */
export function useCancelOrder() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = usePosTenantScope()

  return useMutation<
    OrderData,
    Error,
    { orderId: string; reason?: string }
  >({
    mutationFn: ({ orderId, reason }) => cancelOrder(orderId, reason),
    onSuccess: async (_data, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...orderKeys.detail(variables.orderId)]),
        }),
        queryClient.invalidateQueries({
          predicate: scopedOrderListPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

export type {
  OrderData,
  OrderListResponse,
  OrderListParams,
  CreateOrderRequest,
  AddOrderLineRequest,
  ModifyOrderLineRequest,
}
