import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getOrders,
  getOrder,
  createOrder,
  addOrderLine,
  modifyOrderLine,
  removeOrderLine,
  sendToKitchen,
  closeOrder,
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

/**
 * Fetch orders with optional filters.
 */
export function useOrders(params?: OrderListParams) {
  return useQuery<OrderListResponse>({
    queryKey: orderKeys.list(params),
    queryFn: () => getOrders(params),
    refetchInterval: 10000, // Poll every 10s for active orders
  })
}

/**
 * Fetch a single order by ID.
 */
export function useOrder(id: string | undefined) {
  return useQuery<OrderData>({
    queryKey: orderKeys.detail(id!),
    queryFn: () => getOrder(id!),
    enabled: !!id,
  })
}

/**
 * Create a new order.
 */
export function useCreateOrder() {
  const queryClient = useQueryClient()

  return useMutation<OrderData, Error, CreateOrderRequest>({
    mutationFn: (data) => createOrder(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: orderKeys.lists() })
    },
  })
}

/**
 * Add a line to an order.
 */
export function useAddOrderLine() {
  const queryClient = useQueryClient()

  return useMutation<
    AddLineResponse,
    Error,
    { orderId: string; data: AddOrderLineRequest }
  >({
    mutationFn: ({ orderId, data }) => addOrderLine(orderId, data),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({
        queryKey: orderKeys.detail(variables.orderId),
      })
      queryClient.invalidateQueries({ queryKey: orderKeys.lists() })
    },
  })
}

/**
 * Modify a line on an order.
 */
export function useModifyOrderLine() {
  const queryClient = useQueryClient()

  return useMutation<
    ModifyLineResponse,
    Error,
    { orderId: string; lineId: string; data: ModifyOrderLineRequest }
  >({
    mutationFn: ({ orderId, lineId, data }) =>
      modifyOrderLine(orderId, lineId, data),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({
        queryKey: orderKeys.detail(variables.orderId),
      })
      queryClient.invalidateQueries({ queryKey: orderKeys.lists() })
    },
  })
}

/**
 * Remove a line from an order.
 */
export function useRemoveOrderLine() {
  const queryClient = useQueryClient()

  return useMutation<OrderData, Error, { orderId: string; lineId: string }>({
    mutationFn: ({ orderId, lineId }) => removeOrderLine(orderId, lineId),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({
        queryKey: orderKeys.detail(variables.orderId),
      })
      queryClient.invalidateQueries({ queryKey: orderKeys.lists() })
    },
  })
}

/**
 * Send an order to the kitchen.
 */
export function useSendToKitchen() {
  const queryClient = useQueryClient()

  return useMutation<OrderData, Error, string>({
    mutationFn: (orderId) => sendToKitchen(orderId),
    onSuccess: (_data, orderId) => {
      queryClient.invalidateQueries({
        queryKey: orderKeys.detail(orderId),
      })
      queryClient.invalidateQueries({ queryKey: orderKeys.lists() })
    },
  })
}

/**
 * Close an order (convert to receipt).
 */
export function useCloseOrder() {
  const queryClient = useQueryClient()

  return useMutation<OrderData, Error, string>({
    mutationFn: (orderId) => closeOrder(orderId),
    onSuccess: (_data, orderId) => {
      queryClient.invalidateQueries({
        queryKey: orderKeys.detail(orderId),
      })
      queryClient.invalidateQueries({ queryKey: orderKeys.lists() })
    },
  })
}

/**
 * Cancel an order.
 */
export function useCancelOrder() {
  const queryClient = useQueryClient()

  return useMutation<
    OrderData,
    Error,
    { orderId: string; reason?: string }
  >({
    mutationFn: ({ orderId, reason }) => cancelOrder(orderId, reason),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({
        queryKey: orderKeys.detail(variables.orderId),
      })
      queryClient.invalidateQueries({ queryKey: orderKeys.lists() })
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
