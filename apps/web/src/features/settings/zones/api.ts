import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'

/**
 * Type aliases over the backend-generated DTOs (source of truth lives in
 * `packages/shared/types/generated.d.ts`, produced by `php artisan
 * typescript:transform`). Never hand-write zone types — re-export the
 * generated ones here for ergonomic local consumption.
 */
export type Zone = App.Modules.Inventory.Application.DTOs.ZoneDto
export type ZoneProductAssignment = App.Modules.Inventory.Application.DTOs.ZoneProductAssignmentDto

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
  return apiGet<Zone[]>('/inventory/zones', { location_id: locationId })
}

export async function createZone(data: CreateZoneInput): Promise<Zone> {
  return apiPost<Zone>('/inventory/zones', data)
}

export async function updateZone(id: string, data: UpdateZoneInput): Promise<Zone> {
  return apiPatch<Zone>(`/inventory/zones/${id}`, data)
}

export async function deleteZone(id: string): Promise<void> {
  return apiDelete(`/inventory/zones/${id}`)
}

export async function assignProductsToZone(
  zoneId: string,
  productIds: string[],
): Promise<ZoneProductAssignment[]> {
  return apiPost<ZoneProductAssignment[]>(`/inventory/zones/${zoneId}/assign-products`, {
    product_ids: productIds,
  })
}

/**
 * NOTE: the landed A2 backend (`ZoneController::products()`) returns a flat
 * `{ data: ZoneProductAssignmentDto[] }` array with no pagination `meta` —
 * `apiGet` (which unwraps `response.data.data`) is the correct helper here.
 * If a future change paginates this endpoint, switch to `api.get` + return
 * `response.data` to keep `meta`, per the project's paginated-endpoint
 * convention (docs/conventions/01-API-RESPONSES.md).
 */
export async function listZoneProducts(zoneId: string): Promise<ZoneProductAssignment[]> {
  return apiGet<ZoneProductAssignment[]>(`/inventory/zones/${zoneId}/products`)
}
