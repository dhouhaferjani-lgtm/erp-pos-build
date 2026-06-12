import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchAggregateChannelOrders } from '../api'
import type { AggregateChannelOrdersParams } from '../types'

/**
 * Aggregate channel orders across all of the company's channels.
 *
 * The params object is part of the queryKey so status/channel/page
 * changes fetch (and cache) independently; tenantScopedKey appends
 * the tenant + company suffix so cache entries invalidate on switch.
 */
export function useAggregateChannelOrders(params: AggregateChannelOrdersParams = {}) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['channels', 'orders', 'aggregate', params]),
    queryFn: () => fetchAggregateChannelOrders(params),
    enabled: tenantId !== null && companyId !== null,
  })
}
