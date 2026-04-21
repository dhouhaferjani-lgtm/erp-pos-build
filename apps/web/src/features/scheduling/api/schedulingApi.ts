import { api, apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'
import type {
  Appointment,
  AppointmentListFilters,
  Bay,
  BookAppointmentInput,
  CancelAppointmentInput,
  CheckInAppointmentInput,
  DayViewData,
  FreeSlotDTO,
  PaginatedAppointments,
  RescheduleAppointmentInput,
  ScheduleConfig,
  UpdateAppointmentInput,
  WeekViewData,
} from '../types'

const APPT_BASE = '/scheduling/appointments'
const BAY_BASE = '/scheduling/bays'
const CAL_BASE = '/scheduling/calendar'

/**
 * Scheduling REST client.
 *
 * The appointments list endpoint returns a paginated `{ data: [], meta: {} }`
 * envelope — we use `api.get` directly to preserve `meta` (per CLAUDE.md
 * memory note on paginated endpoints). Every other endpoint returns
 * `{ data: ... }` and is unwrapped by `apiGet`/`apiPost`/`apiPatch`/etc.
 */
export const schedulingApi = {
  // ----- Appointments CRUD -----

  async listAppointments(filters: AppointmentListFilters = {}): Promise<PaginatedAppointments> {
    const params: Record<string, string> = {}
    if (filters.status) params['status'] = filters.status
    if (filters.bay_id) params['bay_id'] = filters.bay_id
    if (filters.date_from) params['date_from'] = filters.date_from
    if (filters.date_to) params['date_to'] = filters.date_to
    if (filters.page) params['page'] = String(filters.page)
    if (filters.per_page) params['per_page'] = String(filters.per_page)
    const response = await api.get<PaginatedAppointments>(APPT_BASE, { params })
    return response.data
  },

  getAppointment(id: string): Promise<Appointment> {
    return apiGet<Appointment>(`${APPT_BASE}/${id}`)
  },

  bookAppointment(input: BookAppointmentInput): Promise<Appointment> {
    return apiPost<Appointment>(APPT_BASE, input)
  },

  updateAppointment(id: string, input: UpdateAppointmentInput): Promise<Appointment> {
    return apiPatch<Appointment>(`${APPT_BASE}/${id}`, input)
  },

  deleteAppointment(id: string): Promise<{ id: string; deleted: boolean }> {
    return apiDelete<{ id: string; deleted: boolean }>(`${APPT_BASE}/${id}`)
  },

  // ----- Appointment transitions -----

  confirmAppointment(id: string): Promise<{ id: string; status: string }> {
    return apiPost<{ id: string; status: string }>(`${APPT_BASE}/${id}/confirm`)
  },

  rescheduleAppointment(
    id: string,
    input: RescheduleAppointmentInput
  ): Promise<{ id: string; status: string; scheduled_start: string; scheduled_end: string; bay_id: string | null }> {
    return apiPost(`${APPT_BASE}/${id}/reschedule`, input)
  },

  checkInAppointment(
    id: string,
    input: CheckInAppointmentInput = {}
  ): Promise<{ id: string; status: string; actual_arrival_at: string | null }> {
    return apiPost(`${APPT_BASE}/${id}/check-in`, input)
  },

  cancelAppointment(id: string, input: CancelAppointmentInput = {}): Promise<{ id: string; status: string }> {
    return apiPost(`${APPT_BASE}/${id}/cancel`, input)
  },

  convertAppointment(id: string): Promise<{ appointment_id: string; work_order_id: string }> {
    return apiPost(`${APPT_BASE}/${id}/convert`)
  },

  // ----- Bays -----

  listBays(): Promise<Bay[]> {
    return apiGet<Bay[]>(BAY_BASE)
  },

  // ----- Schedule config -----

  getScheduleConfig(locationId: string): Promise<ScheduleConfig> {
    return apiGet<ScheduleConfig>(`/scheduling/config/${locationId}`)
  },

  // ----- Calendar reads -----

  dayView(date: string): Promise<DayViewData> {
    return apiGet<DayViewData>(`${CAL_BASE}/day`, { date })
  },

  weekView(weekStart: string): Promise<WeekViewData> {
    return apiGet<WeekViewData>(`${CAL_BASE}/week`, { week_start: weekStart })
  },

  freeSlots(params: { duration: number; from: string; to: string }): Promise<FreeSlotDTO[]> {
    return apiGet<FreeSlotDTO[]>(`${CAL_BASE}/free-slots`, {
      duration: String(params.duration),
      from: params.from,
      to: params.to,
    })
  },
}
