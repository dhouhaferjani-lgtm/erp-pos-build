import { api } from '../../../lib/api'
import type { VehicleData } from '../types'
import type { OffsetPaginationMeta } from '@/types/pagination'

interface PaginatedResponse<T> {
  data: T[]
  meta: OffsetPaginationMeta
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
