import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useProductStockLevels } from '@/features/products/api/useProductStockLevels'
import { getProductStock } from '@/features/products/api/productStock'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

vi.mock('@/features/products/api/productStock', () => ({ getProductStock: vi.fn() }))
const getProductStockMock = vi.mocked(getProductStock)

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

describe('useProductStockLevels', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Test User',
        email: 'test@example.com',
        tenant_id: 'tenant-1',
        roles: [],
        email_verified_at: null,
      },
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1' })
    getProductStockMock.mockResolvedValue({
      locations: [],
      totals: {
        quantity: '0.0000',
        quantity_decimals: 4,
        reserved: '0.0000',
        available: '0.0000',
        incoming: '0.0000',
        projected_available: '0.0000',
      },
    })
  })

  afterEach(() => {
    act(() => {
      useAuthStore.setState({ user: null })
      useCompanyStore.setState({ currentCompanyId: null })
    })
    vi.clearAllMocks()
  })

  it('dedupes two concurrent consumers under the stock-levels root', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const Wrapper = makeWrapper(client)

    const first = renderHook(() => useProductStockLevels('p1', null, true), { wrapper: Wrapper })
    const second = renderHook(() => useProductStockLevels('p1', null, true), { wrapper: Wrapper })
    await waitFor(() => { expect(first.result.current.isSuccess && second.result.current.isSuccess).toBe(true) })

    expect(getProductStockMock).toHaveBeenCalledTimes(1)
    expect(client.getQueryCache().findAll({ queryKey: ['stock-levels'] })).toHaveLength(1)
  })

  it('does not request until both tenant and company scopes exist', async () => {
    useCompanyStore.setState({ currentCompanyId: null })
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const Wrapper = makeWrapper(client)
    const hook = renderHook(() => useProductStockLevels('p1', null, true), { wrapper: Wrapper })

    expect(hook.result.current.fetchStatus).toBe('idle')
    expect(getProductStockMock).not.toHaveBeenCalled()
    act(() => { useCompanyStore.setState({ currentCompanyId: 'company-1' }) })
    await waitFor(() => { expect(hook.result.current.isSuccess).toBe(true) })
    expect(getProductStockMock).toHaveBeenCalledTimes(1)
  })
})
