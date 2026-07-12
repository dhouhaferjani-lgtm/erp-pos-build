import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

export type Bank = App.Modules.Treasury.Application.DTOs.BankData

interface UseBanksOptions {
  country: string
  query: string
  enabled?: boolean
}

export function useBanks({ country, query, enabled = true }: UseBanksOptions) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const normalizedCountry = country.trim().toUpperCase()
  const normalizedQuery = query.trim()

  return useQuery({
    queryKey: tenantScopedKey(['banks', normalizedCountry, normalizedQuery] as const),
    queryFn: () => apiGet<Bank[]>('/banks', {
      country: normalizedCountry,
      ...(normalizedQuery !== '' ? { q: normalizedQuery } : {}),
    }),
    enabled: enabled && normalizedCountry.length === 2 && tenantId !== null && companyId !== null,
    staleTime: 5 * 60 * 1000,
  })
}
