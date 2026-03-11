import { api, apiGet, apiPost, apiPatch } from '@/lib/api'
import type {
  LoyaltyMember,
  CreateMemberData,
  UpdateMemberData,
  MemberListParams,
  MemberListResponse,
  Enrollment,
  AdjustPointsData,
  TransactionListResponse,
} from '../types/loyalty'

export async function listMembers(params: MemberListParams = {}): Promise<MemberListResponse> {
  const searchParams = new URLSearchParams()
  if (params.page) searchParams.set('page', String(params.page))
  if (params.per_page) searchParams.set('per_page', String(params.per_page))
  if (params.search) searchParams.set('search', params.search)
  if (params.status) searchParams.set('status', params.status)

  const query = searchParams.toString()
  const response = await api.get<MemberListResponse>(`/loyalty/members${query ? `?${query}` : ''}`)
  return response.data
}

export async function getMember(id: string): Promise<LoyaltyMember> {
  return apiGet<LoyaltyMember>(`/loyalty/members/${id}`)
}

export async function createMember(data: CreateMemberData): Promise<LoyaltyMember> {
  return apiPost<LoyaltyMember>('/loyalty/members', data)
}

export async function updateMember(id: string, data: UpdateMemberData): Promise<LoyaltyMember> {
  return apiPatch<LoyaltyMember>(`/loyalty/members/${id}`, data)
}

export async function listEnrollments(memberId: string): Promise<Enrollment[]> {
  return apiGet<Enrollment[]>(`/loyalty/members/${memberId}/enrollments`)
}

export async function enrollMember(memberId: string, programId: string): Promise<Enrollment> {
  return apiPost<Enrollment>(`/loyalty/members/${memberId}/enroll`, { program_id: programId })
}

export async function optOutEnrollment(memberId: string, enrollmentId: string): Promise<void> {
  return apiPost(`/loyalty/members/${memberId}/enrollments/${enrollmentId}/opt-out`)
}

export async function reactivateEnrollment(memberId: string, enrollmentId: string): Promise<void> {
  return apiPost(`/loyalty/members/${memberId}/enrollments/${enrollmentId}/reactivate`)
}

export async function listTransactions(
  memberId: string,
  enrollmentId: string,
  page: number = 1,
): Promise<TransactionListResponse> {
  const response = await api.get<TransactionListResponse>(
    `/loyalty/members/${memberId}/enrollments/${enrollmentId}/transactions?page=${page}`,
  )
  return response.data
}

export async function adjustPoints(
  memberId: string,
  enrollmentId: string,
  data: AdjustPointsData,
): Promise<void> {
  return apiPost(`/loyalty/members/${memberId}/enrollments/${enrollmentId}/adjust`, data)
}
