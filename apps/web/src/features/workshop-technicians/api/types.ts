/**
 * Workshop / Technician frontend types — TEMPORARY.
 *
 * These mirror the backend DTOs in
 * `apps/api/app/Modules/Workshop/Technician/Application/DTOs/`. Once the shared
 * type-generation pipeline ships (see `docs/sessions/2026-04-19-types-pipeline-coordination.md`),
 * this file is deleted and consumers import from `@autoerp/shared/types/*`.
 *
 * Until then, keep shapes in sync with the PHP `#[TypeScript]` DTOs by hand.
 */

export type SkillLevel =
  | 'apprentice'
  | 'junior'
  | 'general'
  | 'senior'
  | 'master'
  | 'specialist'

export type SpecialtyCode =
  | 'engine_mechanical'
  | 'engine_diagnostic'
  | 'transmission'
  | 'electrical'
  | 'electronic'
  | 'suspension'
  | 'brakes'
  | 'ac_climate'
  | 'tires'
  | 'alignment'
  | 'bodywork'
  | 'paint'
  | 'hybrid_ev'
  | 'diesel'
  | 'pre_control'
  | 'general_service'

export type EmploymentStatus = 'active' | 'on_leave' | 'terminated'

export interface ScheduleWindow {
  start: string // HH:MM
  end: string // HH:MM
}

export type WeeklyScheduleDay = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun'

export type WeeklyScheduleData = Record<WeeklyScheduleDay, ScheduleWindow[]>

/**
 * Wire DTO for a TechnicianProfile.
 *
 * `hourly_cost_rate` / `hourly_billing_rate` are present only when the caller
 * holds `workshop.technicians.view_pay`. `national_id` / `personal_address` /
 * `personal_phone` require `workshop.technicians.view_pii`. Backend masks fields
 * out entirely (not null) so presence is the signal — see
 * `TechnicianProfileData::toArray()`.
 */
export interface TechnicianProfile {
  id: string
  tenant_id: string
  company_id: string
  user_id: string
  user_display_name: string
  user_email: string | null
  skill_level: SkillLevel
  specialties: SpecialtyCode[]
  hourly_cost_rate?: string | null
  hourly_billing_rate?: string | null
  currency: string
  weekly_schedule: WeeklyScheduleData
  hire_date: string | null
  employment_status: EmploymentStatus
  employee_code: string | null
  notes: string | null
  national_id?: string | null
  personal_address?: string | null
  personal_phone?: string | null
  is_active: boolean
  created_at: string
  updated_at: string | null
}

export type AvailabilityStatus = 'yes' | 'no' | 'partial'

export interface AvailabilityResult {
  status: AvailabilityStatus
  reason: string
}

export interface AvailabilityQuery {
  technician_profile_id: string
  starts_at: string // ISO 8601
  duration_minutes: number
  required_specialty?: SpecialtyCode
}

export interface TechnicianListFilters {
  active_only?: boolean
  specialty?: SpecialtyCode
}
