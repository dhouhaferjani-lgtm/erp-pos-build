/**
 * Workshop / Technician authoring types — TEMPORARY.
 *
 * Mirror the backend DTOs in
 * `apps/api/app/Modules/Workshop/Technician/Application/DTOs/`. When the
 * shared type-generation pipeline ships, consumers will import from
 * `@autoerp/shared/types/*` instead.
 */

export interface TechnicianCertification {
  id: string
  technician_profile_id: string
  certification_name: string
  issuing_body: string | null
  certificate_number: string | null
  issued_at: string | null
  expires_at: string | null
  notes: string | null
  created_at: string
}

export interface CreateCertificationPayload {
  certification_name: string
  issuing_body?: string | null
  certificate_number?: string | null
  issued_at?: string | null
  expires_at?: string | null
  notes?: string | null
}

export type UpdateCertificationPayload = Partial<CreateCertificationPayload>

export type TimeOffReason =
  | 'vacation'
  | 'sick'
  | 'training'
  | 'personal'
  | 'unpaid'
  | 'other'

export interface TechnicianTimeOff {
  id: string
  technician_profile_id: string
  starts_at: string
  ends_at: string
  reason_code: TimeOffReason
  is_full_day: boolean
  is_approved: boolean
  approved_by_user_id: string | null
  notes: string | null
}

export interface CreateTimeOffPayload {
  reason_code: TimeOffReason
  starts_at: string
  ends_at: string
  is_full_day?: boolean
  notes?: string | null
}

export type UpdateTimeOffPayload = Partial<CreateTimeOffPayload>

export type TimeEntryType =
  | 'work_order'
  | 'break'
  | 'non_billable'
  | 'manual_adjust'

export type TimeEntrySource = 'event' | 'manual' | 'import'

/**
 * Mirrors `App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus`. Kept
 * inline here (rather than imported from `@autoerp/shared`) because the
 * workshop-technicians authoring UI currently sources types from this local
 * file. The canonical list lives in the PHP enum + the generated.ts output.
 */
export type WorkOrderStatus =
  | 'received'
  | 'diagnosed'
  | 'quoted'
  | 'approved'
  | 'in_progress'
  | 'paused'
  | 'waiting_parts'
  | 'completed'
  | 'invoiced'
  | 'closed'
  | 'cancelled'

export interface TechnicianTimeEntry {
  id: string
  technician_profile_id: string
  company_id: string
  started_at: string
  ended_at: string | null
  duration_minutes: number | null
  entry_type: TimeEntryType
  work_order_id: string | null
  work_order_status: WorkOrderStatus | null
  source: TimeEntrySource
  recorded_by_user_id: string | null
  notes: string | null
}

export interface CreateTimeEntryPayload {
  work_order_id?: string | null
  started_at: string
  ended_at: string
  entry_type: TimeEntryType
  notes?: string | null
}

export type UpdateTimeEntryPayload = Partial<CreateTimeEntryPayload>

export interface DateRangeFilter {
  from?: string
  to?: string
}
