import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { useUser, useUsers } from '../useUsers'

const mockGetUsers = vi.hoisted(() => vi.fn())
const mockGetUser = vi.hoisted(() => vi.fn())

vi.mock('../../api/users', () => ({ getUser: mockGetUser, getUsers: mockGetUsers }))

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
  mockGetUsers.mockResolvedValue({ data: [{ id: 'user-2' }], meta: {}, links: {} })
  mockGetUser.mockResolvedValue({ id: 'user-2' })
})

afterEach(() => {
  resetTenant()
})

describe('user hooks tenant scope', () => {
  it('wraps user keys and gates missing tenant/company (.748-.749)', async () => {
    const queryClient = createClient()
    const params = { search: 'test' }
    const { result } = renderHook(() => ({
      detail: useUser('user-2'),
      list: useUsers(params),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.list.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['users', 'detail', 'user-2', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['users', 'list', params, 'tenant-A', 'company-1'])).toBeDefined()

    resetTenant()
    const gatedClient = createClient()
    renderHook(() => useUsers(), { wrapper: wrapper(gatedClient) })
    expect(mockGetUsers).toHaveBeenCalledTimes(1)
  })
})
