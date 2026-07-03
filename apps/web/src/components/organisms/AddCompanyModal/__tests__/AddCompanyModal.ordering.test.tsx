import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useCompanyStore } from '@/stores/companyStore'

import { AddCompanyModal } from '../AddCompanyModal'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, s?: unknown) => (typeof s === 'string' ? s : key),
  }),
}))

vi.mock('@/features/company/api', () => ({
  createCompany: vi.fn(),
}))

vi.mock('@/features/company/CompanyProvider', () => ({
  useInvalidateCompanies: () => vi.fn(),
}))

function seedCompany(countryCode: string) {
  useCompanyStore.setState({
    currentCompanyId: 'company-1',
    companies: [
      {
        id: 'company-1',
        name: 'Current Co',
        legalName: 'Current Co SARL',
        taxId: null,
        countryCode,
        currency: 'TND',
        locale: 'fr',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

function renderModal(companyCountry: string) {
  seedCompany(companyCountry)
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <AddCompanyModal isOpen onClose={vi.fn()} />
    </QueryClientProvider>,
  )
}

function countryOptionValues(): string[] {
  const select = screen.getByLabelText(/fields\.country/i)
  return within(select)
    .getAllByRole('option')
    .map((option) => (option as HTMLOptionElement).value)
}

beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(() => {
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('AddCompanyModal country ordering', () => {
  it('orders the country list with the current company country first, then alphabetically', () => {
    renderModal('MA')
    // Morocco first, then Algeria, France, Italy, Tunisia, United Kingdom, United States
    expect(countryOptionValues()).toEqual(['MA', 'DZ', 'FR', 'IT', 'TN', 'GB', 'US'])
  })

  it('puts Tunisia first for a Tunisian company', () => {
    renderModal('TN')
    expect(countryOptionValues()).toEqual(['TN', 'DZ', 'FR', 'IT', 'MA', 'GB', 'US'])
  })
})
