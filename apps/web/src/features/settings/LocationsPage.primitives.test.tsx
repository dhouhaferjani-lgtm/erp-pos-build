import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { LocationsPage } from './LocationsPage'

const mockFetchLocations = vi.hoisted(() => vi.fn())
const mockCreateLocation = vi.hoisted(() => vi.fn())
const mockUpdateLocation = vi.hoisted(() => vi.fn())
const mockDeleteLocation = vi.hoisted(() => vi.fn())
const mockSetDefaultLocation = vi.hoisted(() => vi.fn())

vi.mock('../location/api', () => ({
  createLocation: mockCreateLocation,
  deleteLocation: mockDeleteLocation,
  fetchLocations: mockFetchLocations,
  setDefaultLocation: mockSetDefaultLocation,
  updateLocation: mockUpdateLocation,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: ({ confirmText, isOpen }: { confirmText: string; isOpen: boolean }) =>
    isOpen ? <button type="button">{confirmText}</button> : null,
}))

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

function locationFixture() {
  return {
    id: 'location-1',
    company_id: 'company-1',
    name: 'Main Shop',
    code: 'MAIN',
    type: 'shop',
    phone: null,
    email: null,
    address_street: null,
    address_city: 'Tunis',
    address_postal_code: null,
    address_country: 'TN',
    tax_id: null,
    vat_number: null,
    legal_identifiers: null,
    is_default: false,
    is_active: true,
    pos_enabled: true,
    created_at: '2026-05-11T09:00:00Z',
    updated_at: '2026-05-11T09:00:00Z',
  }
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
  mockFetchLocations.mockResolvedValue([locationFixture()])
  mockCreateLocation.mockResolvedValue(locationFixture())
  mockUpdateLocation.mockResolvedValue(locationFixture())
  mockDeleteLocation.mockResolvedValue({})
  mockSetDefaultLocation.mockResolvedValue(locationFixture())
})

afterEach(() => {
  resetTenant()
})

describe('LocationsPage shared primitives', () => {
  it('renders exactly one h1', async () => {
    render(<LocationsPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    })
  })

  it('exposes the Add control as a button', async () => {
    render(<LocationsPage />, { wrapper: wrapper() })
    const addButton = await screen.findByRole('button', { name: 'locations.addLocation' })
    expect(addButton.tagName).toBe('BUTTON')
  })

  it('opens the create modal as a dialog', async () => {
    render(<LocationsPage />, { wrapper: wrapper() })
    await userEvent.click(await screen.findByRole('button', { name: 'locations.addLocation' }))
    expect(await screen.findByRole('dialog')).toBeInTheDocument()
  })

  it('shows the per-branch scope hint under the page title', async () => {
    render(<LocationsPage />, { wrapper: wrapper() })
    await screen.findByRole('button', { name: 'locations.addLocation' })
    expect(screen.getByText('settings:locations.scopeHint')).toBeInTheDocument()
  })
})
