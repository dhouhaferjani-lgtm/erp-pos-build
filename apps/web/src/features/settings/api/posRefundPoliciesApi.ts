import { apiGet, apiPut } from '@/lib/api'
import type { PosRefundPolicies } from '../types/posRefundPolicies'

interface SettingsResponse {
  data: PosRefundPolicies
}

export async function getPosRefundPolicies(companyId: string): Promise<PosRefundPolicies> {
  const result = await apiGet<SettingsResponse>(`/companies/${companyId}/reservation-settings`)
  return result.data
}

export async function updatePosRefundPolicies(
  companyId: string,
  payload: Partial<PosRefundPolicies>
): Promise<PosRefundPolicies> {
  const result = await apiPut<SettingsResponse>(
    `/companies/${companyId}/reservation-settings`,
    payload
  )
  return result.data
}
