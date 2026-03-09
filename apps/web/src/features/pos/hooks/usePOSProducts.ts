import { useQuery } from '@tanstack/react-query'
import { fetchPOSProducts, type GetPOSProductsParams } from '../api/productApi'

export const posProductKeys = {
  all: ['pos', 'products'] as const,
  lists: () => [...posProductKeys.all, 'list'] as const,
  list: (params?: GetPOSProductsParams) => [...posProductKeys.lists(), params] as const,
}

export function usePOSProducts(params?: GetPOSProductsParams & { enabled?: boolean }) {
  const { enabled, ...queryParams } = params ?? {}
  return useQuery({
    queryKey: posProductKeys.list(Object.keys(queryParams).length > 0 ? queryParams : undefined),
    queryFn: () => fetchPOSProducts(Object.keys(queryParams).length > 0 ? queryParams : undefined),
    staleTime: 60000, // 1 minute
    enabled: enabled ?? true,
  })
}
