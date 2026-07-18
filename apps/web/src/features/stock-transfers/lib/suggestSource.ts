import { bccomp, bcsub } from '@/lib/decimal'

export interface SourceLocationAvailability {
  location_id: string
  available: string
  max_quantity: string | null
}

export interface SuggestedSource {
  locationId: string
  reason: 'surplus' | 'fallback'
}

export function suggestSource(locations: SourceLocationAvailability[], destinationLocationId: string, requestedQuantity: string): SuggestedSource | null {
  const candidates = locations.filter((location) => location.location_id !== destinationLocationId)
  const surplus = candidates.filter((location) => location.max_quantity !== null && bccomp(location.available, location.max_quantity) > 0)
  if (surplus.length > 0) {
    const best = surplus.reduce((current, candidate) => {
      const currentExcess = bcsub(current.available, current.max_quantity ?? '0', 4)
      const candidateExcess = bcsub(candidate.available, candidate.max_quantity ?? '0', 4)
      return bccomp(candidateExcess, currentExcess) > 0 ? candidate : current
    })
    return { locationId: best.location_id, reason: 'surplus' }
  }
  const fallback = candidates.filter((location) => bccomp(location.available, requestedQuantity) >= 0)
  if (fallback.length === 0) return null
  const best = fallback.reduce((current, candidate) => bccomp(candidate.available, current.available) > 0 ? candidate : current)
  return { locationId: best.location_id, reason: 'fallback' }
}
