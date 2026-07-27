import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import type {
  ApplicableBundlesParams,
  CreateBundlePayload,
  ListBundlesParams,
  UpdateBundlePayload,
} from '../../api/bundleApi'
import type { AddComponentPayload, SetApplicabilitiesPayload } from '../../api/bundleComponentApi'
import type {
  ApplicableBundleData,
  BundleExpansionLineData,
  ServiceBundleComponentData,
  ServiceBundleData,
  ServiceBundleVehicleApplicabilityData,
} from '../../types'
import {
  useAddBundleComponent,
  useApplicableBundles,
  useBundle,
  useBundleExpansion,
  useBundles,
  useCreateBundle,
  useDeleteBundle,
  useDeleteBundleComponent,
  useReplaceBundleApplicabilities,
  useUpdateBundle,
  useUpdateBundleComponent,
} from '../useBundles'
import { useUnits, type PickerUnit } from '../useUnits'

const mockListBundles = vi.hoisted(() => vi.fn())
const mockGetBundle = vi.hoisted(() => vi.fn())
const mockCreateBundle = vi.hoisted(() => vi.fn())
const mockUpdateBundle = vi.hoisted(() => vi.fn())
const mockDeleteBundle = vi.hoisted(() => vi.fn())
const mockListApplicableBundles = vi.hoisted(() => vi.fn())
const mockGetBundleExpansion = vi.hoisted(() => vi.fn())
const mockAddBundleComponent = vi.hoisted(() => vi.fn())
const mockUpdateBundleComponent = vi.hoisted(() => vi.fn())
const mockDeleteBundleComponent = vi.hoisted(() => vi.fn())
const mockReplaceBundleApplicabilities = vi.hoisted(() => vi.fn())
const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('../../api/bundleApi', () => ({
  listBundles: mockListBundles,
  getBundle: mockGetBundle,
  createBundle: mockCreateBundle,
  updateBundle: mockUpdateBundle,
  deleteBundle: mockDeleteBundle,
  listApplicableBundles: mockListApplicableBundles,
  getBundleExpansion: mockGetBundleExpansion,
}))

vi.mock('../../api/bundleComponentApi', () => ({
  addBundleComponent: mockAddBundleComponent,
  updateBundleComponent: mockUpdateBundleComponent,
  deleteBundleComponent: mockDeleteBundleComponent,
  replaceBundleApplicabilities: mockReplaceBundleApplicabilities,
}))

vi.mock('@/lib/api', () => ({
  apiGet: mockApiGet,
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

function bundleComponentFixture(): ServiceBundleComponentData {
  return {
    id: 'component-1',
    bundle_id: 'bundle-1',
    component_type: 'labor',
    component_id: 'labor-1',
    component_display_name: 'Labor',
    quantity: '1.000',
    quantity_decimals: 2,
    unit: 'hour',
    override_unit_price: null,
    is_optional: false,
    display_order: 1,
    notes: null,
  }
}

function applicabilityFixture(): ServiceBundleVehicleApplicabilityData {
  return {
    id: 'applicability-1',
    bundle_id: 'bundle-1',
    platform_vehicle_id: null,
    vehicle_type: 'pc',
    vehicle_display: 'Passenger car',
    year_from: null,
    year_to: null,
  }
}

function bundleFixture(id: string): ServiceBundleData {
  return {
    id,
    tenant_id: 'tenant-A',
    company_id: 'company-1',
    code: `B-${id}`,
    name: `Bundle ${id}`,
    description: null,
    pricing_mode: 'standard',
    base_price: null,
    currency: 'TND',
    tax_rate: null,
    estimated_labor_hours: null,
    service_interval_km: null,
    service_interval_months: null,
    is_active: true,
    components: [bundleComponentFixture()],
    vehicle_applicabilities: [applicabilityFixture()],
    created_at: '2026-05-11T09:00:00Z',
    updated_at: null,
  }
}

function listResponse(id: string) {
  return {
    data: [bundleFixture(id)],
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: 1,
    },
  }
}

const applicableBundle: ApplicableBundleData = {
  id: 'bundle-1',
  code: 'B1',
  name: 'Bundle 1',
  description: null,
  pricing_mode: 'standard',
  base_price: null,
  currency: 'TND',
  service_interval_km: null,
  service_interval_months: null,
  estimated_labor_hours: null,
  component_count: 1,
}

const expansionLine: BundleExpansionLineData = {
  component_type: 'labor',
  component_id: 'labor-1',
  display_name: 'Labor',
  quantity: '1.000',
  quantity_decimals: 2,
  unit: 'hour',
  unit_price: '10.000',
  line_total: '10.000',
  is_optional: false,
  is_from_fixed_bundle: false,
}

const unitFixture: PickerUnit = {
  id: 'unit-1',
  code: 'HOUR',
  name: 'Hour',
  symbol: 'h',
}

const createPayload: CreateBundlePayload = {
  code: 'B1',
  name: 'Bundle 1',
  pricing_mode: 'standard',
  currency: 'TND',
}

const updatePayload: UpdateBundlePayload = {
  name: 'Updated bundle',
}

const addComponentPayload: AddComponentPayload = {
  component_type: 'labor',
  component_id: 'labor-1',
  quantity: '1.000',
  unit_id: 'unit-1',
}

const setApplicabilitiesPayload: SetApplicabilitiesPayload = {
  applicabilities: [{
    platform_vehicle_id: null,
    vehicle_type: 'pc',
    vehicle_display: 'Passenger car',
    year_from: null,
    year_to: null,
  }],
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockListBundles.mockResolvedValue(listResponse('bundle-1'))
  mockGetBundle.mockResolvedValue(bundleFixture('bundle-1'))
  mockCreateBundle.mockResolvedValue(bundleFixture('bundle-new'))
  mockUpdateBundle.mockResolvedValue(bundleFixture('bundle-1'))
  mockDeleteBundle.mockResolvedValue(undefined)
  mockListApplicableBundles.mockResolvedValue([applicableBundle])
  mockGetBundleExpansion.mockResolvedValue([expansionLine])
  mockAddBundleComponent.mockResolvedValue(bundleComponentFixture())
  mockUpdateBundleComponent.mockResolvedValue(bundleComponentFixture())
  mockDeleteBundleComponent.mockResolvedValue(undefined)
  mockReplaceBundleApplicabilities.mockResolvedValue([applicabilityFixture()])
  mockApiGet.mockResolvedValue([unitFixture])
})

afterEach(() => {
  resetTenant()
})

describe('workshop bundle hooks tenant scope', () => {
  it('wraps bundle and unit read query keys with the active tenant and company (.799-.800, .803, .809-.810)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    const listParams: ListBundlesParams = { active: true }
    const applicableParams: ApplicableBundlesParams = { vehicle_type: 'pc' }

    const { result } = renderHook(() => ({
      list: useBundles(listParams),
      detail: useBundle('bundle-1'),
      applicable: useApplicableBundles(applicableParams),
      expansion: useBundleExpansion('bundle-1', '2', 'vehicle-1'),
      units: useUnits(),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.list.isSuccess).toBe(true)
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.applicable.isSuccess).toBe(true)
      expect(result.current.expansion.isSuccess).toBe(true)
      expect(result.current.units.isSuccess).toBe(true)
    })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['workshop-bundles', 'list', listParams, 'tenant-A', 'company-1'],
      ['workshop-bundles', 'detail', 'bundle-1', 'tenant-A', 'company-1'],
      ['workshop-bundles', 'applicable', applicableParams, 'tenant-A', 'company-1'],
      ['workshop-bundles', 'expansion', 'bundle-1', '2', 'vehicle-1', 'tenant-A', 'company-1'],
      ['uom', 'units', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch bundle or unit reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      list: useBundles(),
      detail: useBundle('bundle-1'),
      applicable: useApplicableBundles(),
      expansion: useBundleExpansion('bundle-1'),
      units: useUnits(),
    }), { wrapper })

    expect(mockListBundles).not.toHaveBeenCalled()
    expect(mockGetBundle).not.toHaveBeenCalled()
    expect(mockListApplicableBundles).not.toHaveBeenCalled()
    expect(mockGetBundleExpansion).not.toHaveBeenCalled()
    expect(mockApiGet).not.toHaveBeenCalled()
  })

  it('bounds bundle mutation invalidation to the active tenant cache (.801-.802, .804-.808)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    const listParams: ListBundlesParams = { active: true }
    let listCalls = 0
    let detailCalls = 0

    mockListBundles.mockImplementation(async () => {
      listCalls += 1
      return listResponse(`bundle-list-${listCalls}`)
    })
    mockGetBundle.mockImplementation(async () => {
      detailCalls += 1
      return bundleFixture(`bundle-detail-${detailCalls}`)
    })

    queryClient.setQueryData(
      ['workshop-bundles', 'list', listParams, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-list-preserved' },
    )
    queryClient.setQueryData(
      ['workshop-bundles', 'detail', 'bundle-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-detail-preserved' },
    )

    const { result: reads } = renderHook(() => ({
      list: useBundles(listParams),
      detail: useBundle('bundle-1'),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.list.isSuccess).toBe(true)
      expect(reads.current.detail.isSuccess).toBe(true)
      expect(listCalls).toBe(1)
      expect(detailCalls).toBe(1)
    })

    const { result: mutations } = renderHook(() => ({
      create: useCreateBundle(),
      update: useUpdateBundle('bundle-1'),
      remove: useDeleteBundle(),
      addComponent: useAddBundleComponent('bundle-1'),
      updateComponent: useUpdateBundleComponent('bundle-1'),
      deleteComponent: useDeleteBundleComponent('bundle-1'),
      replaceApplicabilities: useReplaceBundleApplicabilities('bundle-1'),
    }), { wrapper })

    await act(async () => {
      await mutations.current.create.mutateAsync(createPayload)
    })
    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(detailCalls).toBe(2)
    })

    await act(async () => {
      await mutations.current.update.mutateAsync(updatePayload)
    })
    await waitFor(() => {
      expect(listCalls).toBe(3)
      expect(detailCalls).toBe(3)
    })

    await act(async () => {
      await mutations.current.remove.mutateAsync('bundle-1')
    })
    await waitFor(() => {
      expect(listCalls).toBe(4)
      expect(detailCalls).toBe(4)
    })

    await act(async () => {
      await mutations.current.addComponent.mutateAsync(addComponentPayload)
    })
    await waitFor(() => {
      expect(listCalls).toBe(4)
      expect(detailCalls).toBe(5)
    })

    await act(async () => {
      await mutations.current.updateComponent.mutateAsync({
        componentId: 'component-1',
        payload: { quantity: '2.000' },
      })
    })
    await waitFor(() => {
      expect(listCalls).toBe(4)
      expect(detailCalls).toBe(6)
    })

    await act(async () => {
      await mutations.current.deleteComponent.mutateAsync('component-1')
    })
    await waitFor(() => {
      expect(listCalls).toBe(4)
      expect(detailCalls).toBe(7)
    })

    await act(async () => {
      await mutations.current.replaceApplicabilities.mutateAsync(setApplicabilitiesPayload)
    })
    await waitFor(() => {
      expect(listCalls).toBe(4)
      expect(detailCalls).toBe(8)
    })

    expect(queryClient.getQueryData([
      'workshop-bundles',
      'list',
      listParams,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-list-preserved' })
    expect(queryClient.getQueryData([
      'workshop-bundles',
      'detail',
      'bundle-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-detail-preserved' })
  })
})
