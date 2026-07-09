import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockGetCountries = vi.hoisted(() => vi.fn())

vi.mock('../../lib/api', () => ({
  api: {
    get: mockApiGet,
  },
  apiPost: mockApiPost,
  apiPatch: mockApiPatch,
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
  return {
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
    consolidation_frequency: null,
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
    exemption_certificate_path: null,
    exemption_valid_until: null,
    notes: null,
  }
}

function renderPartnerForm(
  initialEntries: (string | { pathname: string; state?: unknown })[],
  path: string,
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
          <Route path={path} element={<PartnerForm partnerType="supplier" />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return queryClient
}

describe('PartnerForm — scan-to-document prefill (Task 2)', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiPatch.mockReset()
    mockGetCountries.mockReset()
    mockGetCountries.mockResolvedValue([])
    window.localStorage.setItem('autoerp-language', 'en')
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
})
