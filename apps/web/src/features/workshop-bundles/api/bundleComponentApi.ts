import { api } from '@/lib/api'
import type {
  BundleComponentType,
  ServiceBundleComponentData,
  ServiceBundleVehicleApplicabilityData,
} from '../types'

const BASE = '/workshop/bundles'

export interface AddComponentPayload {
  component_type: BundleComponentType
  component_id: string
  quantity: string
  unit_id: string
  override_unit_price?: string | null
  is_optional?: boolean
  display_order?: number
  notes?: string | null
}

/**
 * Partial update payload. A key set to `null` clears the field
 * server-side; a missing key leaves it untouched. Build the object by
 * conditionally spreading provided keys — don't send `undefined`.
 */
export type UpdateComponentPayload = Partial<AddComponentPayload>

export async function addBundleComponent(
  bundleId: string,
  payload: AddComponentPayload,
): Promise<ServiceBundleComponentData> {
  const response = await api.post<{ data: ServiceBundleComponentData }>(
    `${BASE}/${bundleId}/components`,
    payload,
  )
  return response.data.data
}

export async function updateBundleComponent(
  bundleId: string,
  componentId: string,
  payload: UpdateComponentPayload,
): Promise<ServiceBundleComponentData> {
  const response = await api.patch<{ data: ServiceBundleComponentData }>(
    `${BASE}/${bundleId}/components/${componentId}`,
    payload,
  )
  return response.data.data
}

export async function deleteBundleComponent(
  bundleId: string,
  componentId: string,
): Promise<void> {
  await api.delete(`${BASE}/${bundleId}/components/${componentId}`)
}

/** Full-replace payload for the applicability endpoint. */
export interface SetApplicabilitiesPayload {
  applicabilities: Array<{
    platform_vehicle_id: string | null
    vehicle_type: string | null
    vehicle_display: string | null
    year_from: number | null
    year_to: number | null
  }>
}

export async function replaceBundleApplicabilities(
  bundleId: string,
  payload: SetApplicabilitiesPayload,
): Promise<ServiceBundleVehicleApplicabilityData[]> {
  const response = await api.put<{ data: ServiceBundleVehicleApplicabilityData[] }>(
    `${BASE}/${bundleId}/vehicle-applicabilities`,
    payload,
  )
  return response.data.data
}
