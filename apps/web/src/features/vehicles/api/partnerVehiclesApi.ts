import { api } from '../../../lib/api'
import type { VehicleData } from '../types'

interface PaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
  }
}

export async function fetchVehiclesForPartner(
  partnerId: string,
  perPage = 15,
): Promise<PaginatedResponse<VehicleData>> {
  const response = await api.get<PaginatedResponse<VehicleData>>(
    `/partners/${partnerId}/vehicles`,
    { params: { per_page: perPage } },
  )
  return response.data
}
