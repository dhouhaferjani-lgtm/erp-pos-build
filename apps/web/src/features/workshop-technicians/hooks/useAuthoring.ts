import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
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

function useWorkshopTechniciansTenantScope(): {
  tenantId: string | null
  companyId: string | null
  hasTenantScope: boolean
} {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return {
    tenantId,
    companyId,
    hasTenantScope: tenantId !== null && companyId !== null,
  }
}

function technicianAuthoringPredicate(
  technicianId: string,
  segment: 'time-off' | 'time-entries',
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 5 &&
      k[0] === ROOT[0] &&
      k[1] === technicianId &&
      k[2] === segment &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export const authoringKeys = {
  certifications: (techId: string) => [...ROOT, techId, 'certifications'] as const,
  timeOff: (techId: string, range?: DateRangeFilter) =>
    [...ROOT, techId, 'time-off', range ?? null] as const,
  timeEntries: (techId: string, range?: DateRangeFilter) =>
    [...ROOT, techId, 'time-entries', range ?? null] as const,
}

// ----------------------- Certifications ------------------------------------

export function useTechnicianCertifications(technicianId: string | undefined) {
  const { hasTenantScope } = useWorkshopTechniciansTenantScope()

  return useQuery<TechnicianCertification[]>({
    queryKey: tenantScopedKey([...authoringKeys.certifications(technicianId ?? '')]),
    queryFn: () => {
      if (typeof technicianId !== 'string' || technicianId.length === 0) {
        return Promise.reject(new Error('technicianId required'))
      }
      return certificationApi.list(technicianId)
    },
    enabled: typeof technicianId === 'string' && technicianId.length > 0 && hasTenantScope,
  })
}

export function useCreateCertification(technicianId: string) {
  const qc = useQueryClient()
  useWorkshopTechniciansTenantScope()

  return useMutation({
    mutationFn: (payload: CreateCertificationPayload) =>
      certificationApi.create(technicianId, payload),
    onSuccess: async () => {
      await qc.invalidateQueries({
        queryKey: [...authoringKeys.certifications(technicianId)],
      })
    },
  })
}

export function useUpdateCertification(technicianId: string) {
  const qc = useQueryClient()
  useWorkshopTechniciansTenantScope()

  return useMutation({
    mutationFn: (args: { certificationId: string; payload: UpdateCertificationPayload }) =>
      certificationApi.update(technicianId, args.certificationId, args.payload),
    onSuccess: async () => {
      await qc.invalidateQueries({
        queryKey: [...authoringKeys.certifications(technicianId)],
      })
    },
  })
}

export function useDeleteCertification(technicianId: string) {
  const qc = useQueryClient()
  useWorkshopTechniciansTenantScope()

  return useMutation({
    mutationFn: (certificationId: string) =>
      certificationApi.remove(technicianId, certificationId),
    onSuccess: async () => {
      await qc.invalidateQueries({
        queryKey: [...authoringKeys.certifications(technicianId)],
      })
    },
  })
}

// ----------------------- Time off ------------------------------------------

export function useTimeOff(technicianId: string | undefined, range?: DateRangeFilter) {
  const { hasTenantScope } = useWorkshopTechniciansTenantScope()

  return useQuery<TechnicianTimeOff[]>({
    queryKey: tenantScopedKey([...authoringKeys.timeOff(technicianId ?? '', range)]),
    queryFn: () => {
      if (typeof technicianId !== 'string' || technicianId.length === 0) {
        return Promise.reject(new Error('technicianId required'))
      }
      return timeOffApi.list(technicianId, range)
    },
    enabled: typeof technicianId === 'string' && technicianId.length > 0 && hasTenantScope,
  })
}

export function useCreateTimeOff(technicianId: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkshopTechniciansTenantScope()

  return useMutation({
    mutationFn: (payload: CreateTimeOffPayload) => timeOffApi.create(technicianId, payload),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: technicianAuthoringPredicate(technicianId, 'time-off', tenantId, companyId),
      })
    },
  })
}

export function useUpdateTimeOff(technicianId: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkshopTechniciansTenantScope()

  return useMutation({
    mutationFn: (args: { timeOffId: string; payload: UpdateTimeOffPayload }) =>
      timeOffApi.update(technicianId, args.timeOffId, args.payload),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: technicianAuthoringPredicate(technicianId, 'time-off', tenantId, companyId),
      })
    },
  })
}

export function useDeleteTimeOff(technicianId: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkshopTechniciansTenantScope()

  return useMutation({
    mutationFn: (timeOffId: string) => timeOffApi.remove(technicianId, timeOffId),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: technicianAuthoringPredicate(technicianId, 'time-off', tenantId, companyId),
      })
    },
  })
}

// ----------------------- Time entries --------------------------------------

export function useTimeEntries(technicianId: string | undefined, range?: DateRangeFilter) {
  const { hasTenantScope } = useWorkshopTechniciansTenantScope()

  return useQuery<TechnicianTimeEntry[]>({
    queryKey: tenantScopedKey([...authoringKeys.timeEntries(technicianId ?? '', range)]),
    queryFn: () => {
      if (typeof technicianId !== 'string' || technicianId.length === 0) {
        return Promise.reject(new Error('technicianId required'))
      }
      return timeEntryApi.list(technicianId, range)
    },
    enabled: typeof technicianId === 'string' && technicianId.length > 0 && hasTenantScope,
  })
}

export function useCreateTimeEntry(technicianId: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkshopTechniciansTenantScope()

  return useMutation({
    mutationFn: (payload: CreateTimeEntryPayload) =>
      timeEntryApi.create(technicianId, payload),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: technicianAuthoringPredicate(technicianId, 'time-entries', tenantId, companyId),
      })
    },
  })
}

export function useUpdateTimeEntry(technicianId: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkshopTechniciansTenantScope()

  return useMutation({
    mutationFn: (args: { timeEntryId: string; payload: UpdateTimeEntryPayload }) =>
      timeEntryApi.update(technicianId, args.timeEntryId, args.payload),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: technicianAuthoringPredicate(technicianId, 'time-entries', tenantId, companyId),
      })
    },
  })
}

export function useDeleteTimeEntry(technicianId: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkshopTechniciansTenantScope()

  return useMutation({
    mutationFn: (timeEntryId: string) => timeEntryApi.remove(technicianId, timeEntryId),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: technicianAuthoringPredicate(technicianId, 'time-entries', tenantId, companyId),
      })
    },
  })
}
