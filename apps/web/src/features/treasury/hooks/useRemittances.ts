import { useQuery } from '@tanstack/react-query'

import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { OffsetPaginationMeta } from '@/types/pagination'

export interface RemittanceRepository {
  id: string
  code: string
  name: string
  type?: string
  bank_name?: string | null
  account_number?: string | null
  iban?: string | null
}

export interface RemittanceInstrument {
  id: string
  reference: string
  amount: string
  currency: string
  status: string
  drawer_name?: string | null
  bank_name?: string | null
  maturity_date?: string | null
}

export interface RemittanceLine {
  id: string
  remittance_id: string
  instrument_id: string
  amount: string
  line_status: 'pending' | 'cleared' | 'bounced'
  cleared_at: string | null
  bounced_at: string | null
  instrument: RemittanceInstrument
}

export interface Remittance {
  id: string
  number: string
  remittance_type: 'collection' | 'discount'
  instrument_kind: 'cheque' | 'effet'
  bank_repository_id: string
  bank_repository: RemittanceRepository
  status: 'draft' | 'remitted' | 'closed'
  remitted_at: string | null
  journal_entry_id: string | null
  lines: RemittanceLine[]
  created_at: string | null
}

interface RemittanceListResponse {
  data: Remittance[]
  meta: OffsetPaginationMeta
}

function useScope() {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  return { companyId, enabled: tenantId !== null && companyId !== null, tenantId }
}

export function useRemittances(filters: { status: string; kind: string; page: number; perPage: number }) {
  const scope = useScope()
  return useQuery({
    queryKey: tenantScopedKey(['remittances', filters]),
    queryFn: async () => {
      const params = new URLSearchParams({ page: String(filters.page), per_page: String(filters.perPage) })
      if (filters.status) params.set('status', filters.status)
      if (filters.kind) params.set('instrument_kind', filters.kind)
      const response = await api.get<RemittanceListResponse>(`/instrument-remittances?${params.toString()}`)
      return response.data
    },
    enabled: scope.enabled,
  })
}

export function useRemittance(id: string | undefined) {
  const scope = useScope()
  const remittanceId = id ?? ''
  return useQuery({
    queryKey: tenantScopedKey(['remittance', remittanceId]),
    queryFn: async () => {
      const response = await api.get<{ data: Remittance }>(`/instrument-remittances/${remittanceId}`)
      return response.data.data
    },
    enabled: scope.enabled && remittanceId !== '',
  })
}

export function useRemittanceRepositories() {
  const scope = useScope()
  return useQuery({
    queryKey: tenantScopedKey(['repositories', 'remittance-bank-options']),
    queryFn: async () => {
      const response = await api.get<{ data: RemittanceRepository[] }>('/payment-repositories')
      return response.data.data.filter((repository) => repository.type === 'bank_account')
    },
    enabled: scope.enabled,
  })
}

export function useEligibleInstruments(kind: string) {
  const scope = useScope()
  return useQuery({
    queryKey: tenantScopedKey(['remittance-eligible-instruments', kind]),
    queryFn: async () => {
      const params = new URLSearchParams({ direction: 'inbound', kind, per_page: '100', status: 'received' })
      const response = await api.get<{ data: RemittanceInstrument[] }>(`/payment-instruments?${params.toString()}`)
      return response.data.data
    },
    enabled: scope.enabled && kind !== '',
  })
}
