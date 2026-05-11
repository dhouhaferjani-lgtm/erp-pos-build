import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement } from 'react'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useMembers, useMember } from '../useMembers'

vi.mock('../../api/memberApi', () => ({
  listMembers: vi.fn().mockResolvedValue({
    data: [{ id: '1', phone: '+33612345678', first_name: 'John', status: 'active' }],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getMember: vi.fn().mockResolvedValue(
    { id: '1', phone: '+33612345678', first_name: 'John', status: 'active' },
  ),
  createMember: vi.fn(),
  updateMember: vi.fn(),
  listEnrollments: vi.fn(),
  enrollMember: vi.fn(),
  optOutEnrollment: vi.fn(),
  reactivateEnrollment: vi.fn(),
  listTransactions: vi.fn(),
  adjustPoints: vi.fn(),
}))

function createWrapper() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return createElement(QueryClientProvider, { client: queryClient }, children)
  }
}

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

beforeEach(() => {
  setTenant('tenant-A', 'company-1')
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('useMembers', () => {
  it('fetches paginated members list', async () => {
    const { result } = renderHook(() => useMembers(), { wrapper: createWrapper() })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true); })
    expect(result.current.data?.data).toHaveLength(1)
    expect(result.current.data?.meta.total).toBe(1)
  })
})

describe('useMember', () => {
  it('fetches single member', async () => {
    const { result } = renderHook(() => useMember('1'), { wrapper: createWrapper() })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true); })
    expect(result.current.data?.phone).toBe('+33612345678')
  })

  it('does not fetch when id is empty', () => {
    const { result } = renderHook(() => useMember(''), { wrapper: createWrapper() })
    expect(result.current.isFetching).toBe(false)
  })
})
