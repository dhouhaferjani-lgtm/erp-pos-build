import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import i18n from '@/lib/i18n'
import type { Country } from '../settings/types/country'
import { makePartnerDetail } from './__fixtures__/partner'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiGetUnwrapped = vi.hoisted(() => vi.fn())
const mockGetCountries = vi.hoisted(() => vi.fn())

vi.mock('../../lib/api', () => ({
  api: {
    get: mockApiGet,
  },
  apiPost: mockApiPost,
  apiPatch: mockApiPatch,
  apiGet: mockApiGetUnwrapped,
  getErrorMessage: (error: unknown): string => (error instanceof Error ? error.message : 'Unexpected error'),
  isApiError: () => false,
}))

vi.mock('../settings/api/country', () => ({
  getCountries: mockGetCountries,
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

import { PartnerForm } from './PartnerForm'

/**
 * Full Partner shape matching the interface PartnerForm expects from
 * GET /partners/:id — used only by the edit-mode test.
 */
function makeExistingPartner() {
  return makePartnerDetail({
    id: 'partner-1',
    name: 'Existing Partner',
    type: 'supplier' as const,
    customer_category: null,
    company_legal_name: null,
    business_registration_number: null,
    payment_terms: null,
    payment_terms_days: null,
    credit_limit: null,
    discount_percentage: null,
    invoice_consolidation: false,
    email: null,
    phone: '+21699999999',
    street_address: 'Existing Street',
    city: 'Sfax',
    state: null,
    postal_code: null,
    country: 'TN',
    country_code: 'TN',
    vat_number: 'EXISTING-VAT',
    tax_status: 'REGISTERED' as const,
    exemption_reason: null,
    exemption_valid_until: null,
    notes: null,
  })
}

/**
 * Minimal valid Country DTO for populating the getCountries() mock, used by
 * the country_code dual-set test to give the country <select> elements a
 * matching <option> to select.
 */
function makeCountry(code: string, name: string): Country {
  return {
    code,
    name,
    native_name: null,
    currency_code: 'EUR',
    currency_symbol: null,
    phone_prefix: null,
    date_format: 'DD/MM/YYYY',
    default_locale: null,
    default_timezone: null,
    is_active: true,
    tax_id_label: null,
    tax_id_regex: null,
    created_at: '2026-01-01T00:00:00.000Z',
  }
}

function renderPartnerForm(
  initialEntries: (string | { pathname: string; state?: unknown })[],
  path: string,
  partnerType: 'customer' | 'supplier' = 'supplier',
): QueryClient {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={initialEntries}>
        <Routes>
          <Route path={path} element={<PartnerForm partnerType={partnerType} />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return queryClient
}

function typeOptionValues(): string[] {
  const typeSelect = screen.getByRole('combobox', { name: /^Type/ })
  if (!(typeSelect instanceof HTMLSelectElement)) {
    throw new Error('Expected the Type field to be a select element')
  }

  return Array.from(typeSelect.options, (option) => option.value)
}

describe('PartnerForm — scan-to-document prefill (Task 2)', () => {
  beforeEach(async () => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiPatch.mockReset()
    mockApiGetUnwrapped.mockReset()
    mockGetCountries.mockReset()
    mockGetCountries.mockResolvedValue([])
    window.localStorage.setItem('autoerp-language', 'en')
    await i18n.changeLanguage('en')
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Admin User',
        email: 'admin@example.test',
        tenant_id: 'tenant-1',
        roles: ['admin'],
        email_verified_at: '2026-01-01T00:00:00.000Z',
      },
      token: 'token',
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
  })

  it('refuses customer creation without Nature and does not send the create request', async () => {
    mockApiPost.mockResolvedValue({ id: 'partner-new' })
    renderPartnerForm(['/sales/customers/new'], '/sales/customers/new', 'customer')
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Walk-in Customer' } })

    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(await screen.findByText('Nature is required')).toBeInTheDocument()
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('applies the same Nature-required rule after switching to company B', async () => {
    useCompanyStore.setState({ currentCompanyId: 'company-2', companies: [], isLoading: false })
    mockApiPost.mockResolvedValue({ id: 'partner-company-b' })
    renderPartnerForm(['/sales/customers/new'], '/sales/customers/new', 'customer')
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Company B Customer' } })

    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(await screen.findByText('Nature is required')).toBeInTheDocument()
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('defaults supplier creation Nature to Company', () => {
    renderPartnerForm(['/purchases/suppliers/new'], '/purchases/suppliers/new', 'supplier')

    expect(screen.getByLabelText(/^Nature/)).toHaveValue('business')
  })

  it('offers only Customer and Both Type choices on customer create', () => {
    renderPartnerForm(['/sales/customers/new'], '/sales/customers/new', 'customer')

    expect(typeOptionValues()).toEqual(['', 'customer', 'both'])
  })

  it('offers only Supplier and Both Type choices on supplier create', () => {
    renderPartnerForm(['/purchases/suppliers/new'], '/purchases/suppliers/new', 'supplier')

    expect(typeOptionValues()).toEqual(['', 'supplier', 'both'])
  })

  it('keeps Customer, Supplier, and Both Type choices on edit', async () => {
    mockApiGet.mockResolvedValue({ data: { data: makeExistingPartner() } })
    renderPartnerForm(
      ['/sales/customers/partner-1/edit'],
      '/sales/customers/:id/edit',
      'customer',
    )

    expect(await screen.findByLabelText(/^Name/)).toHaveValue('Existing Partner')
    expect(typeOptionValues()).toEqual(['', 'customer', 'supplier', 'both'])
  })

  it('does not default Nature to Company on a supplier edit whose detail request fails', async () => {
    mockApiGet.mockRejectedValue(new Error('partner unavailable'))

    renderPartnerForm(
      ['/purchases/suppliers/legacy-supplier/edit'],
      '/purchases/suppliers/:id/edit',
      'supplier',
    )

    expect(await screen.findByLabelText(/^Nature/)).toHaveValue('')
  })

  it.each([
    ['en', 'Select nature'],
    ['fr', 'Sélectionner la nature'],
    ['ar', 'اختر طبيعة الشريك'],
  ])('uses the Nature-specific blank option in %s', async (language, placeholder) => {
    await i18n.changeLanguage(language)

    renderPartnerForm(['/sales/customers/new'], '/sales/customers/new', 'customer')

    expect(screen.getByRole('option', { name: placeholder })).toBeInTheDocument()
  })

  it('uses an unambiguous Arabic validation message for Nature', async () => {
    await i18n.changeLanguage('ar')
    renderPartnerForm(['/sales/customers/new'], '/sales/customers/new', 'customer')

    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    expect(await screen.findByText('طبيعة الشريك مطلوبة')).toBeInTheDocument()
  })

  it('shows B2B fields for a legacy null Nature with a VAT number', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: makePartnerDetail({
          id: 'legacy-company',
          customer_category: null,
          vat_number: '1234567ABC000',
        }),
      },
    })

    renderPartnerForm(
      ['/sales/customers/legacy-company/edit'],
      '/sales/customers/:id/edit',
      'customer',
    )

    expect(await screen.findByText('B2B Information')).toBeInTheDocument()
  })

  it('keeps legacy B2B fields visible when the UI blank option represents null Nature', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: makePartnerDetail({
          id: 'legacy-company',
          customer_category: null,
          vat_number: '1234567ABC000',
        }),
      },
    })

    renderPartnerForm(
      ['/sales/customers/legacy-company/edit'],
      '/sales/customers/:id/edit',
      'customer',
    )

    expect(await screen.findByText('B2B Information')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^Nature/), { target: { value: '' } })
    expect(screen.getByText('B2B Information')).toBeInTheDocument()
  })

  it('hides B2B fields for a legacy null Nature with no B2B data', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: makePartnerDetail({
          id: 'legacy-walk-in',
          name: 'Legacy Walk-in',
          customer_category: null,
          vat_number: null,
          company_legal_name: null,
          business_registration_number: null,
          credit_limit: null,
        }),
      },
    })

    renderPartnerForm(
      ['/sales/customers/legacy-walk-in/edit'],
      '/sales/customers/:id/edit',
      'customer',
    )

    expect(await screen.findByLabelText(/^Name/)).toHaveValue('Legacy Walk-in')
    expect(screen.queryByText('B2B Information')).not.toBeInTheDocument()
  })

  it('renders the credit-limit warning when the partner is over the limit', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: makePartnerDetail({
          id: 'over-limit-company',
          customer_category: 'business',
          credit_limit: '100.000',
          receivable_balance: '125.000',
          net_balance: '125.000',
        }),
      },
    })

    renderPartnerForm(
      ['/sales/customers/over-limit-company/edit'],
      '/sales/customers/:id/edit',
      'customer',
    )

    expect(await screen.findByRole('alert')).toHaveTextContent('Credit limit exceeded')
  })

  it('uses net customer exposure instead of gross receivables for the credit warning', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: makePartnerDetail({
          id: 'customer-in-credit',
          customer_category: 'business',
          credit_limit: '100.000',
          receivable_balance: '125.000',
          credit_balance: '30.000',
          net_balance: '95.000',
        }),
      },
    })

    renderPartnerForm(
      ['/sales/customers/customer-in-credit/edit'],
      '/sales/customers/:id/edit',
      'customer',
    )

    expect(await screen.findByLabelText(/^Name/)).toHaveValue('Acme Corp')
    expect(screen.getByRole('alert')).toHaveTextContent('Approaching credit limit')
    expect(screen.getByRole('alert')).not.toHaveTextContent('Credit limit exceeded')
  })

  it('does not subtract supplier payables from a both-role partner credit exposure', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: makePartnerDetail({
          id: 'both-role-over-limit',
          type: 'both',
          customer_category: 'business',
          credit_limit: '1000.000',
          receivable_balance: '5000.000',
          credit_balance: '0.000',
          payable_balance: '4900.000',
          net_balance: '100.000',
        }),
      },
    })

    renderPartnerForm(
      ['/sales/customers/both-role-over-limit/edit'],
      '/sales/customers/:id/edit',
      'customer',
    )

    expect(await screen.findByLabelText(/^Name/)).toHaveValue('Acme Corp')
    expect(screen.getByRole('alert')).toHaveTextContent('Credit limit exceeded')
    expect(screen.getByRole('alert')).toHaveTextContent('5 000,00 EUR')
  })

  it('create mode: prefills Name, VAT number, Phone, Street address, City from navigation state', () => {
    renderPartnerForm(
      [
        {
          pathname: '/purchases/suppliers/new',
          state: {
            partnerPrefill: {
              name: 'Cooper Labs',
              vat_number: 'TN123',
              phone: '+21671',
              street_address: 'Zone Ind',
              city: 'Tunis',
            },
          },
        },
      ],
      '/purchases/suppliers/new',
    )

    expect(screen.getByLabelText(/^Name/)).toHaveValue('Cooper Labs')
    expect(screen.getByLabelText(/matricule fiscal/i)).toHaveValue('TN123')
    expect(screen.getByLabelText(/^Phone$/i)).toHaveValue('+21671')
    expect(screen.getByLabelText(/street address/i)).toHaveValue('Zone Ind')
    expect(screen.getByLabelText(/^City$/i)).toHaveValue('Tunis')
  })

  it('create mode: leaves fields empty when there is no prefill navigation state (customer-flow regression guard)', () => {
    renderPartnerForm(['/purchases/suppliers/new'], '/purchases/suppliers/new')

    expect(screen.getByLabelText(/^Name/)).toHaveValue('')
    expect(screen.getByLabelText(/matricule fiscal/i)).toHaveValue('')
    expect(screen.getByLabelText(/^Phone$/i)).toHaveValue('')
    expect(screen.getByLabelText(/street address/i)).toHaveValue('')
    expect(screen.getByLabelText(/^City$/i)).toHaveValue('')
  })

  it('edit mode: ignores prefill navigation state and shows the loaded partner values instead', async () => {
    mockApiGet.mockResolvedValue({ data: { data: makeExistingPartner() } })

    renderPartnerForm(
      [
        {
          pathname: '/purchases/suppliers/partner-1/edit',
          state: {
            partnerPrefill: {
              name: 'Should Not Apply',
              vat_number: 'SHOULD-NOT-APPLY',
              phone: '+00000000',
              street_address: 'Should Not Apply Street',
              city: 'Should Not Apply City',
            },
          },
        },
      ],
      '/purchases/suppliers/:id/edit',
    )

    await waitFor(() => {
      expect(screen.getByLabelText(/^Name/)).toHaveValue('Existing Partner')
    })
    expect(screen.getByLabelText(/matricule fiscal/i)).toHaveValue('EXISTING-VAT')
    expect(screen.getByLabelText(/^Phone$/i)).toHaveValue('+21699999999')
    expect(screen.getByLabelText(/street address/i)).toHaveValue('Existing Street')
    expect(screen.getByLabelText(/^City$/i)).toHaveValue('Sfax')
  })

  it('create mode: country_code prefill dual-sets both the country and country_code form fields', async () => {
    mockGetCountries.mockResolvedValue([makeCountry('FR', 'France'), makeCountry('TN', 'Tunisia')])

    renderPartnerForm(
      [
        {
          pathname: '/purchases/suppliers/new',
          state: {
            partnerPrefill: {
              name: 'Cooper Labs',
              vat_number: 'TN123',
              country_code: 'FR',
            },
          },
        },
      ],
      '/purchases/suppliers/new',
    )

    // The countries query resolves asynchronously; once the <option value="FR">
    // exists, both country selects should reflect the prefilled 'FR' value.
    await waitFor(() => {
      expect(screen.getByLabelText(/^Country$/)).toHaveValue('FR')
    })
    expect(screen.getByLabelText(/Country \(VAT\)/)).toHaveValue('FR')
  })

  it('create mode: rejects a prefill country_code with no matching country option, keeping the default', async () => {
    mockGetCountries.mockResolvedValue([makeCountry('FR', 'France'), makeCountry('TN', 'Tunisia')])

    renderPartnerForm(
      [
        {
          pathname: '/purchases/suppliers/new',
          state: {
            partnerPrefill: {
              name: 'Cooper Labs',
              // Syntactically valid 2-letter code, but not a real/known country —
              // must NOT be applied (else it is held in form state and submitted,
              // then rejected by the backend at save time).
              country_code: 'XX',
            },
          },
        },
      ],
      '/purchases/suppliers/new',
    )

    // Wait for the country list to resolve (France option present) so validation
    // has had its data, then confirm both country selects kept the company default
    // 'TN' and never took the bogus 'XX'. The rest of the prefill still applied.
    await waitFor(() => {
      expect(screen.getByRole('option', { name: 'France' })).toBeInTheDocument()
    })
    expect(screen.getByLabelText(/^Country$/)).toHaveValue('TN')
    expect(screen.getByLabelText(/Country \(VAT\)/)).toHaveValue('TN')
    expect(screen.getByLabelText(/^Name/)).toHaveValue('Cooper Labs')
  })

  it('create mode: prefill never populates commercial fields even when bogus commercial keys are present in navigation state', () => {
    renderPartnerForm(
      [
        {
          pathname: '/purchases/suppliers/new',
          state: {
            partnerPrefill: {
              name: 'Cooper Labs',
              vat_number: 'TN123',
              // Not in PartnerPrefill's whitelist — must be structurally dropped.
              credit_limit: '9999',
              discount_percentage: '50',
              payment_terms: 'NET90',
            },
          },
        },
      ],
      '/purchases/suppliers/new',
    )

    expect(screen.getByLabelText(/^Name/)).toHaveValue('Cooper Labs')

    // Commercial fields live in B2BFieldsSection, only rendered once
    // customer_category is 'business' — switch to it to expose them and
    // confirm the prefill never reached them.
    fireEvent.change(screen.getByLabelText(/^Nature/), { target: { value: 'business' } })

    expect(screen.getByLabelText(/credit limit/i)).toHaveValue(null)
    expect(screen.getByLabelText(/discount percentage/i)).toHaveValue(null)
    expect(screen.getByLabelText(/^payment terms$/i)).toHaveValue('')
  })

  it('describes invoice consolidation as a periodic-billing classification', () => {
    renderPartnerForm(['/sales/customers/new'], '/sales/customers/new', 'customer')
    fireEvent.change(screen.getByLabelText(/^Nature/), { target: { value: 'business' } })

    expect(screen.getByRole('checkbox', { name: 'Billed periodically' })).toBeInTheDocument()
    expect(screen.getByText('This customer is billed periodically.')).toBeInTheDocument()
  })

  it('does not expose a consolidation-frequency selector when periodic billing is selected', () => {
    renderPartnerForm(['/sales/customers/new'], '/sales/customers/new', 'customer')
    fireEvent.change(screen.getByLabelText(/^Nature/), { target: { value: 'business' } })
    fireEvent.click(screen.getByRole('checkbox', { name: 'Billed periodically' }))

    expect(screen.queryByRole('combobox', { name: /consolidation frequency/i })).not.toBeInTheDocument()
  })

  it('adds a bank account on the edit page, derives its IBAN, and submits without blocking', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: {
          ...makeExistingPartner(),
          customer_category: 'business',
          bank_accounts: [],
        },
      },
    })
    mockApiPatch.mockResolvedValue({ id: 'partner-1' })
    mockApiGetUnwrapped.mockResolvedValue([{
      id: 'bank-amen',
      country_code: 'TN',
      name: 'Amen Bank',
      short_name: 'AB',
      bic: 'CFCTTNTT',
      rib_bank_code: '07',
      city: 'Tunis',
      is_custom: false,
    }])

    renderPartnerForm(
      ['/purchases/suppliers/partner-1/edit'],
      '/purchases/suppliers/:id/edit',
    )
    // Promoted partner-bank-accounts lane (Phase 3.0.0): wait for edit hydration before appending rows.
    await waitFor(() => {
      expect(screen.getByLabelText(/^Name/)).toHaveValue('Existing Partner')
    })
    fireEvent.click(screen.getByRole('button', { name: /add bank account/i }))

    const bankPicker = screen.getByRole('combobox', { name: /bank$/i })
    fireEvent.focus(bankPicker)
    fireEvent.change(bankPicker, { target: { value: 'Amen' } })
    await waitFor(() => {
      expect(screen.getByRole('option', { name: /Amen Bank CFCTTNTT/i })).toBeInTheDocument()
    })
    fireEvent.click(screen.getByRole('option', { name: /Amen Bank CFCTTNTT/i }))
    fireEvent.change(screen.getByLabelText(/^RIB$/i), { target: { value: '07040005810111129653' } })

    await waitFor(() => {
      expect(screen.getByLabelText(/^IBAN$/i)).toHaveValue('TN5907040005810111129653')
    })
    expect(screen.getByText(/valid RIB/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/primary bank account/i)).toBeChecked()

    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))
    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith('/partners/partner-1', expect.objectContaining({
        bank_accounts: [expect.objectContaining({
          bank_id: 'bank-amen',
          bank_name: 'Amen Bank',
          bic: 'CFCTTNTT',
          rib: '07040005810111129653',
          iban: 'TN5907040005810111129653',
          is_primary: true,
        })],
      }))
    })
  })

  it('clears the auto-derived IBAN when a valid RIB is edited back to invalid', async () => {
    mockApiPost.mockResolvedValue({ id: 'partner-new' })
    renderPartnerForm(['/purchases/suppliers/new'], '/purchases/suppliers/new')
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Legacy Supplier' } })
    fireEvent.change(screen.getByLabelText(/^Nature/), { target: { value: 'business' } })
    fireEvent.click(screen.getByRole('button', { name: /add bank account/i }))

    fireEvent.change(screen.getByLabelText(/^RIB$/i), { target: { value: '07040005810111129653' } })
    await waitFor(() => {
      expect(screen.getByLabelText(/^IBAN$/i)).toHaveValue('TN5907040005810111129653')
    })

    // Break the RIB: the auto-derived IBAN must be cleared, not left stale.
    fireEvent.change(screen.getByLabelText(/^RIB$/i), { target: { value: '07040005810111129654' } })
    await waitFor(() => {
      expect(screen.getByLabelText(/^IBAN$/i)).toHaveValue('')
    })
  })

  it('warns on an invalid partner RIB while leaving Save enabled', async () => {
    mockApiPost.mockResolvedValue({ id: 'partner-new' })
    renderPartnerForm(['/purchases/suppliers/new'], '/purchases/suppliers/new')
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Legacy Supplier' } })
    fireEvent.change(screen.getByLabelText(/^Nature/), { target: { value: 'business' } })
    fireEvent.click(screen.getByRole('button', { name: /add bank account/i }))
    fireEvent.change(screen.getByLabelText(/^RIB$/i), { target: { value: '123' } })

    expect(screen.getByText(/could not be verified/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^save$/i })).toBeEnabled()
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))
    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/partners', expect.objectContaining({
        bank_accounts: [expect.objectContaining({ rib: '123' })],
      }))
    })
  })
})
