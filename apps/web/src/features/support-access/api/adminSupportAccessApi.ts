import { adminApiGetPaginated, adminApiPost } from '@/features/admin/lib/adminApi'
import type { OffsetPaginationMeta } from '@/types/pagination'
import type {
  RequestAccessInput,
  StartedSupportSession,
  SupportAccessGrant,
  SupportAccessOverview,
} from '../types'

export interface AdminSupportAccessResponse {
  data: SupportAccessOverview
  meta: OffsetPaginationMeta
}

export function getAdminSupportAccess(page = 1, perPage = 20): Promise<AdminSupportAccessResponse> {
  return adminApiGetPaginated('/admin/impersonation', { page, per_page: perPage })
}

export function requestSupportAccess(input: RequestAccessInput): Promise<SupportAccessGrant> {
  return adminApiPost('/admin/impersonation/requests', input)
}

export function secondApproveGrant(grantId: string): Promise<SupportAccessGrant> {
  return adminApiPost(`/admin/impersonation/requests/${grantId}/approve`)
}

export function revokeAdminGrant(grantId: string, reason: string): Promise<SupportAccessGrant> {
  return adminApiPost(`/admin/impersonation/requests/${grantId}/revoke`, { reason })
}

export function startSupportSession(grantId: string, subjectUserId: string): Promise<StartedSupportSession> {
  return adminApiPost('/admin/impersonation/sessions', {
    grant_id: grantId,
    subject_user_id: subjectUserId,
  })
}

export function requestWriteElevation(sessionId: string, reason: string): Promise<void> {
  return adminApiPost(`/admin/impersonation/sessions/${sessionId}/elevations`, { reason })
}

export function approveWriteElevation(elevationId: string): Promise<void> {
  return adminApiPost(`/admin/impersonation/elevations/${elevationId}/approve`, { approved: true })
}
