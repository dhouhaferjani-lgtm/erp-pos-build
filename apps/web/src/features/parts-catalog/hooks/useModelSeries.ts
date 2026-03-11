import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getModelSeries } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import type { ModelSeries } from '../types/catalog'

const TWENTY_FOUR_HOURS = 1000 * 60 * 60 * 24

export function useModelSeries(manufacturerId: string): UseQueryResult<ModelSeries[]> {
  return useQuery({
    queryKey: partsCatalogKeys.modelSeries(manufacturerId),
    queryFn: () => getModelSeries(manufacturerId),
    enabled: Boolean(manufacturerId),
    staleTime: TWENTY_FOUR_HOURS,
  })
}
