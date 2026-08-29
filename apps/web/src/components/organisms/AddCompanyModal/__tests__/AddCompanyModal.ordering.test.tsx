import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useCompanyStore } from '@/stores/companyStore'

import { AddCompanyModal } from '../AddCompanyModal'

const mockCreateCompany = vi.hoisted(() => vi.fn())
const mockInvalidateCompanies = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, s?: unknown) => (typeof s === 'string' ? s : key),
  }),
}))

vi.mock('@/features/company/api', () => ({
  createCompany: mockCreateCompany,
}))

vi.mock('@/features/company/CompanyProvider', () => ({
  useInvalidateCompanies: () => mockInvalidateCompanies,
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
  localStorage.clear()
})

afterEach(() => {
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  localStorage.clear()
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

  it('switches to and persists the company returned by create', async () => {
    const user = userEvent.setup()
    let companyIdWhenInvalidated: string | null = null
    mockInvalidateCompanies.mockImplementation(() => {
      companyIdWhenInvalidated = useCompanyStore.getState().currentCompanyId
      return Promise.resolve()
    })
    mockCreateCompany.mockResolvedValue({
      id: 'company-created-id',
      name: 'Created Company',
      legalName: 'Created Company SARL',
      taxId: null,
      countryCode: 'TN',
      currency: 'TND',
      locale: 'fr_TN',
      timezone: 'Africa/Tunis',
    })
    renderModal('TN')

    await user.type(screen.getByLabelText(/fields\.name/i), 'Created Company')
    await user.click(screen.getByRole('button', { name: 'settings:company.modal.createButton' }))

    await waitFor(() => {
      expect(useCompanyStore.getState().currentCompanyId).toBe('company-created-id')
    })
    expect(localStorage.getItem('autoerp-company-selection')).toBe('company-created-id')
    expect(mockInvalidateCompanies).toHaveBeenCalledOnce()
    expect(companyIdWhenInvalidated).toBe('company-created-id')
  })
})
