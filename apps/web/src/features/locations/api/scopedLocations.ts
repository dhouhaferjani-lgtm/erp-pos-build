import { apiGet } from '@/lib/api'
import type { LocationType } from '../types'

export interface ScopedLocation {
  id: string
  name: string
  code: string
  type: LocationType
  isDefault: boolean
  isActive: boolean
}

interface RawScopedLocation {
  id: string
  name: string
  code: string | null
  type: LocationType
  is_default: boolean
  is_active: boolean
}

export async function getScopedLocations(): Promise<ScopedLocation[]> {
  const rows = await apiGet<RawScopedLocation[]>('/company/locations')
  return rows.map((row) => ({
    id: row.id,
    name: row.name,
    code: row.code ?? '',
    type: row.type,
    isDefault: row.is_default,
    isActive: row.is_active,
  }))
}
