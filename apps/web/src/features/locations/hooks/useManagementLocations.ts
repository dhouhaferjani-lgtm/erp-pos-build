import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { usePermissions } from '@/hooks/usePermissions'
import type { ScopedLocation } from '../api/scopedLocations'
import type { LocationType } from '../types'

interface RawManagementLocation {
  id: string
  name: string
  code: string | null
  type: LocationType
  is_default: boolean
  is_active: boolean
}

export function useManagementLocations(): UseQueryResult<ScopedLocation[]> {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { hasPermission } = usePermissions()

  return useQuery({
    queryKey: tenantScopedKey(['company-locations', 'all']),
    queryFn: async () => {
      const rows = await apiGet<RawManagementLocation[]>('/company/locations/all')
      return rows.map((row) => ({
        id: row.id,
        name: row.name,
        code: row.code ?? '',
        type: row.type,
        isDefault: row.is_default,
        isActive: row.is_active,
      }))
    },
    enabled: Boolean(tenantId && companyId && hasPermission('users.manage_location_access')),
    staleTime: 300_000,
  })
}
