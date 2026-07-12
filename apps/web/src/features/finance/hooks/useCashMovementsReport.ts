import { useQuery, type UseQueryResult } from '@tanstack/react-query'

import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

export interface CashMovementsFilters {
  from?: string
  to?: string
  repository_id?: string
  direction?: 'in' | 'out'
  page: number
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

export interface CashMovementsMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
  from: number | null
  to: number | null
  totals: Record<string, { in: string; out: string; net: string }>
}

export interface CashMovementsReport {
  data: CashMovementRow[]
  meta: CashMovementsMeta
}

export function useCashMovementsReport(
  filters: CashMovementsFilters,
): UseQueryResult<CashMovementsReport> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['cash-movements-report', filters]),
    queryFn: async () => {
      const response = await api.get<CashMovementsReport>('/reports/cash-movements', {
        params: filters,
      })

      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })
}
