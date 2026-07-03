import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'

interface EarnRateResponse {
  rate: string | null
}

/**
 * Fetches the active global loyalty earn rate from the backend.
 * Returns `rate: null` when the tenant has no active earn-rate rule.
 *
 * `apiGet` already unwraps `response.data.data`, so the returned value is the
 * `EarnRateResponse` object directly — do NOT double-unwrap.
 */
export function useLoyaltyEarnRate(enabled: boolean = true): {
  rate: string | null
  isLoading: boolean
} {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['loyalty', 'earn-rate']),
    queryFn: () => apiGet<EarnRateResponse>('/loyalty/earn-rate'),
    enabled: enabled && tenantId !== null && companyId !== null,
    staleTime: 5 * 60 * 1000,
  })
  return { rate: data?.rate ?? null, isLoading }
}
