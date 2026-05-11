import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { getCriteriaMetadata } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import { usePartsCatalogTenantScope } from './usePartsCatalogTenantScope'
import type { CriteriaMetadata } from '../types/catalog'

const TWENTY_FOUR_HOURS = 1000 * 60 * 60 * 24

export function useCriteriaMetadata(): UseQueryResult<CriteriaMetadata[]> {
  const hasTenantScope = usePartsCatalogTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...partsCatalogKeys.criteriaMetadata()]),
    queryFn: getCriteriaMetadata,
    enabled: hasTenantScope,
    staleTime: TWENTY_FOUR_HOURS,
  })
}
