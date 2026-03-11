import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getSupplierBrands } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import type { Supplier } from '../types/catalog'

const TWENTY_FOUR_HOURS = 1000 * 60 * 60 * 24

export function useSupplierBrands(): UseQueryResult<Supplier[]> {
  return useQuery({
    queryKey: partsCatalogKeys.supplierBrands(),
    queryFn: getSupplierBrands,
    staleTime: TWENTY_FOUR_HOURS,
  })
}
