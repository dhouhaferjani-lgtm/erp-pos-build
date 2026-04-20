import { api } from '../../../lib/api'
import type { MileageSource, VehicleMileageReadingData } from '../types'

interface CollectionResponse<T> {
  data: T[]
}

interface ItemResponse<T> {
  data: T
}

export async function fetchMileageHistory(vehicleId: string): Promise<VehicleMileageReadingData[]> {
  const response = await api.get<CollectionResponse<VehicleMileageReadingData>>(
    `/vehicles/${vehicleId}/mileage`,
  )
  return response.data.data
}

export interface LogMileagePayload {
  mileage: number
  recorded_at: string
  source: MileageSource
  context_document_id?: string | null
  context_work_order_id?: string | null
  notes?: string | null
}

export async function logMileage(
  vehicleId: string,
  payload: LogMileagePayload,
): Promise<VehicleMileageReadingData> {
  const response = await api.post<ItemResponse<VehicleMileageReadingData>>(
    `/vehicles/${vehicleId}/mileage`,
    payload,
  )
  return response.data.data
}
