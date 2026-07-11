import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const { mockGet, mockApiPost } = vi.hoisted(() => ({ mockGet: vi.fn(), mockApiPost: vi.fn() }))
vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: { ...actual.api, get: mockGet }, apiPost: mockApiPost }
})
import { replenishmentApi } from '../api/replenishmentApi'
import { useCreateTransferAction, useOpenReplenishment, useReplenishmentHistory } from '../api/queries'

function scope(tenant: string | null, company: string | null) {
  useAuthStore.setState({ user: tenant ? { id: 'u', name: 'U', email: 'u@t', tenant_id: tenant, roles: [], email_verified_at: null } : null, token: tenant ? 't' : null, isAuthenticated: !!tenant, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: company, companies: [], isLoading: false })
}
function wrapper(client: QueryClient) { return ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider> }

beforeEach(() => {
  vi.clearAllMocks()
  scope('tenant-1', 'company-1')
  mockGet.mockResolvedValue({ data: { data: [], meta: { truncated: false } } })
  mockApiPost.mockResolvedValue({ transfer_ids: ['transfer-1'] })
})
afterEach(() => {
  act(() => {
    scope(null, null)
  })
})

describe('replenishment queries', () => {
  it('preserves open-list meta through api.get', async () => {
    const envelope = { data: [], meta: { truncated: true } }
    mockGet.mockResolvedValueOnce({ data: envelope })
    await expect(replenishmentApi.open({ location_ids: ['l1'] })).resolves.toEqual(envelope)
    expect(mockGet).toHaveBeenCalledWith('/replenishment-requests', { params: { status: 'open', location_ids: ['l1'] } })
  })

  it('uses tenant-scoped keys for open and history', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const { result } = renderHook(() => ({ open: useOpenReplenishment({}), history: useReplenishmentHistory({ status: 'fulfilled', page: 2 }) }), { wrapper: wrapper(client) })
    await waitFor(() => { expect(result.current.open.isSuccess).toBe(true); expect(result.current.history.isSuccess).toBe(true) })
    expect(client.getQueryData(['replenishment', 'open', {}, 'tenant-1', 'company-1'])).toBeDefined()
    expect(client.getQueryData(['replenishment', 'history', { status: 'fulfilled', page: 2 }, 'tenant-1', 'company-1'])).toBeDefined()
  })

  it('invalidates list and transfer caches with bare namespace prefixes', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const listKey = ['replenishment', 'open', {}, 'tenant-1', 'company-1'] as const
    client.setQueryData(listKey, { data: [], meta: { truncated: false } })
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries')
    const { result } = renderHook(() => useCreateTransferAction(), { wrapper: wrapper(client) })

    await act(async () => {
      await result.current.mutateAsync({
        source_location_id: 'warehouse-1',
        lines: [{ request_id: 'request-1', quantity: '1.0000' }],
      })
    })

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['replenishment'] })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['stock-transfers'] })
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['stock-levels'] })
    expect(client.getQueryState(listKey)?.isInvalidated).toBe(true)
  })
})
