/**
 * Scheduling frontend types — TEMPORARY.
 *
 * These shadow the backend response shapes produced by
 * `apps/api/app/Modules/Scheduling/Presentation/Controllers/*`. Once the
 * shared type-generation pipeline ships (see
 * `docs/sessions/2026-04-19-types-pipeline-coordination.md`), this file is
 * deleted and consumers import from `@autoerp/shared/types/*`.
 *
 * Until then, keep shapes aligned with the controllers + Domain enums by
 * hand. Every status/type below corresponds to a PHP backed enum in
 * `App\Modules\Scheduling\Domain\Enums\*`.
 */

export type AppointmentStatus =
  | 'scheduled'
  | 'confirmed'
  | 'checked_in'
  | 'in_progress'
  | 'completed'
  | 'closed'
  | 'no_show'
  | 'cancelled'

export type AppointmentType =
  | 'quick_service'
  | 'inspection'
  | 'diagnostic'
  | 'standard_repair'
  | 'major_repair'
  | 'maintenance'
  | 'tire_service'
  | 'bodywork'
  | 'other'

export type AppointmentSource = 'manual' | 'phone' | 'online' | 'walkin'

export type WaitType = 'waiter' | 'drop_off' | 'pickup_scheduled'

export type BayType =
  | 'general'
  | 'quick_service'
  | 'alignment'
  | 'heavy'
  | 'specialist'
  | 'flat'
  | 'other'

/**
 * Bay resource — one physical lift / ramp.
 */
export interface Bay {
  id: string
  tenant_id: string
  company_id: string
  location_id: string
  code: string
  name: string
  bay_type: BayType
  display_order: number
  operating_hours: Record<string, { start: string; end: string }[]>
  notes: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}

/**
 * Per-location Scheduling configuration aggregate.
 */
export interface ScheduleConfig {
  id: string
  tenant_id: string
  company_id: string
  location_id: string
  time_slot_minutes: number
  default_appointment_duration_minutes: number
  walk_in_buffer_hours_per_day: string
  overbooking_threshold_percent: number
  online_booking_enabled: boolean
  online_booking_advance_days: number
  online_booking_min_notice_hours: number
  online_booking_auto_confirm: boolean
  reminder_sms_hours_before: number | null
  reminder_email_hours_before: number | null
}

/**
 * Appointment aggregate as serialized by `AppointmentController::serialize()`.
 */
export interface Appointment {
  id: string
  tenant_id: string
  company_id: string
  location_id: string
  appointment_number: string
  bay_id: string | null
  primary_technician_profile_id: string | null
  customer_partner_id: string | null
  vehicle_id: string | null
  customer_name: string | null
  customer_phone: string | null
  customer_email: string | null
  vehicle_plate: string | null
  vehicle_description: string | null
  appointment_type: AppointmentType
  wait_type: WaitType
  status: AppointmentStatus
  scheduled_start: string
  scheduled_end: string
  estimated_duration_minutes: number
  actual_arrival_at: string | null
  services_summary: string | null
  customer_notes: string | null
  internal_notes: string | null
  color_label: string | null
  source: AppointmentSource
  work_order_id: string | null
  created_at: string
  updated_at: string
}

export interface PlannedServiceInput {
  service_ref_type: 'service' | 'bundle'
  service_ref_id: string
  display_name: string
  estimated_duration_minutes: number
  estimated_price?: string | null
  display_order?: number
}

export interface BookAppointmentInput {
  location_id: string
  bay_id?: string | null
  primary_technician_profile_id?: string | null
  customer_partner_id?: string | null
  vehicle_id?: string | null
  customer_name?: string | null
  customer_phone?: string | null
  customer_email?: string | null
  vehicle_plate?: string | null
  vehicle_description?: string | null
  appointment_type: AppointmentType
  wait_type?: WaitType
  scheduled_start: string
  scheduled_end: string
  estimated_duration_minutes: number
  planned_services: PlannedServiceInput[]
  services_summary?: string | null
  customer_notes?: string | null
  internal_notes?: string | null
}

export interface UpdateAppointmentInput {
  primary_technician_profile_id?: string | null
  customer_name?: string | null
  customer_phone?: string | null
  customer_email?: string | null
  vehicle_plate?: string | null
  vehicle_description?: string | null
  services_summary?: string | null
  customer_notes?: string | null
  internal_notes?: string | null
  color_label?: string | null
}

export interface RescheduleAppointmentInput {
  new_bay_id?: string | null
  new_scheduled_start: string
  new_scheduled_end: string
}

export interface CheckInAppointmentInput {
  actual_arrival_at?: string | null
}

export interface CancelAppointmentInput {
  reason_code?: string | null
}

export interface AppointmentListFilters {
  status?: AppointmentStatus
  bay_id?: string
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}

export interface PaginatedAppointments {
  data: Appointment[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface AvailabilityWindowDTO {
  start: string
  end: string
  duration_minutes: number
}

export interface BookedEntryDTO {
  appointment_id: string
  start: string
  end: string
  status: AppointmentStatus
}

export interface DayViewData {
  date: string
  availability: Record<string, AvailabilityWindowDTO[]>
  booked: Record<string, BookedEntryDTO[]>
}

export interface WeekEntryDTO {
  appointment_id: string
  bay_id: string | null
  primary_technician_profile_id: string | null
  start: string
  end: string
  status: AppointmentStatus
  customer_display: string | null
  appointment_number: string
}

/**
 * Week view is keyed by YYYY-MM-DD date strings for each of the 7 days.
 */
export type WeekViewData = Record<string, WeekEntryDTO[]>

export interface FreeSlotDTO {
  bay_id: string
  start: string
  end: string
  duration_minutes: number
}

export interface ConflictDetail {
  conflicting_appointment_id: string | null
  conflicting_bay_id: string | null
  conflicting_window: {
    start: string
    end: string
  } | null
  reason: string
}

/**
 * Wire shape of an `AppointmentConflictException` response body
 * (HTTP 409 `error_code: appointment_conflict`).
 */
export interface AppointmentConflictPayload {
  message: string
  error_code: 'appointment_conflict'
  conflict: ConflictDetail
}

/**
 * Utilization slice returned by the CalendarController (not yet surfaced on
 * the `day`/`week` endpoints — shape follows Spec §6). Computed in the
 * CapacityReportPage from DayViewData + bay operating hours.
 */
export interface CapacitySlice {
  label: string
  booked_minutes: number
  available_minutes: number
  utilization_percent: number
}
