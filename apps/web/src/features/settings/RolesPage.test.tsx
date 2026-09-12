import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { RolesPage } from './RolesPage'

const protectedRole = true
let currentRole: App.Modules.Identity.Application.DTOs.RoleData

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

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
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function setTenant() {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
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

function wrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant()
  currentRole = { id: 1, name: 'general_manager', guard_name: 'sanctum', permissions: ['sales.view'], users_count: 0, created_at: null, updated_at: null, is_provisioned_read_only: protectedRole }
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/roles') {
      return {
        data: {
          data: [
            currentRole,
          ],
        },
      }
    }
    if (url === '/permissions') return { data: { data: { sales: ['sales.view', 'sales.manage'] } } }
    return { data: { data: [] } }
  })
})

afterEach(() => {
  resetTenant()
})

describe('W-LOT-A-1a provisioned role read-only contract', () => {
  it('renders a marker-derived protected role without edit or delete affordances', async () => {
    currentRole.is_provisioned_read_only = true
    render(<RolesPage />, { wrapper: wrapper() })
    await screen.findByRole('heading', { name: /general_manager/ })
    expect(screen.getByText('roles.provisionedReadOnly')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'roles.editRole' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'roles.deleteRole' })).not.toBeInTheDocument()
  })
  it('does not treat an unmarked general_manager name as provisioned read-only', async () => {
    currentRole.is_provisioned_read_only = false
    render(<RolesPage />, { wrapper: wrapper() })
    expect(await screen.findByRole('button', { name: 'roles.editRole' })).toBeInTheDocument()
  })
  it('prevents update submission when the selected modal role object is tampered to be protected', async () => {
    currentRole.is_provisioned_read_only = false
    render(<RolesPage />, { wrapper: wrapper() })
    await userEvent.click(await screen.findByRole('button', { name: 'roles.editRole' }))
    // Tamper with the selected modal object; this is not a refetch (which would create a new object).
    currentRole.is_provisioned_read_only = true
    await userEvent.click(screen.getByRole('button', { name: 'actions.save' }))
    expect(mockApiPatch).not.toHaveBeenCalled()
  })
})
