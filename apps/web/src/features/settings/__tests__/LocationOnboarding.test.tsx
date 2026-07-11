import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { LocationsPage } from '../LocationsPage'

const mockFetchLocations = vi.hoisted(() => vi.fn())
const mockCreateLocation = vi.hoisted(() => vi.fn())
const mockUpdateLocation = vi.hoisted(() => vi.fn())
const mockDeleteLocation = vi.hoisted(() => vi.fn())
const mockSetDefaultLocation = vi.hoisted(() => vi.fn())

vi.mock('../../locations/api', () => ({
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

vi.mock('../../../components/ui/ConfirmDialog', () => ({
  ConfirmDialog: ({ confirmText, isOpen }: { confirmText: string; isOpen: boolean }) =>
    isOpen ? <button type="button">{confirmText}</button> : null,
}))

vi.mock('../hooks/useCountryProfile', () => ({
  useCountryProfile: () => ({ profile: null, isLoading: false }),
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

function locationFixture(overrides: Partial<Record<string, unknown>> = {}) {
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
    onboarding_mode: false,
    pos_stock_policy_override: null,
    created_at: '2026-05-11T09:00:00Z',
    updated_at: '2026-05-11T09:00:00Z',
    ...overrides,
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

describe('LocationsPage onboarding mode + stock policy override', () => {
  it('renders the onboarding switch and the policy override select in the edit form', async () => {
    render(<LocationsPage />, { wrapper: wrapper() })
    await userEvent.click(await screen.findByRole('button', { name: /actions\.edit/ }))

    expect(screen.getByRole('switch', { name: 'locations.form.onboardingMode' })).toBeInTheDocument()
    expect(screen.getByLabelText('locations.form.posStockPolicyOverride')).toBeInTheDocument()
  })

  it('round-trips onboarding_mode=true and a non-null override from a location fixture', async () => {
    mockFetchLocations.mockResolvedValue([
      locationFixture({ onboarding_mode: true, pos_stock_policy_override: 'warn' }),
    ])
    render(<LocationsPage />, { wrapper: wrapper() })
    await userEvent.click(await screen.findByRole('button', { name: /actions\.edit/ }))

    expect(screen.getByRole('switch', { name: 'locations.form.onboardingMode' })).toBeChecked()
    expect(screen.getByLabelText('locations.form.posStockPolicyOverride')).toHaveValue('warn')
  })

  it('defaults a location with a null override to the inherit option', async () => {
    render(<LocationsPage />, { wrapper: wrapper() })
    await userEvent.click(await screen.findByRole('button', { name: /actions\.edit/ }))

    expect(screen.getByRole('switch', { name: 'locations.form.onboardingMode' })).not.toBeChecked()
    expect(screen.getByLabelText('locations.form.posStockPolicyOverride')).toHaveValue('inherit')
  })

  it('toggling onboarding on and submitting sends onboarding_mode: true', async () => {
    render(<LocationsPage />, { wrapper: wrapper() })
    await userEvent.click(await screen.findByRole('button', { name: /actions\.edit/ }))

    await userEvent.click(screen.getByRole('switch', { name: 'locations.form.onboardingMode' }))
    await userEvent.click(screen.getByRole('button', { name: 'save' }))

    await waitFor(() => {
      expect(mockUpdateLocation).toHaveBeenCalledWith(
        'location-1',
        expect.objectContaining({ onboardingMode: true }),
      )
    })
  })

  it('selecting a policy override and submitting maps it straight through', async () => {
    render(<LocationsPage />, { wrapper: wrapper() })
    await userEvent.click(await screen.findByRole('button', { name: /actions\.edit/ }))

    await userEvent.selectOptions(screen.getByLabelText('locations.form.posStockPolicyOverride'), 'block')
    await userEvent.click(screen.getByRole('button', { name: 'save' }))

    await waitFor(() => {
      expect(mockUpdateLocation).toHaveBeenCalledWith(
        'location-1',
        expect.objectContaining({ posStockPolicyOverride: 'block' }),
      )
    })
  })

  it('selecting "inherit" and submitting sends posStockPolicyOverride: null', async () => {
    mockFetchLocations.mockResolvedValue([
      locationFixture({ pos_stock_policy_override: 'off' }),
    ])
    render(<LocationsPage />, { wrapper: wrapper() })
    await userEvent.click(await screen.findByRole('button', { name: /actions\.edit/ }))

    await userEvent.selectOptions(screen.getByLabelText('locations.form.posStockPolicyOverride'), 'inherit')
    await userEvent.click(screen.getByRole('button', { name: 'save' }))

    await waitFor(() => {
      expect(mockUpdateLocation).toHaveBeenCalledWith(
        'location-1',
        expect.objectContaining({ posStockPolicyOverride: null }),
      )
    })
  })

  it('sends both fields on create', async () => {
    render(<LocationsPage />, { wrapper: wrapper() })
    await userEvent.click(await screen.findByRole('button', { name: 'locations.addLocation' }))
    await screen.findByRole('dialog')

    await userEvent.type(screen.getByLabelText(/locations\.form\.name/), 'New Shop')
    await userEvent.click(screen.getByRole('switch', { name: 'locations.form.onboardingMode' }))
    await userEvent.selectOptions(screen.getByLabelText('locations.form.posStockPolicyOverride'), 'warn')
    await userEvent.click(screen.getByRole('button', { name: 'save' }))

    await waitFor(() => {
      expect(mockCreateLocation).toHaveBeenCalledWith(
        expect.objectContaining({ onboardingMode: true, posStockPolicyOverride: 'warn' }),
      )
    })
  })
})
