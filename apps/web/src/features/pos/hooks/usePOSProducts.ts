import { useQuery } from '@tanstack/react-query'
import { fetchPOSProducts, type GetPOSProductsParams } from '../api/productApi'

export const posProductKeys = {
  all: ['pos', 'products'] as const,
  lists: () => [...posProductKeys.all, 'list'] as const,
  list: (params?: GetPOSProductsParams) => [...posProductKeys.lists(), params] as const,
}

export function usePOSProducts(params?: GetPOSProductsParams) {
  return useQuery({
    queryKey: posProductKeys.list(params),
    queryFn: () => fetchPOSProducts(params),
    staleTime: 60000, // 1 minute
  })
}
