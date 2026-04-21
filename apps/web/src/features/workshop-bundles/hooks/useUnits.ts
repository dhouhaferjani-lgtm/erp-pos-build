import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'

/**
 * Minimal unit shape needed to render a <select> option in the bundle
 * authoring UI. Deliberately narrower than the full Unit DTO so this
 * hook stays local to workshop-bundles — the authoritative unit catalog
 * lives in features/uom.
 */
export interface PickerUnit {
  id: string
  code: string
  name: string
  symbol: string
}

/**
 * Fetch all active units (system + tenant-scoped). The UoM endpoint
 * `GET /api/v1/uom/units` already filters by is_active=true server-side.
 */
export function useUnits() {
  return useQuery({
    queryKey: ['uom', 'units'],
    queryFn: async () => {
      // apiGet unwraps response.data.data — the UoM controller returns
      // `{ data: Unit[], meta: {...} }` so this yields Unit[] directly.
      const units = await apiGet<PickerUnit[]>('/uom/units')
      return units
    },
    // Units are effectively static per tenant; cache aggressively.
    staleTime: 5 * 60 * 1000,
  })
}
