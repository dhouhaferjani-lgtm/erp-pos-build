import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type {
  CompositeItemData,
  CreateCompositeItemData,
  UpdateCompositeItemData,
  PaginatedResponse,
} from '../types/compositeItem'

export async function getCompositeItems(params?: {
  search?: string
  vertical_type?: string
  is_active?: boolean
  per_page?: number
  page?: number
}): Promise<PaginatedResponse<CompositeItemData>> {
  const queryParams: Record<string, string> = {}
  if (params?.search) queryParams['search'] = params.search
  if (params?.vertical_type) queryParams['vertical_type'] = params.vertical_type
  if (params?.is_active !== undefined) queryParams['is_active'] = String(params.is_active)
  if (params?.per_page) queryParams['per_page'] = String(params.per_page)
  if (params?.page) queryParams['page'] = String(params.page)
  return apiGet('/composite-items', queryParams)
}

export async function getCompositeItem(id: string): Promise<CompositeItemData> {
  return apiGet(`/composite-items/${id}`)
}

export async function createCompositeItem(data: CreateCompositeItemData): Promise<CompositeItemData> {
  return apiPost('/composite-items', data)
}

export async function updateCompositeItem(id: string, data: UpdateCompositeItemData): Promise<CompositeItemData> {
  return apiPatch(`/composite-items/${id}`, data)
}

export async function deleteCompositeItem(id: string): Promise<void> {
  return apiDelete(`/composite-items/${id}`)
}

export async function duplicateCompositeItem(id: string): Promise<CompositeItemData> {
  return apiPost(`/composite-items/${id}/duplicate`)
}

export interface AvailabilityData {
  available_quantity: number
  limiting_component: string | null
  components: Array<{
    product_id: string
    product_name: string
    required_quantity: string
    available_quantity: string
    max_produces: number
  }>
}

export async function checkCompositeItemAvailability(
  id: string,
  locationId: string,
): Promise<AvailabilityData> {
  return apiGet(`/composite-items/${id}/availability`, { location_id: locationId })
}
