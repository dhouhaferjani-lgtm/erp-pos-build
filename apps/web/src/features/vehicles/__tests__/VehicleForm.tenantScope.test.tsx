import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { makePartnerData, type PartnerData } from '@/features/partners/__fixtures__/partner'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { VehicleForm } from '../VehicleForm'
import type { VehicleData } from '../types'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const mockRouteId = vi.hoisted(() => ({ current: '' }))
const mockTranslate = vi.hoisted(() =>
  vi.fn((key: string) => {
    const translations: Record<string, string> = {
      'vehicles:licensePlate': 'License Plate',
      'vehicles:brand': 'Brand',
      'vehicles:model': 'Model',
      'vehicles:owner': 'Owner',
      'common:actions.save': 'actions.save',
      'partner.searchPlaceholder': 'Search partners',
    }
    return translations[key] ?? key
  }),
)

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPatch: mockApiPatch,
    apiPost: mockApiPost,
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
    useNavigate: () => mockNavigate,
    useParams: () => ({ id: mockRouteId.current }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
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

function vehicleFixture(): VehicleData {
  return {
    id: 'vehicle-1',
    tenant_id: 'tenant-A',
    company_id: 'company-1',
    partner_id: 'partner-1',
    license_plate: '123-TUN-456',
    brand: 'Renault',
    model: 'Clio',
    year: 2020,
    color: 'Blue',
    mileage: 45000,
    vin: null,
    engine_code: null,
    fuel_type: 'gasoline',
    transmission: 'manual',
    body_type: 'hatchback',
    notes: null,
    current_owner_partner_id: 'partner-1',
    current_owner_display_name: 'Partner A',
    created_at: '2026-08-29T12:00:00Z',
    updated_at: null,
  }
}

function partnerFixture(overrides: Partial<PartnerData> = {}): PartnerData {
  return makePartnerData({
    id: 'partner-1',
    name: 'Partner A',
    type: 'customer',
    email: null,
    city: null,
    ...overrides,
  })
}

function mockVehicleResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/partners?')) {
      return {
        data: {
          data: [partnerFixture()],
        },
      }
    }
    if (url === '/partners/partner-1') {
      return {
        data: {
          data: partnerFixture(),
        },
      }
    }
    if (url === '/vehicles/vehicle-1') return { data: { data: vehicleFixture() } }
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
  mockRouteId.current = ''
  setTenant('tenant-A', 'company-1')
  mockVehicleResponses()
  mockApiPost.mockResolvedValue({ data: { data: vehicleFixture() } })
  mockApiPatch.mockResolvedValue({ data: { data: vehicleFixture() } })
})

afterEach(() => {
  act(() => { resetTenant() })
})

describe('VehicleForm tenant scope', () => {
  it('wraps the vehicle read key and gates missing tenant/company (.756-.757)', async () => {
    mockRouteId.current = 'vehicle-1'
    const editQueryClient = createClient()
    const edit = render(<VehicleForm />, { wrapper: wrapper(editQueryClient) })

    await waitFor(() => {
      expect(editQueryClient.getQueryData(['vehicle', 'vehicle-1', 'tenant-A', 'company-1'])).toBeDefined()
      expect(editQueryClient.getQueryData(['partner', 'partner-1', 'tenant-A', 'company-1'])).toBeDefined()
    })
    edit.unmount()

    act(() => { resetTenant() })
    const calls = mockApiGet.mock.calls.length
    render(<VehicleForm />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('uses the searchable customer PartnerPicker without an unfiltered partners request', async () => {
    render(<VehicleForm />, { wrapper: wrapper(createClient()) })

    await userEvent.click(screen.getByRole('combobox', { name: 'Owner' }))

    await waitFor(() => {
      expect(mockApiGet.mock.calls.some(([url]) => String(url).startsWith('/partners?'))).toBe(true)
    })
    const listUrls = mockApiGet.mock.calls
      .map(([url]) => String(url))
      .filter((url) => url === '/partners' || url.startsWith('/partners?'))
    expect(listUrls).not.toContain('/partners')
    expect(listUrls).not.toHaveLength(0)
    for (const url of listUrls) {
      expect(new URL(url, 'http://autoerp.test').searchParams.get('type')).toBe('customer')
    }
  })

  it('submits the selected customer partner_id unchanged', async () => {
    render(<VehicleForm />, { wrapper: wrapper(createClient()) })

    await userEvent.click(screen.getByRole('combobox', { name: 'Owner' }))
    await userEvent.click(await screen.findByRole('option', { name: /Partner A/ }))
    await userEvent.type(screen.getByLabelText(/License Plate/), '123-TUN-456')
    await userEvent.type(screen.getByLabelText(/Brand/), 'Renault')
    await userEvent.type(screen.getByLabelText(/Model/), 'Clio')
    await userEvent.click(screen.getByRole('button', { name: 'actions.save' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/vehicles', expect.objectContaining({
        partner_id: 'partner-1',
      }))
    })
  })

  it('invalidates create vehicles list for only active tenant (.758)', async () => {
    const queryClient = createClient()
    let vehiclesCalls = 0
    queryClient.setQueryData(['vehicles', 'tenant-B', 'company-1'], { marker: 'tenant-B-vehicles' })

    render(
      <>
        <Probe queryKey={['vehicles', 'list']} queryFn={async () => [`vehicles-${++vehiclesCalls}`]} />
        <VehicleForm />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(vehiclesCalls).toBe(1)
    })

    await userEvent.type(screen.getByLabelText(/License Plate/), '123-TUN-456')
    await userEvent.type(screen.getByLabelText(/Brand/), 'Renault')
    await userEvent.type(screen.getByLabelText(/Model/), 'Clio')
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'actions.save' }))
    })

    await waitFor(() => {
      expect(vehiclesCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['vehicles', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-vehicles' })
  })

  it('invalidates update vehicles list and exact detail without touching tenant-B (.759-.760)', async () => {
    mockRouteId.current = 'vehicle-1'
    const queryClient = createClient()
    let vehiclesCalls = 0
    queryClient.setQueryData(['vehicles', 'tenant-B', 'company-1'], { marker: 'tenant-B-vehicles' })
    queryClient.setQueryData(['vehicle', 'vehicle-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-vehicle' })

    render(
      <>
        <Probe queryKey={['vehicles', 'list']} queryFn={async () => [`vehicles-${++vehiclesCalls}`]} />
        <VehicleForm />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/vehicles/vehicle-1')).toHaveLength(1)
      expect(vehiclesCalls).toBe(1)
    })

    await act(async () => {
      await userEvent.click(await screen.findByRole('button', { name: 'actions.save' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/vehicles/vehicle-1')).toHaveLength(2)
      expect(vehiclesCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['vehicles', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-vehicles' })
    expect(queryClient.getQueryData(['vehicle', 'vehicle-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-vehicle' })
  })
})
