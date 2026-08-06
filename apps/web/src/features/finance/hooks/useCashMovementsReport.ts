import { useQuery, type UseQueryResult } from '@tanstack/react-query'

import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { api } from '@/lib/api'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { OffsetPaginationMeta } from '@/types/pagination'

export interface CashMovementsFilters {
  from?: string
  to?: string
  repository_id?: string
  direction?: 'in' | 'out'
  page: number
}

interface ScopedCashMovementsFilters extends CashMovementsFilters {
  location_ids: string[]
}

export interface CashMovementRow {
  date: string
  direction: 'in' | 'out'
  amount: string
  currency: string
  source_type: string
  source_id: string
  counterparty: string | null
  gl_account: string | null
}

export interface CashMovementsMeta extends OffsetPaginationMeta {
  totals: Record<string, { in: string; out: string; net: string }>
}

export interface CashMovementsReport {
  data: CashMovementRow[]
  meta: CashMovementsMeta
}

/**
 * W-7 finding F-3 (fix lane L3): this report is location-scoped like every
 * other financial read surface. The effective view scope is BOTH sent as
 * `location_ids[]` and baked into the query key via `locationScopedKey` —
 * sending it without re-keying would serve one branch's cash from another
 * branch's cache entry. Modelled on `useAgedReceivables` / `useCashPosition`.
 *
 * `api.get` rather than `apiGet` is deliberate: this endpoint answers
 * `{ data, meta }` and `apiGet` unwraps `data`, dropping the pagination and
 * per-currency totals in `meta`.
 */
export function useCashMovementsReport(
  filters: CashMovementsFilters,
): UseQueryResult<CashMovementsReport> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { scope, effectiveLocationIds } = useViewScope()
  const scopedFilters: ScopedCashMovementsFilters = {
    ...filters,
    location_ids: effectiveLocationIds,
  }

  return useQuery({
    queryKey: locationScopedKey(['cash-movements-report', scopedFilters], scope),
    queryFn: async () => {
      const response = await api.get<CashMovementsReport>('/reports/cash-movements', {
        params: scopedFilters,
      })

      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })
}
