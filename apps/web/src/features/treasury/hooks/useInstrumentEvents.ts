import { useQuery } from '@tanstack/react-query'

import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

export interface InstrumentEvent {
  id: string
  event_type: 'created' | 'details_updated' | 'custody_transferred' | 'remitted' | 'cleared' | 'bounced' | 're_presented' | 'cancelled'
  from_status: string | null
  to_status: string | null
  from_repository_id: string | null
  to_repository_id: string | null
  remittance_id: string | null
  journal_entry_id: string | null
  movement_id: string | null
  payload: Record<string, unknown>
  occurred_at: string
  created_by: string | null
}

interface InstrumentEventsResponse {
  data: InstrumentEvent[]
}

export function useInstrumentEvents(instrumentId: string | undefined) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['instrument-events', instrumentId]),
    queryFn: async () => {
      const response = await api.get<InstrumentEventsResponse>(`/payment-instruments/${instrumentId ?? ''}/events`)
      return response.data.data
    },
    enabled: Boolean(instrumentId) && tenantId !== null && companyId !== null,
  })
}
