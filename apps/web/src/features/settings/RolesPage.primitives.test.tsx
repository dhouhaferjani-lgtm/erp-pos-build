import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { RolesPage } from './RolesPage'

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
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/roles') {
      return {
        data: {
          data: [
            { id: 1, name: 'manager', permissions: ['sales.view'], users_count: 0, created_at: null, updated_at: null },
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

describe('RolesPage shared primitives', () => {
  it('renders exactly one h1', async () => {
    render(<RolesPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    })
  })

  it('exposes the Add control as a button', async () => {
    render(<RolesPage />, { wrapper: wrapper() })
    const addButton = await screen.findByRole('button', { name: 'roles.addRole' })
    expect(addButton.tagName).toBe('BUTTON')
  })

  it('opens the create modal as a dialog', async () => {
    render(<RolesPage />, { wrapper: wrapper() })
    await userEvent.click(await screen.findByRole('button', { name: 'roles.addRole' }))
    expect(await screen.findByRole('dialog')).toBeInTheDocument()
  })
})
