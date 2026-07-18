import { apiGet } from '@/lib/api'
import type { LocationType } from '../types'

export interface ScopedLocation {
  id: string
  name: string
  code: string
  type: LocationType
  isDefault: boolean
}

interface RawScopedLocation {
  id: string
  name: string
  code: string
  type: LocationType
  is_default: boolean
}

export async function getScopedLocations(): Promise<ScopedLocation[]> {
  const rows = await apiGet<RawScopedLocation[]>('/company/locations')
  return rows.map((row) => ({
    id: row.id,
    name: row.name,
    code: row.code,
    type: row.type,
    isDefault: row.is_default,
  }))
}
