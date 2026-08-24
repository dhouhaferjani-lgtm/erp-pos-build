import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getVatPeriod, getVatPeriods } from '../api'
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

/**
 * The period header for a single period.
 *
 * N-4: `GET /vat/reports/{id}/summary` carries the amounts only; the label and
 * status the report screen shows in its header live on this endpoint.
 */
export function useVatPeriod(periodId: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['vat-period', periodId]),
    queryFn: () => getVatPeriod(periodId ?? ''),
    enabled: periodId !== undefined && tenantId !== null && companyId !== null,
  })
}
