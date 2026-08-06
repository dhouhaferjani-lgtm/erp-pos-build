import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { useCashMovementsReport } from './useCashMovementsReport'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockUseViewScope = vi.hoisted(() => vi.fn())

interface ViewScopeValue {
  scope: 'all' | string[]
  effectiveLocationIds: string[]
  isAll: boolean
  setScope: () => void
}

const branchScope: ViewScopeValue = {
  scope: ['loc-b', 'loc-a'],
  effectiveLocationIds: ['loc-b', 'loc-a'],
  isAll: false,
  setScope: () => undefined,
}

const unrestrictedScope: ViewScopeValue = {
  scope: 'all',
  effectiveLocationIds: ['loc-a', 'loc-b'],
  isAll: true,
  setScope: () => undefined,
}

vi.mock('@/lib/api', () => ({ api: { get: mockApiGet } }))
vi.mock('@/features/locations/hooks/useViewScope', () => ({
  useViewScope: mockUseViewScope,
}))

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

describe('useCashMovementsReport', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // Re-established for EVERY case, so a per-case `mockReturnValue` override
    // cannot leak forward. `mockReturnValueOnce` would be wrong here: the hook
    // re-renders after the fetch resolves and would consume the override before
    // the assertions run (web gate 2026-08-06, MINOR-5).
    mockUseViewScope.mockReturnValue(branchScope)
    act(() => {
      useAuthStore.setState({
        user: {
          id: 'user-1',
          name: 'Test User',
          email: 'test@example.test',
          tenant_id: 'tenant-1',
          roles: [],
          email_verified_at: null,
        },
      })
      useCompanyStore.setState({ currentCompanyId: 'company-1' })
    })
  })

  afterEach(() => {
    act(() => {
      useAuthStore.setState({ user: null })
      useCompanyStore.setState({ currentCompanyId: null })
    })
  })

  it('sends the view scope as location_ids and keys the query by that scope', async () => {
    // W-7 F-3 (fix lane L3): this report used to define no location field at
    // all and key with tenantScopedKey, so a single-shop selection in the
    // TopBar silently returned the whole company's cash — and one scope's rows
    // were served from cache under another. Modelled on useAgedReceivables.
    const filters = { from: '2026-07-01', to: '2026-07-31', page: 1 }
    const report = {
      data: [],
      meta: {
        current_page: 1,
        per_page: 50,
        total: 0,
        last_page: 1,
        from: null,
        to: null,
        totals: {},
      },
    }
    mockApiGet.mockResolvedValue({ data: report })
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: Infinity } },
    })

    const { result } = renderHook(() => useCashMovementsReport(filters), {
      wrapper: wrapper(queryClient),
    })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(mockApiGet).toHaveBeenCalledWith('/reports/cash-movements', {
      params: { ...filters, location_ids: ['loc-b', 'loc-a'] },
    })
    expect(
      queryClient.getQueryData([
        'cash-movements-report',
        { ...filters, location_ids: ['loc-b', 'loc-a'] },
        { locScope: ['loc-a', 'loc-b'] },
        'tenant-1',
        'company-1',
      ]),
    ).toEqual(report)
  })

  it('keys an unrestricted scope distinctly from a single-branch scope', async () => {
    mockUseViewScope.mockReturnValue(unrestrictedScope)
    const filters = { page: 1 }
    const report = {
      data: [],
      meta: {
        current_page: 1,
        per_page: 50,
        total: 0,
        last_page: 1,
        from: null,
        to: null,
        totals: {},
      },
    }
    mockApiGet.mockResolvedValue({ data: report })
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: Infinity } },
    })

    const { result } = renderHook(() => useCashMovementsReport(filters), {
      wrapper: wrapper(queryClient),
    })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(
      queryClient.getQueryData([
        'cash-movements-report',
        { ...filters, location_ids: ['loc-a', 'loc-b'] },
        { locScope: 'all' },
        'tenant-1',
        'company-1',
      ]),
    ).toEqual(report)
  })

  it('preserves paginated data and meta under a location-scoped query key', async () => {
    const filters = {
      from: '2026-07-01',
      to: '2026-07-31',
      repository_id: '11111111-1111-4111-8111-111111111111',
      direction: 'in' as const,
      page: 2,
    }
    const report = {
      data: [{
        date: '2026-07-10',
        direction: 'in' as const,
        amount: '25.000',
        currency: 'TND',
        source_type: 'payment',
        source_id: '22222222-2222-4222-8222-222222222222',
        counterparty: 'Client A',
        gl_account: '531100',
      }],
      meta: {
        current_page: 2,
        per_page: 50,
        total: 51,
        last_page: 2,
        from: 51,
        to: 51,
        totals: { TND: { in: '25.000', out: '0.000', net: '25.000' } },
      },
    }
    mockApiGet.mockResolvedValue({ data: report })
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: Infinity } },
    })

    const { result } = renderHook(() => useCashMovementsReport(filters), {
      wrapper: wrapper(queryClient),
    })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    const scopedFilters = { ...filters, location_ids: ['loc-b', 'loc-a'] }
    expect(mockApiGet).toHaveBeenCalledWith('/reports/cash-movements', {
      params: scopedFilters,
    })
    expect(result.current.data).toEqual(report)
    expect(
      queryClient.getQueryData([
        'cash-movements-report',
        scopedFilters,
        { locScope: ['loc-a', 'loc-b'] },
        'tenant-1',
        'company-1',
      ]),
    ).toEqual(report)
  })

  it('does not request tenant data without both tenant and company context', () => {
    act(() => {
      useCompanyStore.setState({ currentCompanyId: null })
    })
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })

    renderHook(() => useCashMovementsReport({ page: 1 }), {
      wrapper: wrapper(queryClient),
    })

    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
