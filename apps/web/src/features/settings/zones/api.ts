import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'

/**
 * PHASE-1 SHIM over the location placement hierarchy backend
 * (feat/location-placement-hierarchy): the flat `/inventory/zones` API was
 * replaced by `/inventory/nodes` + `/inventory/locations/{id}/nodes`, and the
 * generated DTOs are now `LocationNodeDto` / `ProductPlacementDto`. This
 * module keeps the OLD function signatures so the counting wizard and the
 * legacy zones modal keep compiling and working until the Phase-2 placement
 * UI replaces them. Never hand-write these types — they re-export the
 * generated ones (`packages/shared/types/generated.d.ts`, produced by
 * `php artisan typescript:transform`).
 */
export type Zone = App.Modules.Inventory.Application.DTOs.LocationNodeDto
export type ZoneProductAssignment = App.Modules.Inventory.Application.DTOs.ProductPlacementDto

export interface CreateZoneInput {
  location_id: string
  name: string
  code: string
  sort_order?: number
  is_active?: boolean
}

export interface UpdateZoneInput {
  name?: string
  code?: string
  sort_order?: number
  is_active?: boolean
}

export async function listZones(locationId: string): Promise<Zone[]> {
  return apiGet<Zone[]>(`/inventory/locations/${locationId}/nodes`)
}

export async function createZone(data: CreateZoneInput): Promise<Zone> {
  // node_type is required by the hierarchy backend; the legacy modal only
  // ever created flat zones.
  return apiPost<Zone>('/inventory/nodes', { ...data, node_type: 'zone' })
}

export async function updateZone(id: string, data: UpdateZoneInput): Promise<Zone> {
  return apiPatch<Zone>(`/inventory/nodes/${id}`, data)
}

export async function deleteZone(id: string): Promise<void> {
  return apiDelete(`/inventory/nodes/${id}`)
}

export async function assignProductsToZone(
  zoneId: string,
  productIds: string[],
): Promise<ZoneProductAssignment[]> {
  return apiPost<ZoneProductAssignment[]>(`/inventory/nodes/${zoneId}/assign-products`, {
    product_ids: productIds,
  })
}

/**
 * NOTE: the hierarchy backend paginates this endpoint ({ data, meta }).
 * `apiGet` unwraps `response.data.data`, dropping `meta` — fine for the
 * legacy modal, which expects a flat list; per_page=200 keeps parity with
 * the old unpaginated response for realistic zone sizes. The Phase-2
 * placement UI will consume the paginated form via `api.get`
 * (docs/conventions/01-API-RESPONSES.md).
 */
export async function listZoneProducts(zoneId: string): Promise<ZoneProductAssignment[]> {
  return apiGet<ZoneProductAssignment[]>(`/inventory/nodes/${zoneId}/products`, { per_page: 200 })
}
