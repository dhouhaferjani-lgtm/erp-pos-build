import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getCriteriaMetadata } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import type { CriteriaMetadata } from '../types/catalog'

const TWENTY_FOUR_HOURS = 1000 * 60 * 60 * 24

export function useCriteriaMetadata(): UseQueryResult<CriteriaMetadata[]> {
  return useQuery({
    queryKey: partsCatalogKeys.criteriaMetadata(),
    queryFn: getCriteriaMetadata,
    staleTime: TWENTY_FOUR_HOURS,
  })
}
