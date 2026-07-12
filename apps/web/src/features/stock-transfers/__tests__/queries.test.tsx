import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const { mockGet, mockApiGet, mockApiPost } = vi.hoisted(() => ({
  mockGet: vi.fn(),
  mockApiGet: vi.fn(),
  mockApiPost: vi.fn(),
}))
vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: { ...actual.api, get: mockGet }, apiGet: mockApiGet, apiPost: mockApiPost }
})
import {
  useCancelStockTransfer,
  useCompleteStockTransfer,
  useCreateStockTransfer,
} from '../api/queries'

function scope(tenant: string | null, company: string | null) {
  useAuthStore.setState({
    user: tenant
      ? { id: 'u', name: 'U', email: 'u@t', tenant_id: tenant, roles: [], email_verified_at: null }
      : null,
    token: tenant ? 't' : null,
    isAuthenticated: !!tenant,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: company, companies: [], isLoading: false })
}
function wrapper(client: QueryClient) {
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )
}

// The tenant-scoped query keys these mutations must actually hit. Keys are
// produced by tenantScopedKey([...]) — tenant/company appended as SUFFIXES —
// so the invalidation FILTERS must be bare literal prefixes: React Query
// matches filter keys positionally from the front, and a tenantScopedKey(...)
// filter like ['stock-transfers', tenant, company] never prefixes
// ['stock-transfers', 'list', filters, tenant, company]
// (memory: project_tanstack_invalidation_suffix_noop).
const listKey = ['stock-transfers', 'list', {}, 'tenant-1', 'company-1'] as const
const detailKey = ['stock-transfers', 'detail', 'transfer-1', 'tenant-1', 'company-1'] as const
const stockLevelsKey = ['stock-levels', 'loc-1', 'tenant-1', 'company-1'] as const
const stockMovementsKey = ['stock-movements', 1, 'tenant-1', 'company-1'] as const

function seededClient() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  client.setQueryData(listKey, { data: [], meta: {} })
  client.setQueryData(detailKey, { id: 'transfer-1' })
  client.setQueryData(stockLevelsKey, [])
  client.setQueryData(stockMovementsKey, { data: [] })
  return client
}

beforeEach(() => {
  vi.clearAllMocks()
  scope('tenant-1', 'company-1')
  mockApiPost.mockResolvedValue({ id: 'transfer-1', status: 'completed' })
})
afterEach(() => {
  act(() => {
    scope(null, null)
  })
})

describe('stock-transfer mutations invalidate the tenant-scoped caches', () => {
  it('create marks list + stock-levels + stock-movements stale', async () => {
    const client = seededClient()
    const { result } = renderHook(() => useCreateStockTransfer(), { wrapper: wrapper(client) })

    await act(async () => {
      await result.current.mutateAsync({
        source_location_id: 'loc-1',
        destination_location_id: 'loc-2',
        lines: [],
      } as never)
    })

    expect(client.getQueryState(listKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(stockLevelsKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(stockMovementsKey)?.isInvalidated).toBe(true)
  })

  it('complete marks list + detail + stock caches stale', async () => {
    const client = seededClient()
    const { result } = renderHook(() => useCompleteStockTransfer(), { wrapper: wrapper(client) })

    await act(async () => {
      await result.current.mutateAsync('transfer-1')
    })

    expect(client.getQueryState(listKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(detailKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(stockLevelsKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(stockMovementsKey)?.isInvalidated).toBe(true)
  })

  it('cancel marks list + detail + stock caches stale', async () => {
    const client = seededClient()
    const { result } = renderHook(() => useCancelStockTransfer(), { wrapper: wrapper(client) })

    await act(async () => {
      await result.current.mutateAsync({ id: 'transfer-1', reason: 'oops' })
    })

    expect(client.getQueryState(listKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(detailKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(stockLevelsKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(stockMovementsKey)?.isInvalidated).toBe(true)
  })
})
