import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import type {
  ApproveInput,
  CreateWorkOrderInput,
  PaginatedWorkOrders,
  WorkOrder,
  WorkOrderListFilters,
} from '../../types'
import {
  useApproveWorkOrder,
  useCancelWorkOrder,
  useCompleteWorkOrder,
  useCreateWorkOrder,
  useTransitionWorkOrder,
  useUpdateWorkOrder,
  useWorkOrder,
  useWorkOrders,
} from '../useWorkOrders'

const mockList = vi.hoisted(() => vi.fn())
const mockGet = vi.hoisted(() => vi.fn())
const mockCreate = vi.hoisted(() => vi.fn())
const mockUpdate = vi.hoisted(() => vi.fn())
const mockTransition = vi.hoisted(() => vi.fn())
const mockApprove = vi.hoisted(() => vi.fn())
const mockComplete = vi.hoisted(() => vi.fn())
const mockCancel = vi.hoisted(() => vi.fn())

vi.mock('../../api/workOrderApi', () => ({
  workOrderApi: {
    list: mockList,
    get: mockGet,
    create: mockCreate,
    update: mockUpdate,
    transition: mockTransition,
    approve: mockApprove,
    complete: mockComplete,
    cancel: mockCancel,
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

function workOrderFixture(id: string): WorkOrder {
  return {
    id,
    tenant_id: 'tenant-A',
    company_id: 'company-1',
    location_id: 'location-1',
    work_order_number: `WO-${id}`,
    status: 'received',
    type: 'repair',
    customer_partner_id: 'partner-1',
    customer_display_name: 'Customer',
    vehicle_id: 'vehicle-1',
    vehicle_display_name: 'Vehicle',
    opened_by_user_id: 'user-1',
    primary_technician_profile_id: null,
    primary_technician_display_name: null,
    mileage_at_intake: null,
    customer_complaint: null,
    diagnosis: null,
    internal_notes: null,
    scheduled_start_at: null,
    scheduled_end_at: null,
    promised_at: null,
    started_at: null,
    paused_at: null,
    completed_at: null,
    cancelled_at: null,
    cancellation_reason: null,
    approval_captured_at: null,
    approval_method: null,
    approval_reference: null,
    currency: 'TND',
    estimated_totals: null,
    actual_totals: null,
    quote_document_id: null,
    invoice_document_id: null,
    lines: [],
    assignments: [],
    status_history: [],
    created_at: '2026-05-11T09:00:00Z',
    updated_at: null,
  }
}

function pageFixture(id: string): PaginatedWorkOrders {
  return {
    data: [{
      id,
      work_order_number: `WO-${id}`,
      status: 'received',
      type: 'repair',
      customer_display_name: 'Customer',
      vehicle_display_name: 'Vehicle',
      primary_technician_display_name: null,
      scheduled_start_at: null,
      promised_at: null,
      currency: 'TND',
      estimated_grand_total: null,
      actual_grand_total: null,
      created_at: '2026-05-11T09:00:00Z',
    }],
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: 1,
      from: 1,
      to: 1,
    },
  }
}

const filters: WorkOrderListFilters = {
  status: 'received',
}

const createPayload: CreateWorkOrderInput = {
  type: 'repair',
  customer_partner_id: 'partner-1',
  vehicle_id: 'vehicle-1',
  currency: 'TND',
}

const approvePayload: ApproveInput = {
  approval_method: 'in_person',
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockList.mockResolvedValue(pageFixture('work-order-1'))
  mockGet.mockResolvedValue(workOrderFixture('work-order-1'))
  mockCreate.mockResolvedValue(workOrderFixture('work-order-new'))
  mockUpdate.mockResolvedValue(workOrderFixture('work-order-1'))
  mockTransition.mockResolvedValue(workOrderFixture('work-order-1'))
  mockApprove.mockResolvedValue(workOrderFixture('work-order-1'))
  mockComplete.mockResolvedValue(workOrderFixture('work-order-1'))
  mockCancel.mockResolvedValue(workOrderFixture('work-order-1'))
})

afterEach(() => {
  resetTenant()
})

describe('work order hooks tenant scope', () => {
  it('wraps work order read query keys with the active tenant and company (.826-.827)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => ({
      list: useWorkOrders(filters),
      detail: useWorkOrder('work-order-1'),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.list.isSuccess).toBe(true)
      expect(result.current.detail.isSuccess).toBe(true)
    })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['workshop-work-orders', 'list', filters, 'tenant-A', 'company-1'],
      ['workshop-work-orders', 'detail', 'work-order-1', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch work order reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      list: useWorkOrders(filters),
      detail: useWorkOrder('work-order-1'),
    }), { wrapper })

    expect(mockList).not.toHaveBeenCalled()
    expect(mockGet).not.toHaveBeenCalled()
  })

  it('bounds work order mutation invalidation to the active tenant cache (.828-.838)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let listCalls = 0
    let detailCalls = 0

    mockList.mockImplementation(async () => {
      listCalls += 1
      return pageFixture(`work-order-list-${listCalls}`)
    })
    mockGet.mockImplementation(async () => {
      detailCalls += 1
      return workOrderFixture(`work-order-detail-${detailCalls}`)
    })

    queryClient.setQueryData(
      ['workshop-work-orders', 'list', filters, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-list-preserved' },
    )
    queryClient.setQueryData(
      ['workshop-work-orders', 'detail', 'work-order-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-detail-preserved' },
    )

    const { result: reads } = renderHook(() => ({
      list: useWorkOrders(filters),
      detail: useWorkOrder('work-order-1'),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.list.isSuccess).toBe(true)
      expect(reads.current.detail.isSuccess).toBe(true)
      expect(listCalls).toBe(1)
      expect(detailCalls).toBe(1)
    })

    const { result: mutations } = renderHook(() => ({
      create: useCreateWorkOrder(),
      update: useUpdateWorkOrder('work-order-1'),
      transition: useTransitionWorkOrder('work-order-1'),
      approve: useApproveWorkOrder('work-order-1'),
      complete: useCompleteWorkOrder('work-order-1'),
      cancel: useCancelWorkOrder('work-order-1'),
    }), { wrapper })

    await act(async () => {
      await mutations.current.create.mutateAsync(createPayload)
    })
    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(detailCalls).toBe(1)
    })

    await act(async () => {
      await mutations.current.update.mutateAsync({ diagnosis: 'Updated diagnosis' })
    })
    await waitFor(() => {
      expect(listCalls).toBe(3)
      expect(detailCalls).toBe(2)
    })

    await act(async () => {
      await mutations.current.transition.mutateAsync({ to_status: 'diagnosed' })
    })
    await waitFor(() => {
      expect(listCalls).toBe(4)
      expect(detailCalls).toBe(3)
    })

    await act(async () => {
      await mutations.current.approve.mutateAsync(approvePayload)
    })
    await waitFor(() => {
      expect(listCalls).toBe(5)
      expect(detailCalls).toBe(4)
    })

    await act(async () => {
      await mutations.current.complete.mutateAsync({ completion_mileage: 12345 })
    })
    await waitFor(() => {
      expect(listCalls).toBe(6)
      expect(detailCalls).toBe(5)
    })

    await act(async () => {
      await mutations.current.cancel.mutateAsync({ reason_code: 'customer_declined' })
    })
    await waitFor(() => {
      expect(listCalls).toBe(7)
      expect(detailCalls).toBe(6)
    })

    expect(queryClient.getQueryData([
      'workshop-work-orders',
      'list',
      filters,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-list-preserved' })
    expect(queryClient.getQueryData([
      'workshop-work-orders',
      'detail',
      'work-order-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-detail-preserved' })
  })
})
