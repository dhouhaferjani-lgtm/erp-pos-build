import { apiGet, apiPut } from '@/lib/api'
import type { PosRefundPolicies } from '../types/posRefundPolicies'

export async function getPosRefundPolicies(companyId: string): Promise<PosRefundPolicies> {
  return apiGet<PosRefundPolicies>(`/companies/${companyId}/reservation-settings`)
}

export async function updatePosRefundPolicies(
  companyId: string,
  payload: Partial<PosRefundPolicies>
): Promise<PosRefundPolicies> {
  return apiPut<PosRefundPolicies>(
    `/companies/${companyId}/reservation-settings`,
    payload
  )
}
