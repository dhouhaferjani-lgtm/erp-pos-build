import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { UsersPage } from './UsersPage'

const apiGet = vi.hoisted(() => vi.fn())
const apiPost = vi.hoisted(() => vi.fn())
const useManagementLocations = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: { get: apiGet, post: apiPost, patch: vi.fn(), delete: vi.fn() }, getErrorMessage: () => 'request failed' }
})
vi.mock('@/features/locations/hooks/useManagementLocations', () => ({ useManagementLocations }))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return { ...actual, Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a> }
})
vi.mock('@/components/ui/ActionMenu', () => ({ ActionMenu: () => <div /> }))
vi.mock('@/components/molecules/SearchInput', () => ({ SearchInput: () => <input aria-label="search-users" /> }))
vi.mock('@/components/molecules/FilterTabs', () => ({ FilterTabs: () => <div /> }))
vi.mock('./components/UserEditModal', () => ({ UserEditModal: () => null }))

function setUser(roles: string[]) {
  useAuthStore.setState({ user: { id: 'u-current', name: 'Admin', email: 'a@test.test', tenant_id: 'tenant-1', roles, email_verified_at: null }, token: 'token', isAuthenticated: true, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(<UsersPage />, { wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider> })
}

beforeEach(() => {
  vi.clearAllMocks()
  setUser(['admin'])
  useManagementLocations.mockReturnValue({ data: [{ id: 'loc-a', name: 'Main', code: 'HQ', type: 'shop', isDefault: true }], isLoading: false })
  apiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/users')) return { data: { data: [], meta: { total: 0 } } }
    if (url === '/roles') return { data: { data: [{ name: 'operator', permissions: [] }] } }
    return { data: { data: [] } }
  })
  apiPost.mockResolvedValue({ data: { data: {} } })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('UsersPage location access', () => {
  it('shows the field and sends an assignment when permitted', async () => {
    renderPage()
    fireEvent.click((await screen.findAllByRole('button', { name: 'users.addUser' })).at(-1)!)
    expect(screen.getByText('locations:staffAccess.label')).toBeInTheDocument()
    fireEvent.change(document.getElementById('name')!, { target: { value: 'New User' } })
    fireEvent.change(document.getElementById('email')!, { target: { value: 'new@test.test' } })
    fireEvent.click(screen.getByRole('radio', { name: /locations:staffAccess\.subset/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: /Main/ }))
    fireEvent.click(screen.getByRole('button', { name: 'users.modal.createUser' }))
    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/users', expect.objectContaining({ allowed_location_ids: ['loc-a'] })))
  })

  it('hides the field and omits the assignment without permission', async () => {
    setUser(['manager'])
    renderPage()
    fireEvent.click((await screen.findAllByRole('button', { name: 'users.addUser' })).at(-1)!)
    expect(screen.queryByText('locations:staffAccess.label')).not.toBeInTheDocument()
    fireEvent.change(document.getElementById('name')!, { target: { value: 'New User' } })
    fireEvent.change(document.getElementById('email')!, { target: { value: 'new@test.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'users.modal.createUser' }))
    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/users', expect.not.objectContaining({ allowed_location_ids: expect.anything() })))
  })
})
