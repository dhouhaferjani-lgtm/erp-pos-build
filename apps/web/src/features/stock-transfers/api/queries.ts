import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { stockTransferApi } from './stockTransferApi'
import type {
  CreateStockTransferInput,
  StockTransferListFilters,
} from '../types'

const namespace = 'stock-transfers'

export function useStockTransferList(filters: StockTransferListFilters = {}) {
  return useQuery({
    queryKey: tenantScopedKey([namespace, 'list', filters]),
    queryFn: () => stockTransferApi.list(filters),
  })
}

export function useStockTransfer(id: string | undefined) {
  return useQuery({
    queryKey: tenantScopedKey([namespace, 'detail', id]),
    queryFn: () => {
      if (id === undefined) {
        throw new Error('Stock transfer id is required')
      }
      return stockTransferApi.show(id)
    },
    enabled: typeof id === 'string' && id.length > 0,
  })
}

export function useCreateStockTransfer() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CreateStockTransferInput) => stockTransferApi.create(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey([namespace]) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['stock-levels']) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['stock-movements']) })
    },
  })
}

export function useCompleteStockTransfer() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => stockTransferApi.complete(id),
    onSuccess: (_data, id) => {
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey([namespace]) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey([namespace, 'detail', id]) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['stock-levels']) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['stock-movements']) })
    },
  })
}

export function useCancelStockTransfer() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) =>
      stockTransferApi.cancel(id, reason),
    onSuccess: (_data, { id }) => {
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey([namespace]) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey([namespace, 'detail', id]) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['stock-levels']) })
      void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['stock-movements']) })
    },
  })
}
