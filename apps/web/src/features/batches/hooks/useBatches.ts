import { useQuery, useMutation, useQueryClient, type UseQueryResult } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/api'
import {
  getBatches,
  getBatch,
  createBatch,
  updateBatch,
  deleteBatch,
  recallBatch,
  getExpiringProducts,
  getBatchStock,
  getFEFOSuggestions,
  getProductBatches,
} from '../api/batches'
import type {
  Batch,
  GetBatchesParams,
  PaginatedBatchesResponse,
  CreateBatchInput,
  UpdateBatchInput,
  RecallBatchInput,
  ExpiringProduct,
  BatchStockByLocation,
  FEFOResult,
} from '../types'

/**
 * Query key factory for batches
 */
export const batchKeys = {
  all: ['batches'] as const,
  lists: () => [...batchKeys.all, 'list'] as const,
  list: (params?: GetBatchesParams) => [...batchKeys.lists(), params] as const,
  details: () => [...batchKeys.all, 'detail'] as const,
  detail: (uuid: string) => [...batchKeys.details(), uuid] as const,
  expiring: (days: number) => [...batchKeys.all, 'expiring', days] as const,
  stock: (uuid: string) => [...batchKeys.all, 'stock', uuid] as const,
  fefo: (productId: string, locationId: number, quantity: number) =>
    [...batchKeys.all, 'fefo', productId, locationId, quantity] as const,
  productBatches: (productId: string) => [...batchKeys.all, 'product', productId] as const,
}

/**
 * Hook to fetch paginated list of batches
 */
export function useBatches(params?: GetBatchesParams): UseQueryResult<PaginatedBatchesResponse> {
  return useQuery({
    queryKey: batchKeys.list(params),
    queryFn: () => getBatches(params),
    staleTime: 30000, // Consider data fresh for 30 seconds (batches change frequently)
  })
}

/**
 * Hook to fetch a single batch by UUID
 */
export function useBatch(uuid: string): UseQueryResult<Batch> {
  return useQuery({
    queryKey: batchKeys.detail(uuid),
    queryFn: () => getBatch(uuid),
    enabled: Boolean(uuid),
  })
}

/**
 * Hook to fetch products expiring soon
 */
export function useExpiringProducts(daysThreshold: number = 30): UseQueryResult<ExpiringProduct[]> {
  return useQuery({
    queryKey: batchKeys.expiring(daysThreshold),
    queryFn: () => getExpiringProducts(daysThreshold),
    staleTime: 60000, // 1 minute stale time for dashboard widgets
  })
}

/**
 * Hook to fetch batch stock levels
 */
export function useBatchStock(uuid: string): UseQueryResult<BatchStockByLocation[]> {
  return useQuery({
    queryKey: batchKeys.stock(uuid),
    queryFn: () => getBatchStock(uuid),
    enabled: Boolean(uuid),
  })
}

/**
 * Hook to fetch FEFO suggestions for POS
 */
export function useFEFOSuggestions(
  productId: string,
  locationId: number,
  quantity: number,
  enabled: boolean = true
): UseQueryResult<FEFOResult> {
  return useQuery({
    queryKey: batchKeys.fefo(productId, locationId, quantity),
    queryFn: () => getFEFOSuggestions(productId, locationId, quantity),
    enabled: enabled && Boolean(productId) && Boolean(locationId) && quantity > 0,
    staleTime: 10000, // 10 seconds for POS suggestions
  })
}

/**
 * Hook to fetch all batches for a product
 */
export function useProductBatches(productId: string): UseQueryResult<Batch[]> {
  return useQuery({
    queryKey: batchKeys.productBatches(productId),
    queryFn: () => getProductBatches(productId),
    enabled: Boolean(productId),
  })
}

/**
 * Hook to create a new batch
 */
export function useCreateBatch() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateBatchInput) => createBatch(input),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: batchKeys.all })
      void queryClient.invalidateQueries({ queryKey: ['products', 'detail', data.product_id] })
      toast.success('Batch created successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Hook to update an existing batch
 */
export function useUpdateBatch() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ uuid, input }: { uuid: string; input: UpdateBatchInput }) =>
      updateBatch(uuid, input),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: batchKeys.all })
      void queryClient.invalidateQueries({ queryKey: batchKeys.detail(data.uuid) })
      void queryClient.invalidateQueries({ queryKey: ['products', 'detail', data.product_id] })
      toast.success('Batch updated successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Hook to deactivate a batch
 */
export function useDeleteBatch() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (uuid: string) => deleteBatch(uuid),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: batchKeys.all })
      toast.success('Batch deactivated successfully')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Hook to initiate a batch recall
 */
export function useRecallBatch() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ uuid, input }: { uuid: string; input: RecallBatchInput }) =>
      recallBatch(uuid, input),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: batchKeys.all })
      void queryClient.invalidateQueries({ queryKey: batchKeys.detail(data.uuid) })
      void queryClient.invalidateQueries({ queryKey: ['products', 'detail', data.product_id] })
      toast.warning('Batch recall initiated')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
