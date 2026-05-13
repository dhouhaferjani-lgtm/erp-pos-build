import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { usePaymentRepositories, usePaymentRepository } from '../usePaymentRepositories'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({ api: { get: mockApiGet } }))

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

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockResolvedValue({ data: { data: [{ id: 'repository-1', is_active: true }] } })
})

afterEach(() => {
  resetTenant()
})

describe('payment repository hooks tenant scope', () => {
  it('wraps repository keys and gates missing tenant/company (.716-.717)', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => ({
      detail: usePaymentRepository('repository-1'),
      list: usePaymentRepositories(),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.list.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['payment-repositories', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['payment-repository', 'repository-1', 'tenant-A', 'company-1'])).toBeDefined()

    resetTenant()
    const gatedClient = createClient()
    renderHook(() => usePaymentRepositories(), { wrapper: wrapper(gatedClient) })
    expect(mockApiGet).toHaveBeenCalledTimes(2)
  })
})
