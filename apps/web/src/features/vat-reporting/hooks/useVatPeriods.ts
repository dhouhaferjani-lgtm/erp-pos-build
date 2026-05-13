import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getVatPeriods } from '../api'
import type { VatPeriodsFilters } from '../types'

export function useVatPeriods(filters?: VatPeriodsFilters) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['vat-periods', filters]),
    queryFn: () => getVatPeriods(filters),
    enabled: tenantId !== null && companyId !== null,
  })
}
