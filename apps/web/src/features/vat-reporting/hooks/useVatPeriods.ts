import { useQuery } from '@tanstack/react-query'
import { getVatPeriods } from '../api'
import type { VatPeriodsFilters } from '../types'

export function useVatPeriods(filters?: VatPeriodsFilters) {
  return useQuery({
    queryKey: ['vat-periods', filters],
    queryFn: () => getVatPeriods(filters),
  })
}
