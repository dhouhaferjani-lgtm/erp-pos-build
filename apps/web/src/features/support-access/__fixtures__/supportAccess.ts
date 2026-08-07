import type { OffsetPaginationMeta } from '@/types/pagination'
import type { SupportAccessOverview } from '../types'

export const supportAccessFixture: SupportAccessOverview = {
  grants: [
    {
      id: 'grant-sensitive', tenant_id: 'tenant-1', subject_user_id: 'subject-1', operator_id: 'admin-1',
      type: 'per_incident', status: 'pending_internal_approval', reason: 'Investigate stock mismatch',
      ticket_ref: 'SUP-9000', starts_at: '2030-01-01T10:00:00Z', expires_at: '2030-01-01T11:00:00Z',
      tenant_approved_by: 'tenant-admin', second_approved_by: null,
    },
    {
      id: 'grant-active', tenant_id: 'tenant-1', subject_user_id: 'subject-1', operator_id: 'admin-1',
      type: 'per_incident', status: 'active', reason: 'Investigate stock mismatch', ticket_ref: 'SUP-9000',
      starts_at: '2030-01-01T10:00:00Z', expires_at: '2030-01-01T11:00:00Z',
      tenant_approved_by: 'tenant-admin', second_approved_by: 'partner-admin',
    },
    {
      id: 'grant-pending', tenant_id: 'tenant-1', subject_user_id: 'subject-1', operator_id: 'admin-1',
      type: 'per_incident', status: 'pending_tenant_approval', reason: 'Investigate stock mismatch',
      ticket_ref: 'SUP-9000', starts_at: '2030-01-01T10:00:00Z', expires_at: '2030-01-01T11:00:00Z',
      tenant_approved_by: null, second_approved_by: null,
    },
  ],
  active_sessions: [{
    id: 'session-active', grant_id: 'grant-active', subject_user_id: 'subject-1', access_level: 'read_only',
    started_at: '2030-01-01T10:00:00Z', expires_at: '2030-01-01T11:00:00Z', ended_at: null,
    reason: 'Investigate stock mismatch', ticket_ref: 'SUP-9000',
  }],
  log: [{
    id: 'event-1', session_id: 'session-active', subject_user_id: 'subject-1',
    operator_name: 'Support Operator',
    event_type: 'request_authorized', outcome: 'allowed', action: 'products.index', http_method: 'GET',
    ticket_ref: 'SUP-9000', reason: 'Investigate stock mismatch', access_level: 'read_only',
    duration_seconds: 120, occurred_at: '2030-01-01T10:02:00Z',
  }],
  pending_elevations: [{
    id: 'elevation-1', session_id: 'session-active', status: 'pending',
    reason: 'Update a non-fiscal product description', requested_at: '2030-01-01T10:05:00Z',
  }],
  log_meta: {
    current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1,
  } satisfies OffsetPaginationMeta,
}
