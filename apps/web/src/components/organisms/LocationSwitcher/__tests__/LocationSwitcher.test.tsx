import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { Location } from '@/stores/locationStore'

import { LocationSwitcher } from '../LocationSwitcher'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@/components/organisms/AddLocationModal', () => ({
  AddLocationModal: () => null,
}))

const mockSwitchLocation = vi.hoisted(() => vi.fn())

function locationFixture(overrides: Partial<Location>): Location {
  return {
    id: 'location-1',
    companyId: 'company-1',
    name: 'Main Shop',
    code: 'MAIN',
    type: 'shop',
    phone: null,
    email: null,
    addressStreet: null,
    addressCity: null,
    addressPostalCode: null,
    addressCountry: null,
    isDefault: true,
    isActive: true,
    posEnabled: true,
    createdAt: '2026-05-11T09:00:00Z',
    updatedAt: '2026-05-11T09:00:00Z',
    ...overrides,
  }
}

const locations = [
  locationFixture({ id: 'location-1', name: 'Main Shop' }),
  locationFixture({ id: 'location-2', name: 'Branch Sfax', isDefault: false }),
]

vi.mock('@/hooks/useLocation', () => ({
  useLocation: () => ({
    currentLocation: locations[0],
    currentLocationId: 'location-1',
    locations,
    isLoading: false,
    hasMultipleLocations: true,
    switchLocation: mockSwitchLocation,
  }),
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [
      {
        id: companyId,
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function renderSwitcher() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  const result = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/']}>
        <Routes>
          <Route path="/" element={<LocationSwitcher />} />
          <Route path="/settings/locations" element={<div>locations page probe</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return { ...result, queryClient }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
})

afterEach(() => {
  resetTenant()
})

describe('LocationSwitcher manage-locations entry', () => {
  it('shows a Manage locations item in the dropdown that navigates to /settings/locations', async () => {
    const user = userEvent.setup()
    renderSwitcher()

    await user.click(screen.getByRole('button', { name: 'common:locations.selectLocation' }))

    const manageItem = screen.getByRole('button', { name: /locations\.manageLocations/i })
    await user.click(manageItem)

    expect(screen.getByText('locations page probe')).toBeInTheDocument()
  })
})

describe('LocationSwitcher location-switch invalidation', () => {
  it('invalidates active tenant/company queries on location switch', async () => {
    const user = userEvent.setup()
    const { queryClient } = renderSwitcher()
    queryClient.setQueryData(['stock-levels', 'tenant-A', 'company-1'], ['tenant-A-stock'])
    queryClient.setQueryData(['stock-movements', 'tenant-A', 'company-1'], ['tenant-A-movements'])
    queryClient.setQueryData(['stock-levels', 'tenant-B', 'company-2'], ['tenant-B-stock'])

    await user.click(screen.getByRole('button', { name: 'common:locations.selectLocation' }))
    await user.click(screen.getByRole('button', { name: /Branch Sfax/ }))

    expect(mockSwitchLocation).toHaveBeenCalledWith('location-2')
    expect(queryClient.getQueryState(['stock-levels', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    expect(queryClient.getQueryState(['stock-movements', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    expect(queryClient.getQueryState(['stock-levels', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
  })

  it('does not invalidate when re-selecting the current location', async () => {
    const user = userEvent.setup()
    const { queryClient } = renderSwitcher()
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')

    await user.click(screen.getByRole('button', { name: 'common:locations.selectLocation' }))
    await user.click(screen.getByRole('button', { name: /Main Shop/ }))

    expect(invalidateSpy).not.toHaveBeenCalled()
  })
})
