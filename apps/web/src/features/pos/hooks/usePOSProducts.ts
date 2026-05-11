import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { fetchPOSProducts, type GetPOSProductsParams } from '../api/productApi'
import { usePosTenantScope } from './usePosTenantScope'

export const posProductKeys = {
  all: ['pos', 'products'] as const,
  lists: () => [...posProductKeys.all, 'list'] as const,
  list: (params?: GetPOSProductsParams) => [...posProductKeys.lists(), params] as const,
}

export function usePOSProducts(params?: GetPOSProductsParams & { enabled?: boolean }) {
  const { hasTenantScope } = usePosTenantScope()
  const { enabled, ...queryParams } = params ?? {}
  const hasParams = Object.keys(queryParams).length > 0

  return useQuery({
    queryKey: tenantScopedKey([...posProductKeys.list(hasParams ? queryParams : undefined)]),
    queryFn: () => fetchPOSProducts(hasParams ? queryParams : undefined),
    staleTime: 60000, // 1 minute
    enabled: (enabled ?? true) && hasTenantScope,
  })
}
