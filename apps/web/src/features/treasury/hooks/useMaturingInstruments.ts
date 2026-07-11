import { useQuery } from '@tanstack/react-query'

import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

export type MaturityBucketKey = 'overdue' | 'd0_7' | 'd8_30' | 'd31_60' | 'd61_90' | 'd90_plus'

export interface MaturityBucketTotal {
  count: number
  total_in: string
  total_out: string
}

export interface MaturingInstrumentRow {
  id: string
  reference: string
  amount: string
  currency: string
  maturity_date: string | null
  received_date: string
  status: 'received' | 'deposited'
  direction: 'inbound' | 'outbound'
  kind: 'cheque' | 'effet' | 'other' | null
  certainty: 'portfolio' | 'remitted'
  bucket: MaturityBucketKey
}

export interface MaturingInstrumentsResponse {
  data: MaturingInstrumentRow[]
  meta: {
    buckets: Record<MaturityBucketKey, MaturityBucketTotal>
    grand_total: MaturityBucketTotal
  }
}

export function useMaturingInstruments(from: string, to: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['maturing-instruments', { from, to }]),
    queryFn: async () => {
      const params = new URLSearchParams({ from, to })
      const response = await api.get<MaturingInstrumentsResponse>(`/treasury/maturing-instruments?${params.toString()}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && from !== '' && to !== '',
  })
}
