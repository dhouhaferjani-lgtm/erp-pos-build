import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { certificationApi, timeEntryApi, timeOffApi } from '../api/authoringApi'
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
} from '../api/authoringTypes'

const ROOT = ['workshop-technicians'] as const

export const authoringKeys = {
  certifications: (techId: string) => [...ROOT, techId, 'certifications'] as const,
  timeOff: (techId: string, range?: DateRangeFilter) =>
    [...ROOT, techId, 'time-off', range ?? null] as const,
  timeEntries: (techId: string, range?: DateRangeFilter) =>
    [...ROOT, techId, 'time-entries', range ?? null] as const,
}

// ----------------------- Certifications ------------------------------------

export function useTechnicianCertifications(technicianId: string | undefined) {
  return useQuery<TechnicianCertification[]>({
    queryKey: authoringKeys.certifications(technicianId ?? ''),
    queryFn: () => {
      if (typeof technicianId !== 'string' || technicianId.length === 0) {
        return Promise.reject(new Error('technicianId required'))
      }
      return certificationApi.list(technicianId)
    },
    enabled: typeof technicianId === 'string' && technicianId.length > 0,
  })
}

export function useCreateCertification(technicianId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (payload: CreateCertificationPayload) =>
      certificationApi.create(technicianId, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: authoringKeys.certifications(technicianId) })
    },
  })
}

export function useUpdateCertification(technicianId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (args: { certificationId: string; payload: UpdateCertificationPayload }) =>
      certificationApi.update(technicianId, args.certificationId, args.payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: authoringKeys.certifications(technicianId) })
    },
  })
}

export function useDeleteCertification(technicianId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (certificationId: string) =>
      certificationApi.remove(technicianId, certificationId),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: authoringKeys.certifications(technicianId) })
    },
  })
}

// ----------------------- Time off ------------------------------------------

export function useTimeOff(technicianId: string | undefined, range?: DateRangeFilter) {
  return useQuery<TechnicianTimeOff[]>({
    queryKey: authoringKeys.timeOff(technicianId ?? '', range),
    queryFn: () => {
      if (typeof technicianId !== 'string' || technicianId.length === 0) {
        return Promise.reject(new Error('technicianId required'))
      }
      return timeOffApi.list(technicianId, range)
    },
    enabled: typeof technicianId === 'string' && technicianId.length > 0,
  })
}

export function useCreateTimeOff(technicianId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (payload: CreateTimeOffPayload) => timeOffApi.create(technicianId, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...ROOT, technicianId, 'time-off'] })
    },
  })
}

export function useUpdateTimeOff(technicianId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (args: { timeOffId: string; payload: UpdateTimeOffPayload }) =>
      timeOffApi.update(technicianId, args.timeOffId, args.payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...ROOT, technicianId, 'time-off'] })
    },
  })
}

export function useDeleteTimeOff(technicianId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (timeOffId: string) => timeOffApi.remove(technicianId, timeOffId),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...ROOT, technicianId, 'time-off'] })
    },
  })
}

// ----------------------- Time entries --------------------------------------

export function useTimeEntries(technicianId: string | undefined, range?: DateRangeFilter) {
  return useQuery<TechnicianTimeEntry[]>({
    queryKey: authoringKeys.timeEntries(technicianId ?? '', range),
    queryFn: () => {
      if (typeof technicianId !== 'string' || technicianId.length === 0) {
        return Promise.reject(new Error('technicianId required'))
      }
      return timeEntryApi.list(technicianId, range)
    },
    enabled: typeof technicianId === 'string' && technicianId.length > 0,
  })
}

export function useCreateTimeEntry(technicianId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (payload: CreateTimeEntryPayload) =>
      timeEntryApi.create(technicianId, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...ROOT, technicianId, 'time-entries'] })
    },
  })
}

export function useUpdateTimeEntry(technicianId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (args: { timeEntryId: string; payload: UpdateTimeEntryPayload }) =>
      timeEntryApi.update(technicianId, args.timeEntryId, args.payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...ROOT, technicianId, 'time-entries'] })
    },
  })
}

export function useDeleteTimeEntry(technicianId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (timeEntryId: string) => timeEntryApi.remove(technicianId, timeEntryId),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...ROOT, technicianId, 'time-entries'] })
    },
  })
}
