import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Country } from '@/features/settings/types/country'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { CompanyPage } from '../CompanyPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockUseCountry = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      patch: mockApiPatch,
      post: vi.fn(),
      delete: vi.fn(),
    },
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
  Trans: ({ i18nKey }: { i18nKey: string }) => <span>{i18nKey}</span>,
}))

vi.mock('../components/ReceiptSettingsTab', () => ({ ReceiptSettingsTab: () => null }))

vi.mock('../hooks/useCountries', () => ({
  useCountry: mockUseCountry,
}))

function tnCountry(): Country {
  return {
    code: 'TN',
    name: 'Tunisia',
    native_name: 'تونس',
    currency_code: 'TND',
    currency_symbol: 'DT',
    phone_prefix: '216',
    date_format: 'DD/MM/YYYY',
    default_locale: 'fr',
    default_timezone: 'Africa/Tunis',
    is_active: true,
    tax_id_label: 'Matricule Fiscal',
    tax_id_regex: null,
    created_at: '2026-01-01T00:00:00Z',
  }
}

function companySettings(countryCode: string) {
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
    logo_url: null,
    primary_color: '#2563EB',
    country_code: countryCode,
    currency_code: 'TND',
    timezone: 'Africa/Tunis',
    date_format: 'DD/MM/YYYY',
    locale: 'en',
  }
}

function wrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function renderCompanyPage({
  countryCode,
  countryRow,
}: {
  countryCode: string
  countryRow: Country | null
}) {
  mockApiGet.mockResolvedValue({ data: { data: companySettings(countryCode) } })
  mockUseCountry.mockReturnValue({ data: countryRow ?? undefined, isLoading: false })
  return render(<CompanyPage />, { wrapper: wrapper() })
}

beforeEach(() => {
  vi.clearAllMocks()
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
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('CompanyPage country profile', () => {
  it('labels the tax field from the country profile (TN → Matricule Fiscal) and uses TN placeholders', async () => {
    renderCompanyPage({ countryCode: 'TN', countryRow: tnCountry() })

    await waitFor(() => {
      expect(screen.getByLabelText(/matricule fiscal/i)).toBeInTheDocument()
    })
    expect(screen.getByPlaceholderText('1000')).toBeInTheDocument() // postal
    expect(screen.getByPlaceholderText('+216 71 123 456')).toBeInTheDocument()
    expect(screen.getByPlaceholderText('1234567AM000')).toBeInTheDocument()
  })

  it('falls back to the generic tax label when the country row is missing', async () => {
    renderCompanyPage({ countryCode: 'DE', countryRow: null })

    await waitFor(() => {
      expect(screen.getByLabelText('settings:company.fields.taxId')).toBeInTheDocument()
    })
    expect(screen.queryByPlaceholderText('75001')).not.toBeInTheDocument() // no French leakage
    expect(screen.queryByPlaceholderText('Paris')).not.toBeInTheDocument()
    expect(screen.queryByPlaceholderText('+33 1 23 45 67 89')).not.toBeInTheDocument()
  })
})
