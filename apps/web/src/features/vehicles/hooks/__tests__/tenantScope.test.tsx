import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import type { LogMileagePayload } from '../../api/vehicleMileageApi'
import type { TransferOwnershipPayload } from '../../api/vehicleOwnershipApi'
import type { VehicleData, VehicleMileageReadingData, VehicleOwnershipData } from '../../types'
import { useLogVehicleMileage } from '../useLogVehicleMileage'
import { usePartnerVehicles } from '../usePartnerVehicles'
import { useTransferVehicleOwnership } from '../useTransferVehicleOwnership'
import { useVehicleMileageHistory } from '../useVehicleMileageHistory'
import { useVehicleOwnershipHistory } from '../useVehicleOwnershipHistory'
import { useVehicleWithCurrentOwner, type VehicleWithCurrentOwner } from '../useVehicleWithCurrentOwner'

const mockFetchMileageHistory = vi.hoisted(() => vi.fn())
const mockLogMileage = vi.hoisted(() => vi.fn())
const mockFetchOwnershipHistory = vi.hoisted(() => vi.fn())
const mockTransferOwnership = vi.hoisted(() => vi.fn())
const mockFetchVehiclesForPartner = vi.hoisted(() => vi.fn())
const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('../../api/vehicleMileageApi', () => ({
  fetchMileageHistory: mockFetchMileageHistory,
  logMileage: mockLogMileage,
}))

vi.mock('../../api/vehicleOwnershipApi', () => ({
  fetchOwnershipHistory: mockFetchOwnershipHistory,
  transferOwnership: mockTransferOwnership,
}))

vi.mock('../../api/partnerVehiclesApi', () => ({
  fetchVehiclesForPartner: mockFetchVehiclesForPartner,
}))

vi.mock('@/lib/api', () => ({
  api: {
    get: mockApiGet,
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

function vehicleFixture(id: string): VehicleData {
  return {
    id,
    tenant_id: 'tenant-A',
    company_id: 'company-1',
    license_plate: '123-TN-456',
    brand: 'Brand',
    model: 'Model',
    year: 2024,
    color: null,
    mileage: 1000,
    vin: null,
    engine_code: null,
    fuel_type: 'gasoline',
    transmission: 'manual',
    body_type: 'sedan',
    notes: null,
    current_owner_partner_id: 'partner-1',
    current_owner_display_name: 'Partner',
    partner_id: 'partner-1',
    created_at: '2026-05-11T09:00:00Z',
    updated_at: null,
  }
}

function mileageFixture(id: string): VehicleMileageReadingData {
  return {
    id,
    vehicle_id: 'vehicle-1',
    mileage: 1000,
    recorded_at: '2026-05-11T09:00:00Z',
    source: 'manual',
    context_document_id: null,
    context_work_order_id: null,
    notes: null,
  }
}

function ownershipFixture(id: string): VehicleOwnershipData {
  return {
    id,
    vehicle_id: 'vehicle-1',
    owner_partner_id: 'partner-1',
    owner_display_name: 'Partner',
    acquired_at: '2026-05-11T09:00:00Z',
    released_at: null,
    reason_code: 'initial_registration',
    notes: null,
    recorded_by_user_id: 'user-1',
  }
}

function vehicleWithOwnerFixture(id: string): VehicleWithCurrentOwner {
  return {
    ...vehicleFixture(id),
    current_ownership: ownershipFixture('ownership-1'),
    recent_mileage_readings: [mileageFixture('mileage-1')],
  }
}

const logMileagePayload: LogMileagePayload = {
  mileage: 1200,
  recorded_at: '2026-05-11T10:00:00Z',
  source: 'manual',
}

const transferPayload: TransferOwnershipPayload = {
  new_owner_partner_id: 'partner-2',
  occurred_at: '2026-05-11T10:00:00Z',
  reason_code: 'transfer',
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockFetchMileageHistory.mockResolvedValue([mileageFixture('mileage-1')])
  mockLogMileage.mockResolvedValue(mileageFixture('mileage-new'))
  mockFetchOwnershipHistory.mockResolvedValue([ownershipFixture('ownership-1')])
  mockTransferOwnership.mockResolvedValue(ownershipFixture('ownership-new'))
  mockFetchVehiclesForPartner.mockResolvedValue({
    data: [vehicleFixture('vehicle-1')],
    meta: {
      current_page: 1,
      per_page: 15,
      total: 1,
      last_page: 1,
    },
  })
  mockApiGet.mockResolvedValue({ data: { data: vehicleWithOwnerFixture('vehicle-1') } })
})

afterEach(() => {
  resetTenant()
})

describe('vehicle hooks tenant scope', () => {
  it('wraps vehicle read query keys with the active tenant and company (.764, .768-.770)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => ({
      partnerVehicles: usePartnerVehicles('partner-1', 15),
      mileage: useVehicleMileageHistory('vehicle-1'),
      ownerships: useVehicleOwnershipHistory('vehicle-1'),
      vehicleWithOwner: useVehicleWithCurrentOwner('vehicle-1'),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.partnerVehicles.isSuccess).toBe(true)
      expect(result.current.mileage.isSuccess).toBe(true)
      expect(result.current.ownerships.isSuccess).toBe(true)
      expect(result.current.vehicleWithOwner.isSuccess).toBe(true)
    })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['partner-vehicles', 'partner-1', 15, 'tenant-A', 'company-1'],
      ['vehicle', 'vehicle-1', 'mileage', 'tenant-A', 'company-1'],
      ['vehicle', 'vehicle-1', 'ownerships', 'tenant-A', 'company-1'],
      ['vehicle-with-owner', 'vehicle-1', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch vehicle reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      partnerVehicles: usePartnerVehicles('partner-1', 15),
      mileage: useVehicleMileageHistory('vehicle-1'),
      ownerships: useVehicleOwnershipHistory('vehicle-1'),
      vehicleWithOwner: useVehicleWithCurrentOwner('vehicle-1'),
    }), { wrapper })

    expect(mockFetchVehiclesForPartner).not.toHaveBeenCalled()
    expect(mockFetchMileageHistory).not.toHaveBeenCalled()
    expect(mockFetchOwnershipHistory).not.toHaveBeenCalled()
    expect(mockApiGet).not.toHaveBeenCalled()
  })

  it('bounds vehicle mutation invalidation to the active tenant cache (.762-.763, .765-.767)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let mileageCalls = 0
    let ownershipCalls = 0
    let partnerVehicleCalls = 0

    mockFetchMileageHistory.mockImplementation(async () => {
      mileageCalls += 1
      return [mileageFixture(`mileage-${mileageCalls}`)]
    })
    mockFetchOwnershipHistory.mockImplementation(async () => {
      ownershipCalls += 1
      return [ownershipFixture(`ownership-${ownershipCalls}`)]
    })
    mockFetchVehiclesForPartner.mockImplementation(async () => {
      partnerVehicleCalls += 1
      return {
        data: [vehicleFixture(`vehicle-${partnerVehicleCalls}`)],
        meta: {
          current_page: 1,
          per_page: 15,
          total: 1,
          last_page: 1,
        },
      }
    })

    queryClient.setQueryData(
      ['vehicle', 'vehicle-1', 'mileage', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-mileage-preserved' },
    )
    queryClient.setQueryData(
      ['vehicle', 'vehicle-1', 'ownerships', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-ownerships-preserved' },
    )
    queryClient.setQueryData(
      ['partner-vehicles', 'partner-2', 15, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-partner-vehicles-preserved' },
    )

    const { result: reads } = renderHook(() => ({
      mileage: useVehicleMileageHistory('vehicle-1'),
      ownerships: useVehicleOwnershipHistory('vehicle-1'),
      newPartnerVehicles: usePartnerVehicles('partner-2', 15),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.mileage.isSuccess).toBe(true)
      expect(reads.current.ownerships.isSuccess).toBe(true)
      expect(reads.current.newPartnerVehicles.isSuccess).toBe(true)
      expect(mileageCalls).toBe(1)
      expect(ownershipCalls).toBe(1)
      expect(partnerVehicleCalls).toBe(1)
    })

    const { result: mutations } = renderHook(() => ({
      logMileage: useLogVehicleMileage('vehicle-1'),
      transferOwnership: useTransferVehicleOwnership('vehicle-1'),
    }), { wrapper })

    await act(async () => {
      await mutations.current.logMileage.mutateAsync(logMileagePayload)
    })
    await waitFor(() => {
      expect(mileageCalls).toBe(2)
      expect(ownershipCalls).toBe(1)
      expect(partnerVehicleCalls).toBe(1)
    })

    await act(async () => {
      await mutations.current.transferOwnership.mutateAsync(transferPayload)
    })
    await waitFor(() => {
      expect(mileageCalls).toBe(2)
      expect(ownershipCalls).toBe(2)
      expect(partnerVehicleCalls).toBe(2)
    })

    expect(queryClient.getQueryData([
      'vehicle',
      'vehicle-1',
      'mileage',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-mileage-preserved' })
    expect(queryClient.getQueryData([
      'vehicle',
      'vehicle-1',
      'ownerships',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-ownerships-preserved' })
    expect(queryClient.getQueryData([
      'partner-vehicles',
      'partner-2',
      15,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-partner-vehicles-preserved' })
  })
})
