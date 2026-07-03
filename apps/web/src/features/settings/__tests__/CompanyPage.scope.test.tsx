import { screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import i18n from '@/lib/i18n'
import { renderWithProviders } from '@/test/renderWithProviders'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { CompanyPage } from '../CompanyPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      patch: mockApiPatch,
      post: mockApiPost,
      delete: mockApiDelete,
    },
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('../components/ReceiptSettingsTab', () => ({ ReceiptSettingsTab: () => null }))

function companySettings() {
  return {
    name: 'PharmaBio Tunis',
    legal_name: null,
    slug: 'pharmabio-tunis',
    tax_id: null,
    registration_number: null,
    address: { street: null, city: null, postal_code: null, country: null },
    phone: null,
    email: null,
    website: null,
    logo_url: null,
    primary_color: '#2563EB',
    country_code: 'TN',
    currency_code: 'TND',
    timezone: 'Africa/Tunis',
    date_format: 'DD/MM/YYYY',
    locale: 'en',
  }
}

beforeEach(async () => {
  vi.clearAllMocks()
  await i18n.changeLanguage('en')
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
  useCompanyStore.setState({
    currentCompanyId: 'company-1',
    companies: [
      {
        id: 'company-1',
        name: 'PharmaBio Tunis',
        legalName: 'PharmaBio Tunis SARL',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'fr',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
  mockApiGet.mockResolvedValue({ data: { data: companySettings() } })
  mockApiPatch.mockResolvedValue({ data: {} })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('CompanyPage scope banner', () => {
  it('shows a company-scope banner naming the current company and linking to locations', async () => {
    renderWithProviders(<CompanyPage />)

    const banner = await screen.findByText(/company-wide settings/i)
    expect(banner).toHaveTextContent('PharmaBio Tunis')
    expect(screen.getByRole('link', { name: /locations & branches/i })).toHaveAttribute(
      'href',
      '/settings/locations',
    )
  })
})
