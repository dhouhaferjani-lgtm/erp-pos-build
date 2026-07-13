import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { useCashPosition } from '../useCashPosition'
import { useRepositoryMovements } from '../useRepositoryMovements'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  api: { get: mockApiGet },
  apiGet: async (url: string, params?: Record<string, unknown>) => {
    const response = params === undefined
      ? await mockApiGet(url)
      : await mockApiGet(url, params)
    return response.data.data as unknown
  },
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: { id: 'user-1', name: 'User', email: 'u@example.test', tenant_id: tenantId, roles: [], email_verified_at: null },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

const cashPositionFixture = {
  data: {
    as_of: '2026-07-09T00:00:00Z',
    currency: 'TND',
    groups: [
      { type: 'cash_register', total: '100.000', repositories: [] },
    ],
    grand_total: '100.000',
  },
}

const movementsFixture = {
  data: [
    {
      id: 'mov-1',
      direction: 'in',
      amount: '50.000',
      currency: 'TND',
      balance_after: '150.000',
      ordinal: 1,
      source_type: 'payment',
      source_id: 'pay-1',
      journal_entry_id: null,
      reason_code: null,
      occurred_at: '2026-07-09T10:00:00Z',
      recorded_while_frozen: false,
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockResolvedValue({ data: cashPositionFixture })
})

afterEach(() => {
  resetTenant()
})

describe('useCashPosition tenant scope', () => {
  it('wraps the cash-position query key with tenant/company and gates it on missing scope', async () => {
    const queryClient = createClient()
    const { result, unmount } = renderHook(() => useCashPosition(), { wrapper: wrapper(queryClient) })

    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    expect(queryClient.getQueryData(['treasury-cash-position', 'tenant-A', 'company-1'])).toEqual(
      cashPositionFixture.data,
    )
    expect(mockApiGet).toHaveBeenCalledWith('/treasury/cash-position')

    unmount()
    resetTenant()
    const gatedClient = createClient()
    renderHook(() => useCashPosition(), { wrapper: wrapper(gatedClient) })
    expect(mockApiGet).toHaveBeenCalledTimes(1)
  })

  it('adds the flows window to the query key and request without changing the legacy key', async () => {
    const queryClient = createClient()
    const { result, unmount } = renderHook(() => useCashPosition({ flowsWindow: 7 }), {
      wrapper: wrapper(queryClient),
    })

    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    expect(queryClient.getQueryData([
      'treasury-cash-position',
      7,
      'tenant-A',
      'company-1',
    ])).toEqual(cashPositionFixture.data)
    expect(mockApiGet).toHaveBeenCalledWith('/treasury/cash-position', { flows_window: 7 })
    unmount()

    const legacyClient = createClient()
    const { result: legacyResult, unmount: unmountLegacy } = renderHook(() => useCashPosition(), {
      wrapper: wrapper(legacyClient),
    })

    await waitFor(() => { expect(legacyResult.current.isSuccess).toBe(true) })
    unmountLegacy()

    expect(legacyClient.getQueryState([
      'treasury-cash-position',
      'tenant-A',
      'company-1',
    ])).toBeDefined()
  })
})

describe('useRepositoryMovements tenant scope', () => {
  it('wraps the movements query key with repository id, filters, tenant, and company', async () => {
    mockApiGet.mockResolvedValue({ data: movementsFixture })
    const queryClient = createClient()
    const filters = { direction: 'in' as const, page: 1 }
    const { result } = renderHook(
      () => useRepositoryMovements('repo-1', filters),
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    expect(result.current.data).toEqual(movementsFixture)
    expect(queryClient.getQueryData([
      'repository-movements',
      'repo-1',
      filters,
      'tenant-A',
      'company-1',
    ])).toEqual(movementsFixture)

    // Rule 14: paginated {data, meta} endpoint MUST use api.get with params,
    // never apiGet (which would strip meta).
    expect(mockApiGet).toHaveBeenCalledWith('/payment-repositories/repo-1/movements', {
      params: filters,
    })
  })

  it('does not fetch without a repository id or tenant/company scope', () => {
    mockApiGet.mockResolvedValue({ data: movementsFixture })
    const queryClient = createClient()
    renderHook(() => useRepositoryMovements(undefined), { wrapper: wrapper(queryClient) })
    expect(mockApiGet).not.toHaveBeenCalled()

    resetTenant()
    const gatedClient = createClient()
    renderHook(() => useRepositoryMovements('repo-1'), { wrapper: wrapper(gatedClient) })
    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
