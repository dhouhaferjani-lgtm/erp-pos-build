import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { OffsetPaginationMeta } from '@/types/pagination'

/**
 * A single recorded back-office customer-account deposit, as returned by
 * `GET /partners/{partner}/deposits`.
 */
export interface PartnerDeposit {
  fiscal_event_id: string
  deposit_receipt_uuid: string
  customer_id: string
  customer_name: string
  amount: string
  currency_code: string
  payment_method_code: string
  actor_name: string
  note: string
  recorded_at: string
}

interface PartnerDepositsResponse {
  data: PartnerDeposit[]
  meta: OffsetPaginationMeta
}

/**
 * Newest-first deposit history for a customer partner.
 */
export function usePartnerDeposits(partnerId: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['partner-deposits', partnerId]),
    queryFn: async () => {
      const response = await api.get<PartnerDepositsResponse>(`/partners/${partnerId}/deposits`)
      return response.data.data
    },
    enabled: partnerId.length > 0 && tenantId !== null && companyId !== null,
  })
}
