import { apiGet } from '@/lib/api'
import type {
  AvailabilityQuery,
  AvailabilityResult,
  TechnicianListFilters,
  TechnicianProfile,
} from './types'

const BASE = '/workshop/technicians'

/**
 * Read-side Workshop/Technician API client.
 *
 * Plan C deferred authoring CRUD (create/update/time-off/time-entry/payroll) to
 * a follow-up patch, so no `apiPost`/`apiPatch` helpers here yet. The backend
 * returns a plain `{ data: TechnicianProfile[] }` envelope (not a paginated
 * response), so `apiGet` unwraps it correctly.
 */
export const technicianApi = {
  list(filters: TechnicianListFilters = {}): Promise<TechnicianProfile[]> {
    const params: Record<string, string> = {}
    if (filters.active_only) {
      params['active_only'] = '1'
    }
    if (filters.specialty) {
      params['specialty'] = filters.specialty
    }
    return apiGet<TechnicianProfile[]>(BASE, params)
  },

  get(id: string): Promise<TechnicianProfile> {
    return apiGet<TechnicianProfile>(`${BASE}/${id}`)
  },

  available(query: AvailabilityQuery): Promise<AvailabilityResult> {
    const params: Record<string, string> = {
      technician_profile_id: query.technician_profile_id,
      starts_at: query.starts_at,
      duration_minutes: String(query.duration_minutes),
    }
    if (query.required_specialty) {
      params['required_specialty'] = query.required_specialty
    }
    return apiGet<AvailabilityResult>(`${BASE}/available`, params)
  },
}
