import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { Country } from '@/features/settings/types/country'

import { AddLocationModal } from '../AddLocationModal'

const createLocationMock = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}))

vi.mock('@/features/location/api', async () => {
  const actual = await vi.importActual<typeof import('@/features/location/api')>(
    '@/features/location/api',
  )
  return {
    ...actual,
    createLocation: createLocationMock,
  }
})

vi.mock('@/features/location/LocationProvider', () => ({
  useInvalidateLocations: () => vi.fn(),
}))

const mockCompanyCountry = vi.hoisted(() => ({ value: 'TN' }))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({
    currentCompany: {
      id: 'company-1',
      name: 'PharmaBio Tunis',
      legalName: 'PharmaBio Tunis SARL',
      taxId: null,
      countryCode: mockCompanyCountry.value,
      currency: 'TND',
      locale: 'fr',
      timezone: 'Africa/Tunis',
    },
    currentCompanyId: 'company-1',
    companies: [],
    isLoading: false,
    hasMultipleCompanies: false,
    switchCompany: vi.fn(),
  }),
}))

function countryFixture(overrides: Partial<Country>): Country {
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
    ...overrides,
  }
}

function countryList(): Country[] {
  return [
    countryFixture({ code: 'TN', name: 'Tunisia' }),
    countryFixture({ code: 'FR', name: 'France', tax_id_label: 'SIREN', phone_prefix: '33' }),
    countryFixture({ code: 'DE', name: 'Germany', tax_id_label: null, phone_prefix: '49' }),
  ]
}

vi.mock('@/features/settings/hooks/useCountries', () => ({
  useCountries: () => ({
    data: countryList(),
    isLoading: false,
  }),
  useCountry: (code: string) => ({
    data: countryList().find((country) => country.code === code),
    isLoading: false,
  }),
}))

function renderModal({ companyCountry = 'TN' }: { companyCountry?: string } = {}) {
  mockCompanyCountry.value = companyCountry
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <AddLocationModal isOpen onClose={vi.fn()} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  mockCompanyCountry.value = 'TN'
})

describe('AddLocationModal country + branch tax fields', () => {
  it('defaults the country to the current company country', () => {
    renderModal({ companyCountry: 'TN' })
    expect(screen.getByLabelText(/form\.country/i)).toHaveValue('TN')
  })

  it('requires a tax ID for a shop in a branch-tax country and blocks submit', async () => {
    const user = userEvent.setup()
    renderModal({ companyCountry: 'TN' })

    await user.type(screen.getByLabelText(/form\.name/i), 'Branch Sfax')
    await user.click(screen.getByRole('button', { name: /modal\.createButton/i }))

    expect(await screen.findByText(/modal\.taxIdRequired/i)).toBeInTheDocument()
    expect(createLocationMock).not.toHaveBeenCalled()
  })

  it('does not show tax fields for a warehouse', async () => {
    const user = userEvent.setup()
    renderModal({ companyCountry: 'TN' })

    await user.selectOptions(screen.getByLabelText(/form\.type/i), 'warehouse')

    expect(screen.queryByLabelText(/form\.taxId/i)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/matricule fiscal/i)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/form\.vatNumber/i)).not.toBeInTheDocument()
  })

  it('labels the tax field from the selected country profile and flips on country change', async () => {
    const user = userEvent.setup()
    renderModal({ companyCountry: 'TN' })

    expect(screen.getByLabelText(/matricule fiscal/i)).toBeInTheDocument()
    expect(screen.getByPlaceholderText('1234567AM000')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText(/form\.country/i), 'FR')

    expect(screen.getByLabelText(/SIREN/i)).toBeInTheDocument()
    expect(screen.getByPlaceholderText('FR12345678901')).toBeInTheDocument()
  })

  it('sends country and tax fields in the create payload', async () => {
    const user = userEvent.setup()
    createLocationMock.mockResolvedValue({
      id: 'location-9',
      company_id: 'company-1',
      name: 'Branch Sfax',
      code: 'SFX',
      type: 'shop',
      phone: null,
      email: null,
      address_street: null,
      address_city: null,
      address_postal_code: null,
      address_country: 'TN',
      tax_id: '1234567AM000',
      vat_number: null,
      legal_identifiers: null,
      is_default: false,
      is_active: true,
      pos_enabled: false,
      created_at: '2026-07-03T00:00:00Z',
      updated_at: '2026-07-03T00:00:00Z',
    })
    renderModal({ companyCountry: 'TN' })

    await user.type(screen.getByLabelText(/form\.name/i), 'Branch Sfax')
    // Country is TN → the tax field is labeled from the country profile (Task 7)
    await user.type(screen.getByLabelText(/matricule fiscal/i), '1234567AM000')
    await user.click(screen.getByRole('button', { name: /modal\.createButton/i }))

    expect(createLocationMock).toHaveBeenCalledWith(
      expect.objectContaining({
        addressCountry: 'TN',
        taxId: '1234567AM000',
      }),
    )
  })
})
