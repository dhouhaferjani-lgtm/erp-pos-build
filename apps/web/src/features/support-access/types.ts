export type GrantStatus =
  | 'pending_tenant_approval'
  | 'pending_internal_approval'
  | 'active'
  | 'rejected'
  | 'revoked'
  | 'expired'

export interface SupportAccessGrant {
  id: string
  tenant_id: string
  subject_user_id: string | null
  operator_id: string | null
  type: 'per_incident' | 'pre_granted_window'
  status: GrantStatus
  reason: string
  ticket_ref: string
  starts_at: string
  expires_at: string
  tenant_approved_by: string | null
  second_approved_by: string | null
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
  event_type: string
  outcome: string
  action: string | null
  http_method: string | null
  ticket_ref: string | null
  occurred_at: string
}

export interface SupportAccessOverview {
  grants: SupportAccessGrant[]
  active_sessions: SupportAccessSession[]
  log: SupportAccessLogEntry[]
  pending_elevations: SupportAccessElevation[]
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
