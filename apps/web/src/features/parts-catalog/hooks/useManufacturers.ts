import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getManufacturers } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import type { Manufacturer } from '../types/catalog'

const TWENTY_FOUR_HOURS = 1000 * 60 * 60 * 24

export function useManufacturers(): UseQueryResult<Manufacturer[]> {
  return useQuery({
    queryKey: partsCatalogKeys.manufacturers(),
    queryFn: getManufacturers,
    staleTime: TWENTY_FOUR_HOURS,
  })
}
