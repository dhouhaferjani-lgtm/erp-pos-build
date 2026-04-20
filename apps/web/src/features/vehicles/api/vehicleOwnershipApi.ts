import { api } from '../../../lib/api'
import type { VehicleOwnershipData } from '../types'

interface CollectionResponse<T> {
  data: T[]
}

interface ItemResponse<T> {
  data: T
}

export async function fetchOwnershipHistory(vehicleId: string): Promise<VehicleOwnershipData[]> {
  const response = await api.get<CollectionResponse<VehicleOwnershipData>>(
    `/vehicles/${vehicleId}/ownerships`,
  )
  return response.data.data
}

export interface TransferOwnershipPayload {
  new_owner_partner_id: string
  occurred_at: string
  reason_code: string
  notes?: string | null
}

export async function transferOwnership(
  vehicleId: string,
  payload: TransferOwnershipPayload,
): Promise<VehicleOwnershipData> {
  const response = await api.post<ItemResponse<VehicleOwnershipData>>(
    `/vehicles/${vehicleId}/ownerships`,
    payload,
  )
  return response.data.data
}
