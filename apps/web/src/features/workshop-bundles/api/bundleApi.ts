import { api } from '../../../lib/api'
import type {
  ApplicableBundleData,
  BundleExpansionLineData,
  ServiceBundleData,
} from '../types'
import type { OffsetPaginationMeta } from '@/types/pagination'

const BASE = '/workshop/bundles'

interface ListBundlesResponse {
  data: ServiceBundleData[]
  meta: OffsetPaginationMeta
}

export interface ListBundlesParams {
  active?: boolean
  search?: string
  per_page?: number
  page?: number
}

export interface CreateBundlePayload {
  code: string
  name: string
  description?: string | null
  pricing_mode: 'standard' | 'fixed_bundle'
  base_price?: string | null
  currency: string
  tax_rate?: string | null
  estimated_labor_hours?: string | null
  service_interval_km?: number | null
  service_interval_months?: number | null
}

export type UpdateBundlePayload = Partial<
  Omit<CreateBundlePayload, 'code'> & { is_active: boolean }
>

export async function listBundles(
  params: ListBundlesParams = {},
): Promise<ListBundlesResponse> {
  const response = await api.get<ListBundlesResponse>(BASE, { params })
  return response.data
}

export async function getBundle(id: string): Promise<ServiceBundleData> {
  const response = await api.get<{ data: ServiceBundleData }>(`${BASE}/${id}`)
  return response.data.data
}

export async function createBundle(
  payload: CreateBundlePayload,
): Promise<ServiceBundleData> {
  const response = await api.post<{ data: ServiceBundleData }>(BASE, payload)
  return response.data.data
}

export async function updateBundle(
  id: string,
  payload: UpdateBundlePayload,
): Promise<ServiceBundleData> {
  const response = await api.patch<{ data: ServiceBundleData }>(
    `${BASE}/${id}`,
    payload,
  )
  return response.data.data
}

export async function deleteBundle(id: string): Promise<void> {
  await api.delete(`${BASE}/${id}`)
}

export interface ApplicableBundlesParams {
  vehicle_id?: string
  vehicle_type?: string
  q?: string
}

export async function listApplicableBundles(
  params: ApplicableBundlesParams = {},
): Promise<ApplicableBundleData[]> {
  const response = await api.get<{ data: ApplicableBundleData[] }>(
    `${BASE}/applicable`,
    { params },
  )
  return response.data.data
}

export async function getBundleExpansion(
  bundleId: string,
  qty = '1',
  vehicleId?: string,
): Promise<BundleExpansionLineData[]> {
  const params: Record<string, string> = { qty }
  if (vehicleId !== undefined) {
    params['vehicle_id'] = vehicleId
  }
  const response = await api.get<{ data: BundleExpansionLineData[] }>(
    `${BASE}/${bundleId}/expansion`,
    { params },
  )
  return response.data.data
}
