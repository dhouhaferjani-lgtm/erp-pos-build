import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { LocationsPage } from '../LocationsPage'

const mockFetchLocations = vi.hoisted(() => vi.fn())
const mockCreateLocation = vi.hoisted(() => vi.fn())
const mockUpdateLocation = vi.hoisted(() => vi.fn())
const mockDeleteLocation = vi.hoisted(() => vi.fn())
const mockSetDefaultLocation = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

vi.mock('../../locations/api', () => ({
  createLocation: mockCreateLocation,
  deleteLocation: mockDeleteLocation,
  fetchLocations: mockFetchLocations,
  setDefaultLocation: mockSetDefaultLocation,
  updateLocation: mockUpdateLocation,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: ({
    confirmText,
    isOpen,
    onConfirm,
  }: {
    confirmText: string
    isOpen: boolean
    onConfirm: () => void
  }) => (isOpen ? <button type="button" onClick={onConfirm}>{confirmText}</button> : null),
}))

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

function Probe({ queryKey, queryFn }: { queryKey: readonly unknown[]; queryFn: () => Promise<unknown> }) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  useQuery({ queryKey: tenantScopedKey(queryKey), queryFn, enabled: tenantId !== null && companyId !== null })
  return null
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockFetchLocations.mockResolvedValue([locationFixture()])
  mockCreateLocation.mockResolvedValue(locationFixture())
  mockUpdateLocation.mockResolvedValue(locationFixture())
  mockDeleteLocation.mockResolvedValue({})
  mockSetDefaultLocation.mockResolvedValue(locationFixture())
})

afterEach(() => {
  resetTenant()
})

describe('LocationsPage branch tax identity form', () => {
  it('renders tax fields, marks shop tax ID as required for configured countries, and submits them', async () => {
    render(<LocationsPage />, { wrapper: wrapper(createClient()) })

    await userEvent.click(await screen.findByRole('button', { name: 'locations.addLocation' }))
    await userEvent.type(await screen.findByLabelText(/locations\.form\.name/), 'Paris Shop')
    await userEvent.type(screen.getByLabelText(/locations\.form\.country/), 'FR')

    const taxIdInput = screen.getByLabelText(/locations\.form\.taxId/)
    expect(taxIdInput).toHaveAttribute('aria-required', 'true')
    expect(screen.getByText('locations.form.taxIdRequiredHint')).toBeInTheDocument()

    await userEvent.type(taxIdInput, '73282932000074')
    await userEvent.type(screen.getByLabelText(/locations\.form\.vatNumber/), 'FR40303265045')

    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'save' }))
    })

    await waitFor(() => {
      expect(mockCreateLocation).toHaveBeenCalledWith(expect.objectContaining({
        addressCountry: 'FR',
        name: 'Paris Shop',
        taxId: '73282932000074',
        vatNumber: 'FR40303265045',
      }))
    })
  })
})

describe('LocationsPage tenant scope', () => {
  it('wraps locations read key and gates missing tenant/company (.629)', async () => {
    const queryClient = createClient()
    render(<LocationsPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['locations', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockFetchLocations.mock.calls.length
    render(<LocationsPage />, { wrapper: wrapper(createClient()) })
    expect(mockFetchLocations).toHaveBeenCalledTimes(calls)
  })

  it('invalidates location mutations for only the active tenant (.630-.633)', async () => {
    const queryClient = createClient()
    let locationCalls = 0
    queryClient.setQueryData(['locations', 'tenant-B', 'company-1'], { marker: 'tenant-B-locations' })

    render(
      <>
        <Probe queryKey={['locations', 'probe']} queryFn={async () => [`locations-${++locationCalls}`]} />
        <LocationsPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockFetchLocations).toHaveBeenCalledTimes(1)
      expect(locationCalls).toBe(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'locations.addLocation' }))
    await userEvent.type(await screen.findByLabelText(/locations\.form\.name/), 'Branch')
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'save' }))
    })
    await waitFor(() => {
      expect(locationCalls).toBe(2)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'actions.edit' }))
    await userEvent.clear(await screen.findByLabelText(/locations\.form\.name/))
    await userEvent.type(screen.getByLabelText(/locations\.form\.name/), 'Main Shop Updated')
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'save' }))
    })
    await waitFor(() => {
      expect(locationCalls).toBe(3)
    })

    await act(async () => {
      await userEvent.click(await screen.findByRole('button', { name: 'locations.setDefault' }))
    })
    await waitFor(() => {
      expect(locationCalls).toBe(4)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'actions.delete' }))
    await act(async () => {
      await userEvent.click(screen.getAllByRole('button', { name: 'actions.delete' }).at(-1)!)
    })
    await waitFor(() => {
      expect(locationCalls).toBe(5)
    })

    expect(queryClient.getQueryData(['locations', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-locations' })
  })
})
