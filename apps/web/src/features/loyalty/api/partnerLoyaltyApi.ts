import { apiGet, apiPost } from '@/lib/api'

// Source of truth is the backend resource (Rule 7: types flow from backend).
// See packages/shared/types/generated.d.ts:
//   App.Modules.Loyalty.Application.DTOs.PartnerLoyaltySummaryData
export type PartnerLoyaltySummary = App.Modules.Loyalty.Application.DTOs.PartnerLoyaltySummaryData
export type PartnerEnrollmentSummary = App.Modules.Loyalty.Application.DTOs.PartnerEnrollmentSummaryData

export interface EnrollPartnerData {
  phone: string
  program_id?: string
}

export async function getPartnerLoyaltySummary(partnerId: string): Promise<PartnerLoyaltySummary> {
  return apiGet<PartnerLoyaltySummary>(`/loyalty/partners/${partnerId}`)
}

export async function enrollPartner(
  partnerId: string,
  data: EnrollPartnerData,
): Promise<PartnerLoyaltySummary> {
  return apiPost<PartnerLoyaltySummary>(`/loyalty/partners/${partnerId}/enroll`, data)
}
