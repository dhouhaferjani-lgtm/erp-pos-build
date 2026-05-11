import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { UsersPage } from '../UsersPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      delete: mockApiDelete,
      get: mockApiGet,
      patch: mockApiPatch,
      post: mockApiPost,
    },
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

vi.mock('@/components/ui/ActionMenu', () => ({
  ActionMenu: ({ items }: { items: Array<{ key: string; onClick: () => void }> }) => (
    <div>
      {items.map((item) => (
        <button key={item.key} type="button" onClick={item.onClick}>
          {item.key}
        </button>
      ))}
    </div>
  ),
}))

vi.mock('@/components/ui/SearchInput', () => ({
  SearchInput: ({ onChange, value }: { onChange: (value: string) => void; value: string }) => (
    <input aria-label="search-users" value={value} onChange={(event) => { onChange(event.target.value) }} />
  ),
}))

vi.mock('@/components/ui/FilterTabs', () => ({
  FilterTabs: ({ onChange, value }: { onChange: (value: string) => void; value: string }) => (
    <select aria-label="status-filter" value={value} onChange={(event) => { onChange(event.target.value) }}>
      <option value="all">all</option>
      <option value="active">active</option>
    </select>
  ),
}))

vi.mock('../components/UserEditModal', () => ({
  UserEditModal: () => null,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'current-user',
      name: 'Current User',
      email: 'current@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
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
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function usersResponse() {
  return {
    data: [
      {
        id: 'user-1',
        name: 'Inactive User',
        email: 'inactive@example.test',
        phone: null,
        roles: ['operator'],
        status: 'inactive',
        lastLoginAt: null,
      },
      {
        id: 'user-2',
        name: 'Active User',
        email: 'active@example.test',
        phone: null,
        roles: ['manager'],
        status: 'active',
        lastLoginAt: null,
      },
    ],
    meta: { total: 2, current_page: 1, per_page: 20, last_page: 1 },
  }
}

function mockSettingsResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/users')) return { data: usersResponse() }
    if (url === '/roles') return { data: { data: [{ name: 'operator', permissions: [] }, { name: 'manager', permissions: [] }] } }
    return { data: { data: [] } }
  })
}

function Probe({ queryKey, queryFn }: { queryKey: readonly unknown[]; queryFn: () => Promise<unknown> }) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  useQuery({ queryKey: tenantScopedKey(queryKey), queryFn, enabled: tenantId !== null && companyId !== null })
  return null
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.stubGlobal('confirm', vi.fn(() => true))
  setTenant('tenant-A', 'company-1')
  mockSettingsResponses()
  mockApiPost.mockResolvedValue({ data: { data: usersResponse().data[0] } })
  mockApiDelete.mockResolvedValue({ data: {} })
  mockApiPatch.mockResolvedValue({ data: {} })
})

afterEach(() => {
  vi.unstubAllGlobals()
  resetTenant()
})

describe('UsersPage tenant scope', () => {
  it('wraps users and roles read keys and gates missing tenant/company (.642-.643)', async () => {
    const queryClient = createClient()
    render(<UsersPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['users', '', 'all', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['roles', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<UsersPage />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('invalidates user mutations for only the active tenant (.644-.647)', async () => {
    const queryClient = createClient()
    let usersCalls = 0
    queryClient.setQueryData(['users', 'tenant-B', 'company-1'], { marker: 'tenant-B-users' })

    render(
      <>
        <Probe queryKey={['users', 'probe']} queryFn={async () => [`users-${++usersCalls}`]} />
        <UsersPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => String(url).startsWith('/users'))).toHaveLength(1)
      expect(usersCalls).toBe(1)
    })

    await userEvent.click(screen.getByRole('button', { name: 'users.addUser' }))
    await userEvent.type(await screen.findByLabelText(/users\.modal\.nameLabel/), 'New User')
    await userEvent.type(screen.getByLabelText(/users\.modal\.emailLabel/), 'new@example.test')
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'users.modal.createUser' }))
    })

    await waitFor(() => {
      expect(usersCalls).toBe(2)
    })

    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'activate' }))
    })
    await waitFor(() => {
      expect(usersCalls).toBe(3)
    })

    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'deactivate' }))
    })
    await waitFor(() => {
      expect(usersCalls).toBe(4)
    })

    await act(async () => {
      await userEvent.click(screen.getAllByRole('button', { name: 'delete' })[0])
    })
    await waitFor(() => {
      expect(usersCalls).toBe(5)
    })

    expect(queryClient.getQueryData(['users', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-users' })
  })
})
