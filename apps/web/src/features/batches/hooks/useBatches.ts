import { useQuery, useMutation, useQueryClient, type UseQueryResult } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
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
  productBatches: (productId: string, variantId: string | null = null) =>
    [...batchKeys.all, 'product', productId, variantId] as const,
}

function scopedBatchCollectionsPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k[0] === 'batches' &&
      k[1] !== 'detail' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

/**
 * Hook to fetch paginated list of batches
 */
export function useBatches(params?: GetBatchesParams): UseQueryResult<PaginatedBatchesResponse> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(batchKeys.list(params)),
    queryFn: () => getBatches(params),
    enabled: tenantId !== null && companyId !== null,
    staleTime: 30000, // Consider data fresh for 30 seconds (batches change frequently)
  })
}

/**
 * Hook to fetch a single batch by UUID
 */
export function useBatch(uuid: string): UseQueryResult<Batch> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(batchKeys.detail(uuid)),
    queryFn: () => getBatch(uuid),
    enabled: Boolean(uuid) && tenantId !== null && companyId !== null,
  })
}

/**
 * Hook to fetch products expiring soon
 */
export function useExpiringProducts(daysThreshold: number = 30): UseQueryResult<ExpiringProduct[]> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(batchKeys.expiring(daysThreshold)),
    queryFn: () => getExpiringProducts(daysThreshold),
    enabled: tenantId !== null && companyId !== null,
    staleTime: 60000, // 1 minute stale time for dashboard widgets
  })
}

/**
 * Hook to fetch batch stock levels
 */
export function useBatchStock(uuid: string): UseQueryResult<BatchStockByLocation[]> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(batchKeys.stock(uuid)),
    queryFn: () => getBatchStock(uuid),
    enabled: Boolean(uuid) && tenantId !== null && companyId !== null,
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(batchKeys.fefo(productId, locationId, quantity)),
    queryFn: () => getFEFOSuggestions(productId, locationId, quantity),
    enabled: enabled && Boolean(productId) && Boolean(locationId) && quantity > 0 && tenantId !== null && companyId !== null,
    staleTime: 10000, // 10 seconds for POS suggestions
  })
}

/**
 * Hook to fetch all batches for a product
 */
export function useProductBatches(
  productId: string,
  variantId: string | null = null,
): UseQueryResult<Batch[]> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(batchKeys.productBatches(productId, variantId)),
    queryFn: () => getProductBatches(productId, variantId),
    enabled: Boolean(productId) && tenantId !== null && companyId !== null,
  })
}

/**
 * Hook to create a new batch
 */
export function useCreateBatch() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (input: CreateBatchInput) => createBatch(input),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedBatchCollectionsPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: ['products', 'detail', data.product_id],
        }),
      ])
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ uuid, input }: { uuid: string; input: UpdateBatchInput }) =>
      updateBatch(uuid, input),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedBatchCollectionsPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: [...batchKeys.detail(data.uuid)] }),
        queryClient.invalidateQueries({
          queryKey: ['products', 'detail', data.product_id],
        }),
      ])
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (uuid: string) => deleteBatch(uuid),
    onSuccess: async (_data, uuid) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedBatchCollectionsPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: [...batchKeys.detail(uuid)] }),
      ])
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
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ uuid, input }: { uuid: string; input: RecallBatchInput }) =>
      recallBatch(uuid, input),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedBatchCollectionsPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: [...batchKeys.detail(data.uuid)] }),
        queryClient.invalidateQueries({
          queryKey: ['products', 'detail', data.product_id],
        }),
      ])
      toast.warning('Batch recall initiated')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}
