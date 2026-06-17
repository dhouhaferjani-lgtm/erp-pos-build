import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { AddVehicleModal } from '../organisms/AddVehicleModal/AddVehicleModal'
import { LocationSwitcher } from '../organisms/LocationSwitcher/LocationSwitcher'
import { LocationField } from '../ui/LocationField'
import { PartnerSearchSelect } from '../ui/PartnerSearchSelect'
import { ProductSearchSelect } from '../ui/ProductSearchSelect'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockGetLocations = vi.hoisted(() => vi.fn())
const mockSwitchLocation = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPost: mockApiPost,
  }
})

vi.mock('@/features/locations/api/locations', () => ({
  getLocations: mockGetLocations,
}))

vi.mock('@/hooks/useLocation', () => ({
  useLocation: () => ({
    currentLocation: { id: 'loc-1', name: 'Shop', type: 'shop', isDefault: true },
    hasMultipleLocations: true,
    isLoading: false,
    locations: [
      { id: 'loc-1', name: 'Shop', type: 'shop', code: 'S1', isDefault: true },
      { id: 'loc-2', name: 'Warehouse', type: 'warehouse', code: 'W1', isDefault: false },
    ],
    switchLocation: mockSwitchLocation,
  }),
}))

vi.mock('../organisms/AddLocationModal', () => ({
  AddLocationModal: ({ isOpen }: { isOpen: boolean }) => isOpen ? <div>add-location-modal</div> : null,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
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

function createClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiPost.mockResolvedValue({
    data: {
      id: 'vehicle-1',
      partner_id: 'partner-1',
      license_plate: 'AB-123-CD',
      brand: 'Renault',
      model: 'Clio',
      year: null,
      color: null,
      vin: null,
    },
  })
  mockGetLocations.mockResolvedValue([
    { id: 'loc-1', name: 'Shop', type: 'shop', code: 'S1', isDefault: true },
  ])
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/locations/loc-1') return { data: { data: { id: 'loc-1', name: 'Shop', code: 'S1' } } }
    if (url.startsWith('/partners/partner-1')) {
      return { data: { data: { id: 'partner-1', name: 'Partner A', type: 'customer' } } }
    }
    if (url.startsWith('/partners')) {
      return { data: { data: [{ id: 'partner-1', name: 'Partner A', type: 'customer' }] } }
    }
    if (url === '/products/product-1') return { data: { data: { id: 'product-1', name: 'Product A', sku: 'P1', price: 10 } } }
    if (url.startsWith('/products')) {
      return { data: { data: [{ id: 'product-1', name: 'Product A', sku: 'P1', price: 10 }] } }
    }
    return { data: { data: [] } }
  })
})

afterEach(() => {
  resetTenant()
})

describe('shared selector tenant scope', () => {
  it('scopes AddVehicleModal invalidations (.004-.005)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    queryClient.setQueryData(['vehicles', 'tenant-A', 'company-1'], ['tenant-A-vehicles'])
    queryClient.setQueryData(['partner', 'partner-1', 'tenant-A', 'company-1'], { id: 'partner-1' })
    queryClient.setQueryData(['vehicles', 'tenant-B', 'company-2'], ['tenant-B-vehicles'])

    render(<AddVehicleModal isOpen={true} onClose={vi.fn()} partnerId="partner-1" />, { wrapper: wrapper(queryClient) })

    await user.type(screen.getByPlaceholderText('AB-123-CD'), 'AB-123-CD')
    await user.type(screen.getByPlaceholderText('Renault'), 'Renault')
    await user.type(screen.getByPlaceholderText('Clio'), 'Clio')
    await user.click(screen.getByRole('button', { name: 'common:actions.create' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/vehicles', expect.objectContaining({
        brand: 'Renault',
        license_plate: 'AB-123-CD',
        model: 'Clio',
        partner_id: 'partner-1',
      }))
      expect(queryClient.getQueryState(['vehicles', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
      expect(queryClient.getQueryState(['partner', 'partner-1', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    })
    expect(queryClient.getQueryState(['vehicles', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
  })

  it('scopes header location switch invalidations (.006-.007)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    queryClient.setQueryData(['stock-levels', 'tenant-A', 'company-1'], ['tenant-A-stock'])
    queryClient.setQueryData(['stock-movements', 'tenant-A', 'company-1'], ['tenant-A-movements'])
    queryClient.setQueryData(['stock-levels', 'tenant-B', 'company-2'], ['tenant-B-stock'])

    render(<LocationSwitcher />, { wrapper: wrapper(queryClient) })

    await user.click(screen.getByRole('button', { name: 'Select location' }))
    await user.click(screen.getByRole('button', { name: /Warehouse/ }))

    expect(mockSwitchLocation).toHaveBeenCalledWith('loc-2')
    expect(queryClient.getQueryState(['stock-levels', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    expect(queryClient.getQueryState(['stock-movements', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    expect(queryClient.getQueryState(['stock-levels', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
  })

  it('scopes location, partner, and product selector reads (.020-.025)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    render(
      <div>
        <LocationField value="loc-1" onChange={vi.fn()} />
        <PartnerSearchSelect value="partner-1" onChange={vi.fn()} partnerType="customer" />
        <ProductSearchSelect value="product-1" onChange={vi.fn()} />
      </div>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(queryClient.getQueryData(['location', 'loc-1', 'tenant-A', 'company-1'])).toEqual({ id: 'loc-1', name: 'Shop', code: 'S1' })
      expect(queryClient.getQueryData(['partner', 'partner-1', 'tenant-A', 'company-1'])).toEqual({ id: 'partner-1', name: 'Partner A', type: 'customer' })
      expect(queryClient.getQueryData(['product', 'product-1', 'tenant-A', 'company-1'])).toEqual({ id: 'product-1', name: 'Product A', sku: 'P1', price: 10 })
    })

    await user.click(screen.getByText(/Shop/))
    await waitFor(() => {
      expect(queryClient.getQueryData(['locations', 'tenant-A', 'company-1'])).toEqual([
        { id: 'loc-1', name: 'Shop', type: 'shop', code: 'S1', isDefault: true },
      ])
    })

    await user.click(screen.getByText('Partner A'))
    await waitFor(() => {
      expect(queryClient.getQueryData(['partners-search', 'customer', '', 'tenant-A', 'company-1'])).toEqual({
        data: [{ id: 'partner-1', name: 'Partner A', type: 'customer' }],
      })
    })

    await user.click(screen.getByText(/Product A/))
    await waitFor(() => {
      expect(queryClient.getQueryData(['products-search', '', 'tenant-A', 'company-1'])).toEqual({
        data: [{ id: 'product-1', name: 'Product A', sku: 'P1', price: 10 }],
      })
    })
  })

  it('does not fetch shared selector reads without tenant/company state', async () => {
    resetTenant()

    render(<ProductSearchSelect value="product-1" onChange={vi.fn()} />, { wrapper: wrapper(createClient()) })
    await userEvent.click(screen.getByText('common:actions.select'))

    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
