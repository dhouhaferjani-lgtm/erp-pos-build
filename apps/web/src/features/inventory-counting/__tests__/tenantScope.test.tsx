import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  countingKeys,
  countingListInvalidationPredicate,
  useActivateCounting,
  useCountingDashboard,
  useCountingDetail,
  useCountingList,
  useCreateCounting,
  useDiscrepancyReport,
  useReconciliation,
} from '../api/queries'
import type { CountingFilters, CreateCountingFormData } from '../types'

const mockGetDashboard = vi.hoisted(() => vi.fn())
const mockList = vi.hoisted(() => vi.fn())
const mockGetDetail = vi.hoisted(() => vi.fn())
const mockCreate = vi.hoisted(() => vi.fn())
const mockActivate = vi.hoisted(() => vi.fn())
const mockGetReconciliation = vi.hoisted(() => vi.fn())
const mockGetReport = vi.hoisted(() => vi.fn())

vi.mock('../api/countingApi', () => ({
  countingApi: {
    getDashboard: mockGetDashboard,
    list: mockList,
    getDetail: mockGetDetail,
    create: mockCreate,
    activate: mockActivate,
    cancel: vi.fn(),
    finalize: vi.fn(),
    getReconciliation: mockGetReconciliation,
    triggerThirdCount: vi.fn(),
    manualOverride: vi.fn(),
    getReport: mockGetReport,
    exportReport: vi.fn(),
    sendReminder: vi.fn(),
  },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 't@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function persistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

const filters: CountingFilters = { status: 'all', page: 1, per_page: 10 }

const createCountingPayload: CreateCountingFormData = {
  scope_type: 'full_inventory',
  scope_filters: {},
  execution_mode: 'parallel',
  requires_count_2: true,
  requires_count_3: false,
  allow_unexpected_items: true,
  count_1_user_id: 'user-1',
}

beforeEach(() => {
  mockGetDashboard.mockReset()
  mockGetDashboard.mockResolvedValue({
    summary: { active: 0, pending_review: 0, completed_this_month: 0, overdue: 0 },
    active_counts: [],
    pending_review: [],
  })
  mockList.mockReset()
  mockList.mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
    links: { first: '', last: '', prev: null, next: null },
  })
  mockGetDetail.mockReset()
  mockGetDetail.mockResolvedValue({ id: 7, uuid: 'count-7' })
  mockCreate.mockReset()
  mockCreate.mockResolvedValue({ id: 8, uuid: 'count-8' })
  mockActivate.mockReset()
  mockActivate.mockResolvedValue(undefined)
  mockGetReconciliation.mockReset()
  mockGetReconciliation.mockResolvedValue({ summary: {}, items: [] })
  mockGetReport.mockReset()
  mockGetReport.mockResolvedValue({ report_id: 'report-7', flagged_items: [] })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('countingListInvalidationPredicate', () => {
  it('matches counting.list keys for the active tenant/company', () => {
    const pred = countingListInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['counting', 'list', filters, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['counting', 'list', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects detail/dashboard/reconciliation/report keys and wrong tenant/company', () => {
    const pred = countingListInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['counting', 'detail', 7, 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['counting', 'dashboard', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['counting', 'reconciliation', 7, 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['counting', 'report', 7, 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['counting', 'list', filters, 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['counting', 'list', filters, 'tenant-A', 'company-2'] })).toBe(false)
  })
})

describe('inventory counting queryKey shapes', () => {
  it('wraps list, detail, dashboard, reconciliation, and report keys (.319-.323)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)

    const { result: dashboard } = renderHook(() => useCountingDashboard(), { wrapper })
    const { result: list } = renderHook(() => useCountingList(filters), { wrapper })
    const { result: detail } = renderHook(() => useCountingDetail(7), { wrapper })
    const { result: reconciliation } = renderHook(() => useReconciliation(7), { wrapper })
    const { result: report } = renderHook(() => useDiscrepancyReport(7), { wrapper })

    await waitFor(() => {
      expect(dashboard.current.isSuccess).toBe(true)
      expect(list.current.isSuccess).toBe(true)
      expect(detail.current.isSuccess).toBe(true)
      expect(reconciliation.current.isSuccess).toBe(true)
      expect(report.current.isSuccess).toBe(true)
    })

    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    expect(keys.find((k) => k[0] === 'counting' && k[1] === 'dashboard')).toEqual([
      'counting',
      'dashboard',
      'tenant-A',
      'company-1',
    ])
    expect(keys.find((k) => k[0] === 'counting' && k[1] === 'list')).toEqual([
      'counting',
      'list',
      filters,
      'tenant-A',
      'company-1',
    ])
    expect(keys.find((k) => k[0] === 'counting' && k[1] === 'detail')).toEqual([
      'counting',
      'detail',
      7,
      'tenant-A',
      'company-1',
    ])
    expect(keys.find((k) => k[0] === 'counting' && k[1] === 'reconciliation')).toEqual([
      'counting',
      'reconciliation',
      7,
      'tenant-A',
      'company-1',
    ])
    expect(keys.find((k) => k[0] === 'counting' && k[1] === 'report')).toEqual([
      'counting',
      'report',
      7,
      'tenant-A',
      'company-1',
    ])
  })

  it('queryKeys differ across tenants (useCountingList)', async () => {
    setTenant('tenant-A', 'company-1')
    const clientA = createTestQueryClient()
    const { result: resultA } = renderHook(() => useCountingList(filters), {
      wrapper: makeWrapper(clientA),
    })
    await waitFor(() => { expect(resultA.current.isSuccess).toBe(true) })
    const keysA = JSON.stringify(clientA.getQueryCache().getAll().map((q) => q.queryKey))

    setTenant('tenant-B', 'company-1')
    const clientB = createTestQueryClient()
    const { result: resultB } = renderHook(() => useCountingList(filters), {
      wrapper: makeWrapper(clientB),
    })
    await waitFor(() => { expect(resultB.current.isSuccess).toBe(true) })

    const keysB = JSON.stringify(clientB.getQueryCache().getAll().map((q) => q.queryKey))
    expect(keysA).toContain('tenant-A')
    expect(keysB).toContain('tenant-B')
    expect(keysA).not.toEqual(keysB)
  })
})

describe('inventory counting mutation cascades', () => {
  it('useCreateCounting cascades counting.list + dashboard with per-call counters (.324, .325)', async () => {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    let dashboardCalls = 0
    let detailCalls = 0
    mockList.mockImplementation(async () => {
      listCalls += 1
      return {
        data: [{ id: listCalls, uuid: `count-${String(listCalls)}` }],
        meta: { current_page: 1, last_page: 1, per_page: 10, total: listCalls },
        links: { first: '', last: '', prev: null, next: null },
      }
    })
    mockGetDashboard.mockImplementation(async () => {
      dashboardCalls += 1
      return {
        summary: { active: dashboardCalls, pending_review: 0, completed_this_month: 0, overdue: 0 },
        active_counts: [],
        pending_review: [],
      }
    })
    mockGetDetail.mockImplementation(async () => {
      detailCalls += 1
      return { id: 7, uuid: `detail-${String(detailCalls)}` }
    })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result: list } = renderHook(() => useCountingList(filters), { wrapper })
    const { result: dashboard } = renderHook(() => useCountingDashboard(), { wrapper })
    const { result: detail } = renderHook(() => useCountingDetail(7), { wrapper })
    const { result: create } = renderHook(() => useCreateCounting(), { wrapper })

    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(dashboard.current.isSuccess).toBe(true)
      expect(detail.current.isSuccess).toBe(true)
    })
    expect(listCalls).toBe(1)
    expect(dashboardCalls).toBe(1)
    expect(detailCalls).toBe(1)

    await create.current.mutateAsync(createCountingPayload)

    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(dashboardCalls).toBe(2)
    })
    expect(detailCalls).toBe(1)
  })

  it('useActivateCounting cascades exact detail + dashboard keys (.326, .327)', async () => {
    setTenant('tenant-A', 'company-1')
    let detailCalls = 0
    let dashboardCalls = 0
    let listCalls = 0
    mockGetDetail.mockImplementation(async () => {
      detailCalls += 1
      return { id: 7, uuid: `detail-${String(detailCalls)}` }
    })
    mockGetDashboard.mockImplementation(async () => {
      dashboardCalls += 1
      return {
        summary: { active: dashboardCalls, pending_review: 0, completed_this_month: 0, overdue: 0 },
        active_counts: [],
        pending_review: [],
      }
    })
    mockList.mockImplementation(async () => {
      listCalls += 1
      return {
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
        links: { first: '', last: '', prev: null, next: null },
      }
    })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result: detail } = renderHook(() => useCountingDetail(7), { wrapper })
    const { result: dashboard } = renderHook(() => useCountingDashboard(), { wrapper })
    const { result: list } = renderHook(() => useCountingList(filters), { wrapper })
    const { result: activate } = renderHook(() => useActivateCounting(), { wrapper })

    await waitFor(() => {
      expect(detail.current.isSuccess).toBe(true)
      expect(dashboard.current.isSuccess).toBe(true)
      expect(list.current.isSuccess).toBe(true)
    })
    expect(detailCalls).toBe(1)
    expect(dashboardCalls).toBe(1)
    expect(listCalls).toBe(1)

    await activate.current.mutateAsync(7)

    await waitFor(() => {
      expect(detailCalls).toBe(2)
      expect(dashboardCalls).toBe(2)
    })
    expect(listCalls).toBe(1)
  })
})

describe('cross-tenant counting isolation', () => {
  it('tenant-A counting list data does not contain tenant-B entries (L18)', async () => {
    const client = persistentQueryClient()
    const tenantBKey = ['counting', 'list', filters, 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, {
      data: [{ id: 90, uuid: 'leaked-tenant-b-counting' }],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
      links: { first: '', last: '', prev: null, next: null },
    })

    setTenant('tenant-A', 'company-1')
    const { result } = renderHook(() => useCountingList(filters), { wrapper: makeWrapper(client) })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const tenantAKey = ['counting', 'list', filters, 'tenant-A', 'company-1']
    const tenantAData = client.getQueryData<{ data: Array<{ uuid: string }> }>(tenantAKey)
    expect(tenantAData?.data).toEqual([])
    expect(tenantAData?.data.map((counting) => counting.uuid)).not.toContain(
      'leaked-tenant-b-counting',
    )
    expect(client.getQueryData(tenantBKey)).toEqual({
      data: [{ id: 90, uuid: 'leaked-tenant-b-counting' }],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
      links: { first: '', last: '', prev: null, next: null },
    })
  })
})

describe('countingKeys factory shape', () => {
  it('exposes stable unscoped base keys', () => {
    expect(countingKeys.all).toEqual(['counting'])
    expect(countingKeys.lists()).toEqual(['counting', 'list'])
    expect(countingKeys.list(filters)).toEqual(['counting', 'list', filters])
    expect(countingKeys.details()).toEqual(['counting', 'detail'])
    expect(countingKeys.detail(7)).toEqual(['counting', 'detail', 7])
    expect(countingKeys.reconciliation(7)).toEqual(['counting', 'reconciliation', 7])
    expect(countingKeys.report(7)).toEqual(['counting', 'report', 7])
    expect(countingKeys.dashboard()).toEqual(['counting', 'dashboard'])
  })
})
