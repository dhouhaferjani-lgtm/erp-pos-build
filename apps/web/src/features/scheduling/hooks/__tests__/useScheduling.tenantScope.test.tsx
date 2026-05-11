import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import type {
  Appointment,
  AppointmentListFilters,
  Bay,
  BookAppointmentInput,
  DayViewData,
  PaginatedAppointments,
  ScheduleConfig,
  WeekViewData,
} from '../../types'
import {
  schedulingKeys,
  useAppointment,
  useAppointments,
  useBays,
  useBookAppointment,
  useCancelAppointment,
  useCheckInAppointment,
  useConfirmAppointment,
  useConvertAppointment,
  useDayView,
  useFreeSlots,
  useRescheduleAppointment,
  useScheduleConfig,
  useUpdateAppointment,
  useWeekView,
} from '../useScheduling'

const mockListAppointments = vi.hoisted(() => vi.fn())
const mockGetAppointment = vi.hoisted(() => vi.fn())
const mockBookAppointment = vi.hoisted(() => vi.fn())
const mockUpdateAppointment = vi.hoisted(() => vi.fn())
const mockConfirmAppointment = vi.hoisted(() => vi.fn())
const mockRescheduleAppointment = vi.hoisted(() => vi.fn())
const mockCheckInAppointment = vi.hoisted(() => vi.fn())
const mockCancelAppointment = vi.hoisted(() => vi.fn())
const mockConvertAppointment = vi.hoisted(() => vi.fn())
const mockListBays = vi.hoisted(() => vi.fn())
const mockGetScheduleConfig = vi.hoisted(() => vi.fn())
const mockDayView = vi.hoisted(() => vi.fn())
const mockWeekView = vi.hoisted(() => vi.fn())
const mockFreeSlots = vi.hoisted(() => vi.fn())

vi.mock('../../api/schedulingApi', () => ({
  schedulingApi: {
    listAppointments: mockListAppointments,
    getAppointment: mockGetAppointment,
    bookAppointment: mockBookAppointment,
    updateAppointment: mockUpdateAppointment,
    confirmAppointment: mockConfirmAppointment,
    rescheduleAppointment: mockRescheduleAppointment,
    checkInAppointment: mockCheckInAppointment,
    cancelAppointment: mockCancelAppointment,
    convertAppointment: mockConvertAppointment,
    listBays: mockListBays,
    getScheduleConfig: mockGetScheduleConfig,
    dayView: mockDayView,
    weekView: mockWeekView,
    freeSlots: mockFreeSlots,
  },
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function makeWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function cacheKeys(queryClient: QueryClient): unknown[][] {
  return queryClient
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

function appointmentFixture(id: string): Appointment {
  return {
    id,
    tenant_id: 'tenant-A',
    company_id: 'company-1',
    location_id: 'location-1',
    appointment_number: `APT-${id}`,
    bay_id: 'bay-1',
    primary_technician_profile_id: null,
    customer_partner_id: null,
    vehicle_id: null,
    customer_name: 'Customer',
    customer_phone: null,
    customer_email: null,
    vehicle_plate: null,
    vehicle_description: null,
    appointment_type: 'standard_repair',
    wait_type: 'drop_off',
    status: 'scheduled',
    scheduled_start: '2026-05-11T10:00:00Z',
    scheduled_end: '2026-05-11T11:00:00Z',
    estimated_duration_minutes: 60,
    actual_arrival_at: null,
    services_summary: null,
    customer_notes: null,
    internal_notes: null,
    color_label: null,
    source: 'manual',
    work_order_id: null,
    created_at: '2026-05-11T09:00:00Z',
    updated_at: '2026-05-11T09:00:00Z',
  }
}

function appointmentsPage(id: string): PaginatedAppointments {
  return {
    data: [appointmentFixture(id)],
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: 1,
    },
  }
}

function bayFixture(): Bay {
  return {
    id: 'bay-1',
    tenant_id: 'tenant-A',
    company_id: 'company-1',
    location_id: 'location-1',
    code: 'B1',
    name: 'Bay 1',
    bay_type: 'general',
    display_order: 1,
    operating_hours: {},
    notes: null,
    is_active: true,
    created_at: '2026-05-11T09:00:00Z',
    updated_at: '2026-05-11T09:00:00Z',
  }
}

function scheduleConfigFixture(): ScheduleConfig {
  return {
    id: 'config-1',
    tenant_id: 'tenant-A',
    company_id: 'company-1',
    location_id: 'location-1',
    time_slot_minutes: 30,
    default_appointment_duration_minutes: 60,
    walk_in_buffer_hours_per_day: '2.00',
    overbooking_threshold_percent: 100,
    online_booking_enabled: false,
    online_booking_advance_days: 14,
    online_booking_min_notice_hours: 24,
    online_booking_auto_confirm: false,
    reminder_sms_hours_before: null,
    reminder_email_hours_before: null,
  }
}

const dayViewFixture: DayViewData = {
  date: '2026-05-11',
  availability: {},
  booked: {},
}

const weekViewFixture: WeekViewData = {
  '2026-05-11': [],
}

const freeSlotParams = {
  duration: 60,
  from: '2026-05-11T10:00:00Z',
  to: '2026-05-11T18:00:00Z',
}

const bookAppointmentInput: BookAppointmentInput = {
  location_id: 'location-1',
  appointment_type: 'standard_repair',
  scheduled_start: '2026-05-11T10:00:00Z',
  scheduled_end: '2026-05-11T11:00:00Z',
  estimated_duration_minutes: 60,
  planned_services: [],
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')

  mockListAppointments.mockResolvedValue(appointmentsPage('appointment-1'))
  mockGetAppointment.mockResolvedValue(appointmentFixture('appointment-1'))
  mockBookAppointment.mockResolvedValue(appointmentFixture('appointment-new'))
  mockUpdateAppointment.mockResolvedValue(appointmentFixture('appointment-1'))
  mockConfirmAppointment.mockResolvedValue({ id: 'appointment-1', status: 'confirmed' })
  mockRescheduleAppointment.mockResolvedValue({
    id: 'appointment-1',
    status: 'scheduled',
    scheduled_start: '2026-05-11T12:00:00Z',
    scheduled_end: '2026-05-11T13:00:00Z',
    bay_id: 'bay-1',
  })
  mockCheckInAppointment.mockResolvedValue({
    id: 'appointment-1',
    status: 'checked_in',
    actual_arrival_at: '2026-05-11T10:05:00Z',
  })
  mockCancelAppointment.mockResolvedValue({ id: 'appointment-1', status: 'cancelled' })
  mockConvertAppointment.mockResolvedValue({
    appointment_id: 'appointment-1',
    work_order_id: 'work-order-1',
  })
  mockListBays.mockResolvedValue([bayFixture()])
  mockGetScheduleConfig.mockResolvedValue(scheduleConfigFixture())
  mockDayView.mockResolvedValue(dayViewFixture)
  mockWeekView.mockResolvedValue(weekViewFixture)
  mockFreeSlots.mockResolvedValue([])
})

afterEach(() => {
  resetTenant()
})

describe('useScheduling tenant scope', () => {
  it('wraps all scheduling read query keys with the active tenant and company (.591-.597)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    const filters: AppointmentListFilters = { status: 'scheduled' }

    const { result } = renderHook(() => ({
      appointments: useAppointments(filters),
      appointment: useAppointment('appointment-1'),
      bays: useBays(),
      config: useScheduleConfig('location-1'),
      day: useDayView('2026-05-11'),
      week: useWeekView('2026-05-11'),
      freeSlots: useFreeSlots(freeSlotParams),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.appointments.isSuccess).toBe(true)
      expect(result.current.appointment.isSuccess).toBe(true)
      expect(result.current.bays.isSuccess).toBe(true)
      expect(result.current.config.isSuccess).toBe(true)
      expect(result.current.day.isSuccess).toBe(true)
      expect(result.current.week.isSuccess).toBe(true)
      expect(result.current.freeSlots.isSuccess).toBe(true)
      expect(mockListAppointments).toHaveBeenCalledTimes(1)
      expect(mockGetAppointment).toHaveBeenCalledTimes(1)
      expect(mockListBays).toHaveBeenCalledTimes(1)
      expect(mockGetScheduleConfig).toHaveBeenCalledTimes(1)
      expect(mockDayView).toHaveBeenCalledTimes(1)
      expect(mockWeekView).toHaveBeenCalledTimes(1)
      expect(mockFreeSlots).toHaveBeenCalledTimes(1)
    })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['scheduling', 'appointments', 'list', filters, 'tenant-A', 'company-1'],
      ['scheduling', 'appointments', 'detail', 'appointment-1', 'tenant-A', 'company-1'],
      ['scheduling', 'bays', 'tenant-A', 'company-1'],
      ['scheduling', 'config', 'location-1', 'tenant-A', 'company-1'],
      ['scheduling', 'day', '2026-05-11', 'tenant-A', 'company-1'],
      ['scheduling', 'week', '2026-05-11', 'tenant-A', 'company-1'],
      ['scheduling', 'free-slots', freeSlotParams, 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch scheduling data without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => useAppointments(), { wrapper })
    renderHook(() => useAppointment('appointment-1'), { wrapper })
    renderHook(() => useBays(), { wrapper })
    renderHook(() => useScheduleConfig('location-1'), { wrapper })
    renderHook(() => useDayView('2026-05-11'), { wrapper })
    renderHook(() => useWeekView('2026-05-11'), { wrapper })
    renderHook(() => useFreeSlots(freeSlotParams), { wrapper })

    expect(mockListAppointments).not.toHaveBeenCalled()
    expect(mockGetAppointment).not.toHaveBeenCalled()
    expect(mockListBays).not.toHaveBeenCalled()
    expect(mockGetScheduleConfig).not.toHaveBeenCalled()
    expect(mockDayView).not.toHaveBeenCalled()
    expect(mockWeekView).not.toHaveBeenCalled()
    expect(mockFreeSlots).not.toHaveBeenCalled()
  })

  it('bounds every scheduling mutation invalidation to the active tenant cache (.598-.611)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    const filters: AppointmentListFilters = { status: 'scheduled' }
    let listCalls = 0

    mockListAppointments.mockImplementation(async () => {
      listCalls += 1
      return appointmentsPage(`appointment-${listCalls}`)
    })

    queryClient.setQueryData(
      ['scheduling', 'appointments', 'list', filters, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-preserved' },
    )

    const { result: list } = renderHook(() => useAppointments(filters), { wrapper })
    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(listCalls).toBe(1)
    })

    const { result: mutations } = renderHook(() => ({
      book: useBookAppointment(),
      update: useUpdateAppointment('appointment-1'),
      confirm: useConfirmAppointment('appointment-1'),
      reschedule: useRescheduleAppointment('appointment-1'),
      checkIn: useCheckInAppointment('appointment-1'),
      cancel: useCancelAppointment('appointment-1'),
      convert: useConvertAppointment('appointment-1'),
    }), { wrapper })

    await act(async () => {
      await mutations.current.book.mutateAsync(bookAppointmentInput)
    })
    await waitFor(() => { expect(listCalls).toBe(2) })

    await act(async () => {
      await mutations.current.update.mutateAsync({ customer_name: 'Updated Customer' })
    })
    await waitFor(() => { expect(listCalls).toBe(3) })

    await act(async () => {
      await mutations.current.confirm.mutateAsync()
    })
    await waitFor(() => { expect(listCalls).toBe(4) })

    await act(async () => {
      await mutations.current.reschedule.mutateAsync({
        new_scheduled_start: '2026-05-11T12:00:00Z',
        new_scheduled_end: '2026-05-11T13:00:00Z',
      })
    })
    await waitFor(() => { expect(listCalls).toBe(5) })

    await act(async () => {
      await mutations.current.checkIn.mutateAsync({
        actual_arrival_at: '2026-05-11T10:05:00Z',
      })
    })
    await waitFor(() => { expect(listCalls).toBe(6) })

    await act(async () => {
      await mutations.current.cancel.mutateAsync({ reason_code: 'customer_cancelled' })
    })
    await waitFor(() => { expect(listCalls).toBe(7) })

    await act(async () => {
      await mutations.current.convert.mutateAsync()
    })
    await waitFor(() => { expect(listCalls).toBe(8) })

    expect(queryClient.getQueryData([
      'scheduling',
      'appointments',
      'list',
      filters,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-preserved' })
    expect(queryClient.getQueryData([
      ...schedulingKeys.appointmentList(filters),
      'tenant-A',
      'company-1',
    ])).toEqual(appointmentsPage('appointment-8'))
  })
})
