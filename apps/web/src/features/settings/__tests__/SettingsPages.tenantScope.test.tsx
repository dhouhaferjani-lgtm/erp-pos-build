import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { CompanyPage } from '../CompanyPage'
import { TaxSettingsPage } from '../TaxSettingsPage'
import { InventorySettings } from '../components/InventorySettings'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())
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
      put: mockApiPut,
    },
    apiPut: mockApiPut,
    getErrorMessage: () => 'request failed',
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

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'company-1', name: 'Company A', currency: 'TND' } }),
}))

vi.mock('../components/ReceiptSettingsTab', () => ({ ReceiptSettingsTab: () => null }))

vi.mock('../hooks/useTaxConfigurations', () => ({
  useDeleteTaxConfiguration: () => ({ isPending: false, mutateAsync: vi.fn() }),
  useTaxConfigurations: () => ({ data: [], isLoading: false }),
}))

vi.mock('@/components/organisms', () => ({
  TaxConfigFormModal: () => null,
}))

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: () => null,
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

function companySettings() {
  return {
    name: 'Company A',
    legal_name: null,
    slug: 'company-a',
    tax_id: null,
    registration_number: null,
    address: { street: null, city: null, postal_code: null, country: null },
    phone: null,
    email: null,
    website: null,
    logo_url: '/logo.png',
    primary_color: '#2563EB',
    country_code: 'TN',
    currency_code: 'TND',
    timezone: 'Africa/Tunis',
    date_format: 'DD/MM/YYYY',
    locale: 'en',
  }
}

function companyInventorySettings() {
  return {
    id: 'company-1',
    name: 'Company A',
    default_target_margin: '30.00',
    default_minimum_margin: '15.00',
    allow_below_cost_sales: false,
  }
}

function reservationSettings() {
  return {
    sales_order_expiry_days: 30,
    ecommerce_cart_expiry_minutes: 30,
    marketplace_order_expiry_hours: 24,
    customer_return_expiry_days: 14,
    high_value_alert_threshold: '10000.00',
    inventory_count_trigger_threshold: '5000.00',
    auto_reserve_on_sales_order: true,
  }
}

function taxCompanySettings() {
  return {
    id: 'company-1',
    name: 'Company A',
    default_tax_rate: null,
    fiscal_year_start_month: 1,
    tax_status: 'NON_REGISTERED',
    vat_registration_number: null,
  }
}

function mockSettingsResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/settings/company') return { data: { data: companySettings() } }
    if (url === '/company') return { data: { data: companyInventorySettings() } }
    if (url === '/companies/company-1/reservation-settings') return { data: { data: reservationSettings() } }
    if (url === '/companies/company-1') return { data: { data: taxCompanySettings() } }
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
  vi.stubGlobal('confirm', vi.fn(() => true))
  setTenant('tenant-A', 'company-1')
  mockSettingsResponses()
  mockApiPatch.mockResolvedValue({ data: {} })
  mockApiPost.mockResolvedValue({ data: {} })
  mockApiDelete.mockResolvedValue({ data: {} })
  mockApiPut.mockResolvedValue({ data: taxCompanySettings() })
})

afterEach(() => {
  vi.unstubAllGlobals()
  resetTenant()
})

describe('settings pages tenant scope', () => {
  it('wraps CompanyPage settings key and invalidates exact settings operations (.625-.628)', async () => {
    const queryClient = createClient()
    queryClient.setQueryData(['company-settings', 'tenant-B', 'company-1'], { marker: 'tenant-B-company-settings' })

    render(<CompanyPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/settings/company')).toHaveLength(1)
      expect(queryClient.getQueryData(['company-settings', 'tenant-A', 'company-1'])).toBeDefined()
    })

    await userEvent.clear(screen.getByLabelText('settings:company.fields.name'))
    await userEvent.type(screen.getByLabelText('settings:company.fields.name'), 'Company B')
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'common:actions.save' }))
    })
    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/settings/company')).toHaveLength(2)
    })

    const fileInput = document.querySelector<HTMLInputElement>('input[type="file"]')
    expect(fileInput).not.toBeNull()
    await userEvent.upload(fileInput!, new File(['logo'], 'logo.png', { type: 'image/png' }))
    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/settings/company')).toHaveLength(3)
    })

    await userEvent.click(screen.getByRole('button', { name: 'common:actions.delete' }))
    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/settings/company')).toHaveLength(4)
    })
    expect(queryClient.getQueryData(['company-settings', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-company-settings' })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<CompanyPage />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('wraps InventorySettings keys and invalidates exact settings operations (.648-.651)', async () => {
    const queryClient = createClient()
    queryClient.setQueryData(['company-settings', 'tenant-B', 'company-1'], { marker: 'tenant-B-company-settings' })
    queryClient.setQueryData(['reservation-settings', 'company-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-reservations' })

    render(<InventorySettings />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/company')).toHaveLength(1)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/companies/company-1/reservation-settings')).toHaveLength(1)
    })

    const numberInputs = await screen.findAllByRole('spinbutton')
    await userEvent.clear(numberInputs[0])
    await userEvent.type(numberInputs[0], '35')
    await userEvent.clear(numberInputs[2])
    await userEvent.type(numberInputs[2], '45')

    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'common:save common:settings.title' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/company')).toHaveLength(2)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/companies/company-1/reservation-settings')).toHaveLength(2)
    })
    expect(queryClient.getQueryData(['company-settings', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-company-settings' })
    expect(queryClient.getQueryData(['reservation-settings', 'company-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-reservations' })
  })

  it('wraps TaxSettingsPage company key and invalidates active company namespaces (.639-.641)', async () => {
    const queryClient = createClient()
    let companiesCalls = 0
    queryClient.setQueryData(['company', 'tax-settings', 'company-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-company-tax' })
    queryClient.setQueryData(['companies', 'tenant-B', 'company-1'], { marker: 'tenant-B-companies' })

    render(
      <>
        <Probe queryKey={['companies']} queryFn={async () => [`companies-${++companiesCalls}`]} />
        <TaxSettingsPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/companies/company-1')).toHaveLength(1)
      expect(companiesCalls).toBe(1)
    })

    await userEvent.click(screen.getByRole('radio', { name: /tax\.configurations\.profile\.registered/ }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'common:save' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/companies/company-1')).toHaveLength(2)
      expect(companiesCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['company', 'tax-settings', 'company-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-company-tax' })
    expect(queryClient.getQueryData(['companies', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-companies' })
  })
})
