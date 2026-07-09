import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { TaxSettingsPage } from './TaxSettingsPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPut: mockApiPut,
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', () => ({
  Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, s?: unknown) => (typeof s === 'string' ? s : key),
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'company-1', name: 'Company A', currency: 'TND' } }),
}))

vi.mock('./hooks/useTaxConfigurations', () => ({
  useDeleteTaxConfiguration: () => ({ isPending: false, mutateAsync: vi.fn() }),
  useTaxConfigurations: () => ({
    data: [
      {
        id: 'tax-1',
        name: 'VAT',
        code: 'VAT-19',
        tax_type: 'PERCENTAGE',
        percentage_rate: '19.000',
        fixed_amount: null,
        applies_to: 'LINE_ITEMS',
        is_active: true,
      },
    ],
    isLoading: false,
  }),
}))

vi.mock('@/components/organisms', () => ({ TaxConfigFormModal: () => null }))
vi.mock('@/components/ui/ConfirmDialog', () => ({ ConfirmDialog: () => null }))

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
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant()
  mockApiGet.mockResolvedValue({ data: { data: taxCompanySettings() } })
  mockApiPut.mockResolvedValue(taxCompanySettings())
})

afterEach(() => {
  resetTenant()
})

describe('TaxSettingsPage (canonical primitives)', () => {
  it('renders exactly one h1 via PageHeader', async () => {
    render(<TaxSettingsPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'common:save' })).toBeInTheDocument()
    })
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
      'settings:tax.configurations.pageTitle',
    )
  })

  it('renders the fiscal-year select and tax-status radios via atoms', async () => {
    render(<TaxSettingsPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getByLabelText('settings:tax.fiscalYear.label').tagName).toBe('SELECT')
    })
    expect(
      screen.getByRole('radio', { name: /tax\.configurations\.profile\.registered/ }),
    ).toBeInTheDocument()
  })

  it('renders the save action as a real button element', async () => {
    render(<TaxSettingsPage />, { wrapper: wrapper() })
    const save = await screen.findByRole('button', { name: 'common:save' })
    expect(save.tagName).toBe('BUTTON')
  })

  it('trims percentage tax rates for display', async () => {
    render(<TaxSettingsPage />, { wrapper: wrapper() })

    fireEvent.click(await screen.findByRole('tab', { name: 'settings:tax.configurations.tabs.taxes' }))

    await waitFor(() => {
      expect(screen.getByText('19%')).toBeInTheDocument()
    })

    expect(screen.queryByText('19.000%')).not.toBeInTheDocument()
  })
})
