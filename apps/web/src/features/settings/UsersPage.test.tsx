import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { UsersPage } from './UsersPage'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      post: vi.fn(),
      patch: vi.fn(),
      delete: vi.fn(),
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
    t: (key: string, second?: unknown) => (typeof second === 'string' ? second : key),
  }),
}))

vi.mock('@/components/ui/ActionMenu', () => ({
  ActionMenu: () => <div data-testid="action-menu" />,
}))

vi.mock('@/components/molecules/SearchInput', () => ({
  SearchInput: () => <input aria-label="search-users" />,
}))

vi.mock('@/components/molecules/FilterTabs', () => ({
  FilterTabs: () => <div data-testid="filter-tabs" />,
}))

vi.mock('./components/UserEditModal', () => ({
  UserEditModal: () => null,
}))

function setTenant() {
  useAuthStore.setState({
    user: {
      id: 'current-user',
      name: 'Current User',
      email: 'current@example.test',
      tenant_id: 'tenant-A',
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function usersResponse() {
  return {
    data: [
      {
        id: 'user-1',
        name: 'Active User',
        email: 'active@example.test',
        phone: null,
        roles: ['manager'],
        status: 'active',
        lastLoginAt: null,
      },
    ],
    meta: { total: 1, current_page: 1, per_page: 20, last_page: 1 },
  }
}

function wrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant()
  mockApiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/users')) return { data: usersResponse() }
    if (url === '/roles') return { data: { data: [{ name: 'manager', permissions: [] }] } }
    return { data: { data: [] } }
  })
})

afterEach(() => {
  resetTenant()
})

describe('UsersPage shared primitives', () => {
  it('renders exactly one h1 via ListPageLayout/PageHeader', async () => {
    render(<UsersPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getByText('Active User')).toBeInTheDocument()
    })
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders the user status via StatusBadge (rounded-full pill)', async () => {
    render(<UsersPage />, { wrapper: wrapper() })
    const statusLabel = await screen.findByText('users.statusLabels.active')
    expect(statusLabel.className).toContain('rounded-full')
  })

  it('renders an Add User action button', async () => {
    render(<UsersPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'users.addUser' })).toBeInTheDocument()
    })
  })
})
