import { useEffect } from 'react'
import { useViewScopeStore } from '@/stores/viewScopeStore'
import { useScopedLocations } from './useScopedLocations'

export function useViewScope() {
  const scope = useViewScopeStore((state) => state.scope)
  const setScope = useViewScopeStore((state) => state.setScope)
  const query = useScopedLocations()
  const locations = Array.isArray(query.data) ? query.data : []
  const allowedIds = locations.map((location) => location.id)
  const isAll = scope === 'all'
  const effectiveLocationIds = isAll
    ? allowedIds
    : scope.filter((locationId) => allowedIds.includes(locationId))

  useEffect(() => {
    if (query.isLoading || scope === 'all' || effectiveLocationIds.length === scope.length) return

    // A persisted subset may contain locations removed by a later admin grant.
    // Clamp it before any scoped request can send stale ids and receive 403.
    setScope(effectiveLocationIds.length === 0 || effectiveLocationIds.length === allowedIds.length
      ? 'all'
      : effectiveLocationIds)
  }, [allowedIds, effectiveLocationIds, query.isLoading, scope, setScope])

  return { scope, effectiveLocationIds, isAll, setScope }
}
