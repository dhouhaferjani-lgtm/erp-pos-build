import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { RolesPage } from '../RolesPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
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

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
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

function rolesResponse() {
  return {
    data: [
      {
        id: 1,
        name: 'manager',
        permissions: ['sales.view'],
        users_count: 0,
        created_at: null,
        updated_at: null,
      },
    ],
  }
}

function permissionsResponse() {
  return {
    data: {
      sales: ['sales.view', 'sales.manage'],
    },
  }
}

function mockRoleResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/roles') return { data: rolesResponse() }
    if (url === '/permissions') return { data: permissionsResponse() }
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
  setTenant('tenant-A', 'company-1')
  mockRoleResponses()
  mockApiPost.mockResolvedValue({ data: rolesResponse().data[0] })
  mockApiPatch.mockResolvedValue({ data: rolesResponse().data[0] })
  mockApiDelete.mockResolvedValue({ data: {} })
})

afterEach(() => {
  resetTenant()
})

describe('RolesPage tenant scope', () => {
  it('wraps roles and permissions read keys and gates missing tenant/company (.634-.635)', async () => {
    const queryClient = createClient()
    render(<RolesPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['roles', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['permissions', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<RolesPage />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('invalidates role mutations for only the active tenant (.636-.638)', async () => {
    const queryClient = createClient()
    let roleCalls = 0
    queryClient.setQueryData(['roles', 'tenant-B', 'company-1'], { marker: 'tenant-B-roles' })

    render(
      <>
        <Probe queryKey={['roles', 'probe']} queryFn={async () => [`roles-${++roleCalls}`]} />
        <RolesPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/roles')).toHaveLength(1)
      expect(roleCalls).toBe(1)
    })

    await userEvent.click(screen.getByRole('button', { name: 'roles.addRole' }))
    await userEvent.type(await screen.findByPlaceholderText('roles.roleNamePlaceholder'), 'cashier')
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'roles.createRole' }))
    })
    await waitFor(() => {
      expect(roleCalls).toBe(2)
    })

    await userEvent.click(screen.getByTitle('roles.editRole'))
    const roleNameInput = await screen.findByPlaceholderText('roles.roleNamePlaceholder')
    await userEvent.clear(roleNameInput)
    await userEvent.type(roleNameInput, 'manager-updated')
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'actions.save' }))
    })
    await waitFor(() => {
      expect(roleCalls).toBe(3)
    })

    await userEvent.click(screen.getByTitle('roles.deleteRole'))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'roles.confirmations.delete.confirm' }))
    })
    await waitFor(() => {
      expect(roleCalls).toBe(4)
    })

    expect(queryClient.getQueryData(['roles', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-roles' })
  })
})
