import { apiGet, apiPost } from '@/lib/api'
import type { CreateSupportWindowInput, SupportAccessGrant, SupportAccessOverview } from '../types'

export function getTenantSupportAccess(): Promise<SupportAccessOverview> {
  return apiGet('/support-access')
}

export function createSupportWindow(input: CreateSupportWindowInput): Promise<SupportAccessGrant> {
  return apiPost('/support-access/grants', input)
}

export function approveTenantGrant(grantId: string): Promise<SupportAccessGrant> {
  return apiPost(`/support-access/requests/${grantId}/approve`)
}

export function rejectTenantGrant(grantId: string, reason: string): Promise<SupportAccessGrant> {
  return apiPost(`/support-access/requests/${grantId}/reject`, { reason })
}

export function revokeTenantGrant(grantId: string, reason: string): Promise<SupportAccessGrant> {
  return apiPost(`/support-access/grants/${grantId}/revoke`, { reason })
}

export async function exitImpersonationSession(sessionId: string): Promise<void> {
  await apiPost(`/support-access/sessions/${sessionId}/exit`)
}
