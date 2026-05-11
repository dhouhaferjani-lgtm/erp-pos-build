import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import type {
  AvailabilityQuery,
  TechnicianListFilters,
  TechnicianProfile,
} from '../../api/types'
import type {
  CreateCertificationPayload,
  CreateTimeEntryPayload,
  CreateTimeOffPayload,
  DateRangeFilter,
  TechnicianCertification,
  TechnicianTimeEntry,
  TechnicianTimeOff,
} from '../../api/authoringTypes'
import {
  useCreateCertification,
  useCreateTimeEntry,
  useCreateTimeOff,
  useDeleteCertification,
  useDeleteTimeEntry,
  useDeleteTimeOff,
  useTechnicianCertifications,
  useTimeEntries,
  useTimeOff,
  useUpdateCertification,
  useUpdateTimeEntry,
  useUpdateTimeOff,
} from '../useAuthoring'
import {
  useTechnician,
  useTechnicianAvailability,
  useTechnicians,
} from '../useTechnicians'

const mockCertificationList = vi.hoisted(() => vi.fn())
const mockCertificationCreate = vi.hoisted(() => vi.fn())
const mockCertificationUpdate = vi.hoisted(() => vi.fn())
const mockCertificationRemove = vi.hoisted(() => vi.fn())
const mockTimeOffList = vi.hoisted(() => vi.fn())
const mockTimeOffCreate = vi.hoisted(() => vi.fn())
const mockTimeOffUpdate = vi.hoisted(() => vi.fn())
const mockTimeOffRemove = vi.hoisted(() => vi.fn())
const mockTimeEntryList = vi.hoisted(() => vi.fn())
const mockTimeEntryCreate = vi.hoisted(() => vi.fn())
const mockTimeEntryUpdate = vi.hoisted(() => vi.fn())
const mockTimeEntryRemove = vi.hoisted(() => vi.fn())
const mockTechnicianList = vi.hoisted(() => vi.fn())
const mockTechnicianGet = vi.hoisted(() => vi.fn())
const mockTechnicianAvailable = vi.hoisted(() => vi.fn())

vi.mock('../../api/authoringApi', () => ({
  certificationApi: {
    list: mockCertificationList,
    create: mockCertificationCreate,
    update: mockCertificationUpdate,
    remove: mockCertificationRemove,
  },
  timeOffApi: {
    list: mockTimeOffList,
    create: mockTimeOffCreate,
    update: mockTimeOffUpdate,
    remove: mockTimeOffRemove,
  },
  timeEntryApi: {
    list: mockTimeEntryList,
    create: mockTimeEntryCreate,
    update: mockTimeEntryUpdate,
    remove: mockTimeEntryRemove,
  },
}))

vi.mock('../../api/technicianApi', () => ({
  technicianApi: {
    list: mockTechnicianList,
    get: mockTechnicianGet,
    available: mockTechnicianAvailable,
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

function technicianFixture(id: string): TechnicianProfile {
  return {
    id,
    tenant_id: 'tenant-A',
    company_id: 'company-1',
    user_id: 'user-1',
    user_display_name: `Technician ${id}`,
    user_email: 'tech@example.test',
    skill_level: 'general',
    specialties: ['general_service'],
    currency: 'TND',
    weekly_schedule: {
      mon: [],
      tue: [],
      wed: [],
      thu: [],
      fri: [],
      sat: [],
      sun: [],
    },
    hire_date: null,
    employment_status: 'active',
    employee_code: null,
    notes: null,
    is_active: true,
    created_at: '2026-05-11T09:00:00Z',
    updated_at: null,
  }
}

function certificationFixture(id: string): TechnicianCertification {
  return {
    id,
    technician_profile_id: 'tech-1',
    certification_name: `Certification ${id}`,
    issuing_body: null,
    certificate_number: null,
    issued_at: null,
    expires_at: null,
    notes: null,
    created_at: '2026-05-11T09:00:00Z',
  }
}

function timeOffFixture(id: string): TechnicianTimeOff {
  return {
    id,
    technician_profile_id: 'tech-1',
    starts_at: '2026-05-11T09:00:00Z',
    ends_at: '2026-05-11T17:00:00Z',
    reason_code: 'training',
    is_full_day: true,
    is_approved: false,
    approved_by_user_id: null,
    notes: null,
  }
}

function timeEntryFixture(id: string): TechnicianTimeEntry {
  return {
    id,
    technician_profile_id: 'tech-1',
    company_id: 'company-1',
    started_at: '2026-05-11T09:00:00Z',
    ended_at: '2026-05-11T10:00:00Z',
    duration_minutes: 60,
    entry_type: 'manual_adjust',
    work_order_id: null,
    work_order_status: null,
    source: 'manual',
    recorded_by_user_id: 'user-1',
    notes: null,
  }
}

const range: DateRangeFilter = {
  from: '2026-05-11',
  to: '2026-05-12',
}

const filters: TechnicianListFilters = {
  active_only: true,
}

const availabilityQuery: AvailabilityQuery = {
  technician_profile_id: 'tech-1',
  starts_at: '2026-05-11T09:00:00Z',
  duration_minutes: 60,
}

const certificationPayload: CreateCertificationPayload = {
  certification_name: 'Certification',
}

const timeOffPayload: CreateTimeOffPayload = {
  reason_code: 'training',
  starts_at: '2026-05-11T09:00:00Z',
  ends_at: '2026-05-11T17:00:00Z',
}

const timeEntryPayload: CreateTimeEntryPayload = {
  started_at: '2026-05-11T09:00:00Z',
  ended_at: '2026-05-11T10:00:00Z',
  entry_type: 'manual_adjust',
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockCertificationList.mockResolvedValue([certificationFixture('cert-1')])
  mockCertificationCreate.mockResolvedValue(certificationFixture('cert-new'))
  mockCertificationUpdate.mockResolvedValue(certificationFixture('cert-1'))
  mockCertificationRemove.mockResolvedValue(undefined)
  mockTimeOffList.mockResolvedValue([timeOffFixture('time-off-1')])
  mockTimeOffCreate.mockResolvedValue(timeOffFixture('time-off-new'))
  mockTimeOffUpdate.mockResolvedValue(timeOffFixture('time-off-1'))
  mockTimeOffRemove.mockResolvedValue(undefined)
  mockTimeEntryList.mockResolvedValue([timeEntryFixture('time-entry-1')])
  mockTimeEntryCreate.mockResolvedValue(timeEntryFixture('time-entry-new'))
  mockTimeEntryUpdate.mockResolvedValue(timeEntryFixture('time-entry-1'))
  mockTimeEntryRemove.mockResolvedValue(undefined)
  mockTechnicianList.mockResolvedValue([technicianFixture('tech-1')])
  mockTechnicianGet.mockResolvedValue(technicianFixture('tech-1'))
  mockTechnicianAvailable.mockResolvedValue({ status: 'yes', reason: 'available' })
})

afterEach(() => {
  resetTenant()
})

describe('workshop technician hooks tenant scope', () => {
  it('wraps technician and authoring read query keys with the active tenant and company (.811, .815, .819, .823-.825)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => ({
      certifications: useTechnicianCertifications('tech-1'),
      timeOff: useTimeOff('tech-1', range),
      timeEntries: useTimeEntries('tech-1', range),
      technicians: useTechnicians(filters),
      technician: useTechnician('tech-1'),
      availability: useTechnicianAvailability(availabilityQuery),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.certifications.isSuccess).toBe(true)
      expect(result.current.timeOff.isSuccess).toBe(true)
      expect(result.current.timeEntries.isSuccess).toBe(true)
      expect(result.current.technicians.isSuccess).toBe(true)
      expect(result.current.technician.isSuccess).toBe(true)
      expect(result.current.availability.isSuccess).toBe(true)
    })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['workshop-technicians', 'tech-1', 'certifications', 'tenant-A', 'company-1'],
      ['workshop-technicians', 'tech-1', 'time-off', range, 'tenant-A', 'company-1'],
      ['workshop-technicians', 'tech-1', 'time-entries', range, 'tenant-A', 'company-1'],
      ['workshop-technicians', 'list', filters, 'tenant-A', 'company-1'],
      ['workshop-technicians', 'detail', 'tech-1', 'tenant-A', 'company-1'],
      ['workshop-technicians', 'availability', availabilityQuery, 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch technician reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      certifications: useTechnicianCertifications('tech-1'),
      timeOff: useTimeOff('tech-1', range),
      timeEntries: useTimeEntries('tech-1', range),
      technicians: useTechnicians(filters),
      technician: useTechnician('tech-1'),
      availability: useTechnicianAvailability(availabilityQuery),
    }), { wrapper })

    expect(mockCertificationList).not.toHaveBeenCalled()
    expect(mockTimeOffList).not.toHaveBeenCalled()
    expect(mockTimeEntryList).not.toHaveBeenCalled()
    expect(mockTechnicianList).not.toHaveBeenCalled()
    expect(mockTechnicianGet).not.toHaveBeenCalled()
    expect(mockTechnicianAvailable).not.toHaveBeenCalled()
  })

  it('bounds authoring mutation invalidation to the active tenant cache (.812-.814, .816-.818, .820-.822)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let certCalls = 0
    let timeOffCalls = 0
    let timeEntryCalls = 0

    mockCertificationList.mockImplementation(async () => {
      certCalls += 1
      return [certificationFixture(`cert-${certCalls}`)]
    })
    mockTimeOffList.mockImplementation(async () => {
      timeOffCalls += 1
      return [timeOffFixture(`time-off-${timeOffCalls}`)]
    })
    mockTimeEntryList.mockImplementation(async () => {
      timeEntryCalls += 1
      return [timeEntryFixture(`time-entry-${timeEntryCalls}`)]
    })

    queryClient.setQueryData(
      ['workshop-technicians', 'tech-1', 'certifications', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-certifications-preserved' },
    )
    queryClient.setQueryData(
      ['workshop-technicians', 'tech-1', 'time-off', range, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-time-off-preserved' },
    )
    queryClient.setQueryData(
      ['workshop-technicians', 'tech-1', 'time-entries', range, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-time-entries-preserved' },
    )

    const { result: reads } = renderHook(() => ({
      certifications: useTechnicianCertifications('tech-1'),
      timeOff: useTimeOff('tech-1', range),
      timeEntries: useTimeEntries('tech-1', range),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.certifications.isSuccess).toBe(true)
      expect(reads.current.timeOff.isSuccess).toBe(true)
      expect(reads.current.timeEntries.isSuccess).toBe(true)
      expect(certCalls).toBe(1)
      expect(timeOffCalls).toBe(1)
      expect(timeEntryCalls).toBe(1)
    })

    const { result: mutations } = renderHook(() => ({
      createCertification: useCreateCertification('tech-1'),
      updateCertification: useUpdateCertification('tech-1'),
      deleteCertification: useDeleteCertification('tech-1'),
      createTimeOff: useCreateTimeOff('tech-1'),
      updateTimeOff: useUpdateTimeOff('tech-1'),
      deleteTimeOff: useDeleteTimeOff('tech-1'),
      createTimeEntry: useCreateTimeEntry('tech-1'),
      updateTimeEntry: useUpdateTimeEntry('tech-1'),
      deleteTimeEntry: useDeleteTimeEntry('tech-1'),
    }), { wrapper })

    await act(async () => {
      await mutations.current.createCertification.mutateAsync(certificationPayload)
    })
    await waitFor(() => { expect(certCalls).toBe(2) })

    await act(async () => {
      await mutations.current.updateCertification.mutateAsync({
        certificationId: 'cert-1',
        payload: { certification_name: 'Updated certification' },
      })
    })
    await waitFor(() => { expect(certCalls).toBe(3) })

    await act(async () => {
      await mutations.current.deleteCertification.mutateAsync('cert-1')
    })
    await waitFor(() => { expect(certCalls).toBe(4) })

    await act(async () => {
      await mutations.current.createTimeOff.mutateAsync(timeOffPayload)
    })
    await waitFor(() => { expect(timeOffCalls).toBe(2) })

    await act(async () => {
      await mutations.current.updateTimeOff.mutateAsync({
        timeOffId: 'time-off-1',
        payload: { notes: 'Updated time off' },
      })
    })
    await waitFor(() => { expect(timeOffCalls).toBe(3) })

    await act(async () => {
      await mutations.current.deleteTimeOff.mutateAsync('time-off-1')
    })
    await waitFor(() => { expect(timeOffCalls).toBe(4) })

    await act(async () => {
      await mutations.current.createTimeEntry.mutateAsync(timeEntryPayload)
    })
    await waitFor(() => { expect(timeEntryCalls).toBe(2) })

    await act(async () => {
      await mutations.current.updateTimeEntry.mutateAsync({
        timeEntryId: 'time-entry-1',
        payload: { notes: 'Updated time entry' },
      })
    })
    await waitFor(() => { expect(timeEntryCalls).toBe(3) })

    await act(async () => {
      await mutations.current.deleteTimeEntry.mutateAsync('time-entry-1')
    })
    await waitFor(() => { expect(timeEntryCalls).toBe(4) })

    expect(queryClient.getQueryData([
      'workshop-technicians',
      'tech-1',
      'certifications',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-certifications-preserved' })
    expect(queryClient.getQueryData([
      'workshop-technicians',
      'tech-1',
      'time-off',
      range,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-time-off-preserved' })
    expect(queryClient.getQueryData([
      'workshop-technicians',
      'tech-1',
      'time-entries',
      range,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-time-entries-preserved' })
  })
})
