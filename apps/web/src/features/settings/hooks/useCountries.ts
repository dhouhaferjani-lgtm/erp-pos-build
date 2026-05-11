import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getCountries, getCountry } from '../api/country'
import type { CountryFilters } from '../types/country'

function useSettingsTenantScope(): boolean {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return tenantId !== null && companyId !== null
}

export function useCountries(filters?: CountryFilters) {
  const hasTenantScope = useSettingsTenantScope()

  return useQuery({
    queryKey: tenantScopedKey(['countries', filters]),
    queryFn: () => getCountries(filters),
    enabled: hasTenantScope,
  })
}

export function useCountry(code: string) {
  const hasTenantScope = useSettingsTenantScope()

  return useQuery({
    queryKey: tenantScopedKey(['country', code]),
    queryFn: () => getCountry(code),
    enabled: !!code && hasTenantScope,
  })
}
