import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'
import type {
  CreateCertificationPayload,
  CreateTimeEntryPayload,
  CreateTimeOffPayload,
  DateRangeFilter,
  TechnicianCertification,
  TechnicianTimeEntry,
  TechnicianTimeOff,
  UpdateCertificationPayload,
  UpdateTimeEntryPayload,
  UpdateTimeOffPayload,
} from './authoringTypes'

const BASE = (techId: string): string => `/workshop/technicians/${techId}`

function withRange(params: DateRangeFilter | undefined): Record<string, string> {
  const out: Record<string, string> = {}
  if (params?.from !== undefined && params.from !== '') {
    out['from'] = params.from
  }
  if (params?.to !== undefined && params.to !== '') {
    out['to'] = params.to
  }
  return out
}

export const certificationApi = {
  list(technicianId: string): Promise<TechnicianCertification[]> {
    return apiGet<TechnicianCertification[]>(`${BASE(technicianId)}/certifications`)
  },
  create(
    technicianId: string,
    payload: CreateCertificationPayload,
  ): Promise<TechnicianCertification> {
    return apiPost<TechnicianCertification>(`${BASE(technicianId)}/certifications`, payload)
  },
  update(
    technicianId: string,
    certificationId: string,
    payload: UpdateCertificationPayload,
  ): Promise<TechnicianCertification> {
    return apiPatch<TechnicianCertification>(
      `${BASE(technicianId)}/certifications/${certificationId}`,
      payload,
    )
  },
  remove(technicianId: string, certificationId: string): Promise<void> {
    return apiDelete<void>(`${BASE(technicianId)}/certifications/${certificationId}`)
  },
}

export const timeOffApi = {
  list(technicianId: string, range?: DateRangeFilter): Promise<TechnicianTimeOff[]> {
    return apiGet<TechnicianTimeOff[]>(`${BASE(technicianId)}/time-off`, withRange(range))
  },
  create(technicianId: string, payload: CreateTimeOffPayload): Promise<TechnicianTimeOff> {
    return apiPost<TechnicianTimeOff>(`${BASE(technicianId)}/time-off`, payload)
  },
  update(
    technicianId: string,
    timeOffId: string,
    payload: UpdateTimeOffPayload,
  ): Promise<TechnicianTimeOff> {
    return apiPatch<TechnicianTimeOff>(
      `${BASE(technicianId)}/time-off/${timeOffId}`,
      payload,
    )
  },
  remove(technicianId: string, timeOffId: string): Promise<void> {
    return apiDelete<void>(`${BASE(technicianId)}/time-off/${timeOffId}`)
  },
}

export const timeEntryApi = {
  list(technicianId: string, range?: DateRangeFilter): Promise<TechnicianTimeEntry[]> {
    return apiGet<TechnicianTimeEntry[]>(`${BASE(technicianId)}/time-entries`, withRange(range))
  },
  create(technicianId: string, payload: CreateTimeEntryPayload): Promise<TechnicianTimeEntry> {
    return apiPost<TechnicianTimeEntry>(`${BASE(technicianId)}/time-entries`, payload)
  },
  update(
    technicianId: string,
    timeEntryId: string,
    payload: UpdateTimeEntryPayload,
  ): Promise<TechnicianTimeEntry> {
    return apiPatch<TechnicianTimeEntry>(
      `${BASE(technicianId)}/time-entries/${timeEntryId}`,
      payload,
    )
  },
  remove(technicianId: string, timeEntryId: string): Promise<void> {
    return apiDelete<void>(`${BASE(technicianId)}/time-entries/${timeEntryId}`)
  },
}
