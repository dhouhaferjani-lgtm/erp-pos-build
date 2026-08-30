import { api, apiGet } from '@/lib/api'
import type { Location, LocationType } from '../types'

/**
 * Raw /locations payload (snake_case, as served by the API).
 */
interface RawLocation {
  id: string
  company_id: string
  name: string
  code: string | null
  type: LocationType
  phone: string | null
  email: string | null
  address_street: string | null
  address_city: string | null
  address_postal_code: string | null
  address_country: string | null
  tax_id: string | null
  vat_number: string | null
  legal_identifiers: Record<string, unknown> | null
  is_default: boolean
  is_active: boolean
  pos_enabled: boolean
  created_at: string
  updated_at: string
}

export interface TransactionLocation {
  id: string
  name: string
  code: string
  type: LocationType
  isDefault: boolean
  isActive: boolean
}

interface RawTransactionLocation {
  id: string
  name: string
  code: string | null
  type: LocationType
  is_default: boolean
  is_active: boolean
}

function mapLocation(raw: RawLocation): Location {
  return {
    id: raw.id,
    companyId: raw.company_id,
    name: raw.name,
    code: raw.code ?? '',
    type: raw.type,
    phone: raw.phone,
    email: raw.email,
    addressStreet: raw.address_street,
    addressCity: raw.address_city,
    addressPostalCode: raw.address_postal_code,
    addressCountry: raw.address_country,
    taxId: raw.tax_id,
    vatNumber: raw.vat_number,
    legalIdentifiers: raw.legal_identifiers,
    isDefault: raw.is_default,
    isActive: raw.is_active,
    posEnabled: raw.pos_enabled,
    createdAt: raw.created_at,
    updatedAt: raw.updated_at,
  }
}

/**
 * Get all locations for the current company
 */
async function getLocationList(path: string): Promise<Location[]> {
  const raw = await apiGet<RawLocation[]>(path)
  return raw.map(mapLocation)
}

export async function getLocations(): Promise<Location[]> {
  return getLocationList('/locations')
}

export async function getTransactionLocations(): Promise<TransactionLocation[]> {
  const rows = await apiGet<RawTransactionLocation[]>('/company/locations/transaction-destinations')
  return rows.map((row) => ({
    id: row.id,
    name: row.name,
    code: row.code ?? '',
    type: row.type,
    isDefault: row.is_default,
    isActive: row.is_active,
  }))
}

/**
 * Get a single location by ID
 */
export async function getLocation(id: string): Promise<Location> {
  const response = await api.get<{ data: RawLocation }>(`/locations/${id}`)
  return mapLocation(response.data.data)
}
