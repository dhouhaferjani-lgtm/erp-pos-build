import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { getModelSeries } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import { usePartsCatalogTenantScope } from './usePartsCatalogTenantScope'
import type { ModelSeries } from '../types/catalog'

const TWENTY_FOUR_HOURS = 1000 * 60 * 60 * 24

export function useModelSeries(manufacturerId: string): UseQueryResult<ModelSeries[]> {
  const hasTenantScope = usePartsCatalogTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...partsCatalogKeys.modelSeries(manufacturerId)]),
    queryFn: () => getModelSeries(manufacturerId),
    enabled: Boolean(manufacturerId) && hasTenantScope,
    staleTime: TWENTY_FOUR_HOURS,
  })
}
