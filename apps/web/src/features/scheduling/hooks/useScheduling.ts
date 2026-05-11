import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { schedulingApi } from '../api/schedulingApi'
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

/**
 * React Query key factory for the Scheduling feature.
 *
 * Every hook in this file pulls its key from this factory so invalidation
 * across mutations stays consistent.
 */
export const schedulingKeys = {
  all: ['scheduling'] as const,

  appointments: () => [...schedulingKeys.all, 'appointments'] as const,
  appointmentList: (filters: AppointmentListFilters) =>
    [...schedulingKeys.appointments(), 'list', filters] as const,
  appointmentDetail: (id: string) => [...schedulingKeys.appointments(), 'detail', id] as const,

  bays: () => [...schedulingKeys.all, 'bays'] as const,
  config: (locationId: string) => [...schedulingKeys.all, 'config', locationId] as const,

  day: (date: string) => [...schedulingKeys.all, 'day', date] as const,
  week: (weekStart: string) => [...schedulingKeys.all, 'week', weekStart] as const,
  freeSlots: (params: { duration: number; from: string; to: string }) =>
    [...schedulingKeys.all, 'free-slots', params] as const,
}

function useSchedulingTenantScope(): {
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

function scopedSchedulingPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'scheduling' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

// ----- Appointments queries -----

export function useAppointments(filters: AppointmentListFilters = {}) {
  const { hasTenantScope } = useSchedulingTenantScope()

  return useQuery<PaginatedAppointments>({
    queryKey: tenantScopedKey([...schedulingKeys.appointmentList(filters)]),
    queryFn: () => schedulingApi.listAppointments(filters),
    enabled: hasTenantScope,
    staleTime: 30 * 1000,
  })
}

export function useAppointment(id: string | undefined) {
  const { hasTenantScope } = useSchedulingTenantScope()

  return useQuery<Appointment>({
    queryKey: tenantScopedKey([...schedulingKeys.appointmentDetail(id ?? '')]),
    queryFn: () => {
      if (id === undefined || id === '') throw new Error('id is required')
      return schedulingApi.getAppointment(id)
    },
    enabled: typeof id === 'string' && id.length > 0 && hasTenantScope,
    staleTime: 30 * 1000,
  })
}

// ----- Appointments mutations -----

export function useBookAppointment() {
  const qc = useQueryClient()
  const { tenantId, companyId } = useSchedulingTenantScope()

  return useMutation<Appointment, Error, BookAppointmentInput>({
    mutationFn: (input) => schedulingApi.bookAppointment(input),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: scopedSchedulingPredicate(tenantId, companyId),
      })
    },
  })
}

export function useUpdateAppointment(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useSchedulingTenantScope()

  return useMutation<Appointment, Error, UpdateAppointmentInput>({
    mutationFn: (input) => schedulingApi.updateAppointment(id, input),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: scopedSchedulingPredicate(tenantId, companyId),
      })
    },
  })
}

export function useConfirmAppointment(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useSchedulingTenantScope()

  return useMutation<{ id: string; status: string }>({
    mutationFn: () => schedulingApi.confirmAppointment(id),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: scopedSchedulingPredicate(tenantId, companyId),
      })
    },
  })
}

export function useRescheduleAppointment(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useSchedulingTenantScope()

  return useMutation<
    { id: string; status: string; scheduled_start: string; scheduled_end: string; bay_id: string | null },
    Error,
    RescheduleAppointmentInput
  >({
    mutationFn: (input) => schedulingApi.rescheduleAppointment(id, input),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: scopedSchedulingPredicate(tenantId, companyId),
      })
    },
  })
}

export function useCheckInAppointment(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useSchedulingTenantScope()

  return useMutation<
    { id: string; status: string; actual_arrival_at: string | null },
    Error,
    CheckInAppointmentInput
  >({
    mutationFn: (input) => schedulingApi.checkInAppointment(id, input),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: scopedSchedulingPredicate(tenantId, companyId),
      })
    },
  })
}

export function useCancelAppointment(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useSchedulingTenantScope()

  return useMutation<{ id: string; status: string }, Error, CancelAppointmentInput>({
    mutationFn: (input) => schedulingApi.cancelAppointment(id, input),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: scopedSchedulingPredicate(tenantId, companyId),
      })
    },
  })
}

export function useConvertAppointment(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useSchedulingTenantScope()

  return useMutation<{ appointment_id: string; work_order_id: string }>({
    mutationFn: () => schedulingApi.convertAppointment(id),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: scopedSchedulingPredicate(tenantId, companyId),
      })
    },
  })
}

// ----- Bays -----

export function useBays() {
  const { hasTenantScope } = useSchedulingTenantScope()

  return useQuery<Bay[]>({
    queryKey: tenantScopedKey([...schedulingKeys.bays()]),
    queryFn: () => schedulingApi.listBays(),
    enabled: hasTenantScope,
    staleTime: 5 * 60 * 1000,
  })
}

// ----- Schedule config -----

export function useScheduleConfig(locationId: string | undefined) {
  const { hasTenantScope } = useSchedulingTenantScope()

  return useQuery<ScheduleConfig>({
    queryKey: tenantScopedKey([...schedulingKeys.config(locationId ?? '')]),
    queryFn: () => {
      if (locationId === undefined || locationId === '') {
        throw new Error('locationId is required')
      }
      return schedulingApi.getScheduleConfig(locationId)
    },
    enabled: typeof locationId === 'string' && locationId.length > 0 && hasTenantScope,
    staleTime: 5 * 60 * 1000,
  })
}

// ----- Calendar reads -----

export function useDayView(date: string | undefined) {
  const { hasTenantScope } = useSchedulingTenantScope()

  return useQuery<DayViewData>({
    queryKey: tenantScopedKey([...schedulingKeys.day(date ?? '')]),
    queryFn: () => {
      if (date === undefined || date === '') throw new Error('date is required')
      return schedulingApi.dayView(date)
    },
    enabled: typeof date === 'string' && date.length > 0 && hasTenantScope,
    staleTime: 30 * 1000,
  })
}

export function useWeekView(weekStart: string | undefined) {
  const { hasTenantScope } = useSchedulingTenantScope()

  return useQuery<WeekViewData>({
    queryKey: tenantScopedKey([...schedulingKeys.week(weekStart ?? '')]),
    queryFn: () => {
      if (weekStart === undefined || weekStart === '') {
        throw new Error('weekStart is required')
      }
      return schedulingApi.weekView(weekStart)
    },
    enabled: typeof weekStart === 'string' && weekStart.length > 0 && hasTenantScope,
    staleTime: 30 * 1000,
  })
}

export function useFreeSlots(params: { duration: number; from: string; to: string } | null) {
  const { hasTenantScope } = useSchedulingTenantScope()

  return useQuery<FreeSlotDTO[]>({
    queryKey: tenantScopedKey(
      params
        ? [...schedulingKeys.freeSlots(params)]
        : [...schedulingKeys.all, 'free-slots', 'idle'],
    ),
    queryFn: () => {
      if (params === null) throw new Error('params is required')
      return schedulingApi.freeSlots(params)
    },
    enabled: params !== null && hasTenantScope,
    staleTime: 30 * 1000,
  })
}
