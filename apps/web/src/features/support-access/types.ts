import type { OffsetPaginationMeta } from '@/types/pagination'

export type GrantStatus = App.Modules.SupportAccess.Domain.Enums.GrantStatus

export type SupportAccessGrant = Omit<
  App.Modules.SupportAccess.Application.DTOs.GrantData,
  'starts_at' | 'expires_at' | 'tenant_approved_at' | 'second_approved_at' | 'revoked_at'
> & {
  starts_at: string
  expires_at: string
  tenant_approved_at: string | null
  second_approved_at: string | null
  revoked_at: string | null
}

export interface SupportAccessSession {
  id: string
  grant_id: string
  subject_user_id: string
  access_level: 'read_only' | 'write_elevated'
  started_at: string
  expires_at: string
  ended_at: string | null
  reason: string
  ticket_ref: string
}

export interface SupportAccessLogEntry {
  id: string
  session_id: string
  subject_user_id: string
  operator_name: string
  event_type: string
  outcome: string
  action: string | null
  http_method: string | null
  ticket_ref: string | null
  reason: string
  access_level: 'read_only' | 'write_elevated'
  duration_seconds: number
  occurred_at: string
}

export interface SupportAccessOverview extends Omit<
  App.Modules.SupportAccess.Application.DTOs.SupportAccessOverviewData,
  'grants' | 'active_sessions' | 'log' | 'pending_elevations' | 'log_meta'
> {
  max_grant_window_hours: number
  grants: SupportAccessGrant[]
  active_sessions: SupportAccessSession[]
  log: SupportAccessLogEntry[]
  pending_elevations: SupportAccessElevation[]
  log_meta: OffsetPaginationMeta | null
}

export interface SupportAccessElevation {
  id: string
  session_id: string
  status: 'pending' | 'approved' | 'rejected' | 'cancelled' | 'expired'
  reason: string
  requested_at: string
}

export interface RequestAccessInput {
  tenant_id: string
  subject_user_id: string
  reason: string
  ticket_ref: string
  duration_minutes: number
}

export interface CreateSupportWindowInput {
  subject_user_id?: string
  reason: string
  ticket_ref: string
  starts_at: string
  expires_at: string
}

export interface StartedSupportSession {
  session_id: string
  subject_name: string
  plain_text_token: string
  expires_at: string
  permissions: string[]
}
