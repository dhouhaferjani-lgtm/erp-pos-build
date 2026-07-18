import { useViewScopeStore } from '@/stores/viewScopeStore'
import { useScopedLocations } from './useScopedLocations'

export function useViewScope() {
  const scope = useViewScopeStore((state) => state.scope)
  const setScope = useViewScopeStore((state) => state.setScope)
  const query = useScopedLocations()
  const locations = Array.isArray(query.data) ? query.data : []
  const isAll = scope === 'all'
  const effectiveLocationIds = isAll ? locations.map((location) => location.id) : scope

  return { scope, effectiveLocationIds, isAll, setScope }
}
