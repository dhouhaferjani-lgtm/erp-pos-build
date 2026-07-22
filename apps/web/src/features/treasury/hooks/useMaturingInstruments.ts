import { useQuery } from '@tanstack/react-query'

import { api } from '@/lib/api'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

export type MaturityBucketKey = keyof App.Modules.Treasury.Application.DTOs.MaturingInstrumentBucketsData
export type MaturityBucketTotal = App.Modules.Treasury.Application.DTOs.MaturingInstrumentBucketData
export type MaturingInstrumentRow = App.Modules.Treasury.Application.DTOs.MaturingInstrumentRowData
export type MaturingInstrumentsResponse = App.Modules.Treasury.Application.DTOs.MaturingInstrumentsData

export function useMaturingInstruments(from: string, to: string) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { scope, effectiveLocationIds } = useViewScope()

  return useQuery({
    queryKey: locationScopedKey(['maturing-instruments', { from, to }], scope),
    queryFn: async () => {
      const params = new URLSearchParams({ from, to })
      effectiveLocationIds.forEach((id) => params.append('location_ids[]', id))
      const response = await api.get<MaturingInstrumentsResponse>(`/treasury/maturing-instruments?${params.toString()}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && from !== '' && to !== '',
  })
}
