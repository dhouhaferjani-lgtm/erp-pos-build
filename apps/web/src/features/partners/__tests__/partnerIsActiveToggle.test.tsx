import { Route, Routes } from 'react-router-dom'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { renderWithProviders } from '@/test/renderWithProviders'

import { PartnerForm } from '../PartnerForm'

/**
 * BUG-007 follow-up (FE gate M1) — the blocked-delete toast tells the operator
 * to "deactivate it instead", but the partner UI had no `is_active` affordance
 * anywhere: `PartnerForm` never referenced the field and `PartnerDetailPage`
 * rendered the status badge read-only. The instruction was a dead end in three
 * languages, delivered at the exact moment the operator was already blocked.
 *
 * The backend has always accepted it — `CreatePartnerRequest.php:97` and
 * `UpdatePartnerRequest.php:103` both carry `['sometimes','boolean']`, the
 * model casts it and defaults it to true.
 */

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiInstance = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: mockApiInstance,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    apiPatch: mockApiPatch,
  }
})

vi.mock('../../settings/api/country', () => ({
  getCountries: vi.fn().mockResolvedValue([]),
}))

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const inactivePartner = {
  id: 'partner-1',
  name: 'Acme Corp',
  type: 'customer' as const,
  customer_category: 'individual' as const,
  company_legal_name: null,
  business_registration_number: null,
  payment_terms: null,
  payment_terms_days: null,
  credit_limit: null,
  discount_percentage: null,
  invoice_consolidation: false,
  consolidation_frequency: null,
  email: null,
  phone: null,
  street_address: null,
  city: null,
  state: null,
  postal_code: null,
  country: null,
  country_code: null,
  vat_number: null,
  tax_status: 'REGISTERED' as const,
  exemption_reason: null,
  exemption_certificate_path: null,
  exemption_valid_until: null,
  notes: null,
  is_active: false,
}

function seedTenant(): void {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-A',
      roles: ['admin'],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: 'company-1',
    companies: [
      {
        id: 'company-1',
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

describe('PartnerForm is_active toggle (BUG-007 / FE gate M1)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedTenant()
    mockApiPost.mockResolvedValue({ id: 'partner-new', name: 'New Partner' })
    mockApiPatch.mockResolvedValue({ ...inactivePartner, is_active: true })
    mockApiInstance.get.mockResolvedValue({ data: { data: inactivePartner } })
  })

  afterEach(() => {
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  })

  it('creates a partner as active by default', async () => {
    const user = userEvent.setup()

    renderWithProviders(<PartnerForm />)

    const toggle = screen.getByLabelText(/active/i)
    expect(toggle).toBeChecked()

    await user.type(screen.getByLabelText(/^name\s*\*?$/i), 'New Partner')
    await user.selectOptions(screen.getByLabelText(/^type/i), 'customer')
    await user.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/partners', expect.objectContaining({
        is_active: true,
      }))
    })
  })

  it('reflects a deactivated partner and round-trips reactivation through PATCH', async () => {
    const user = userEvent.setup()

    renderWithProviders(
      <Routes>
        <Route path="/sales/customers/:id/edit" element={<PartnerForm partnerType="customer" />} />
      </Routes>,
      { route: '/sales/customers/partner-1/edit' },
    )

    await waitFor(() => {
      expect(screen.getByLabelText(/^name\s*\*?$/i)).toHaveValue('Acme Corp')
    })

    // The saved state must be reflected, not silently defaulted back to active.
    const toggle = screen.getByLabelText(/active/i)
    expect(toggle).not.toBeChecked()

    await user.click(toggle)
    expect(toggle).toBeChecked()

    await user.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith('/partners/partner-1', expect.objectContaining({
        is_active: true,
      }))
    })
  })

  it('round-trips deactivation (the action the blocked-delete message tells the operator to take)', async () => {
    const user = userEvent.setup()
    mockApiInstance.get.mockResolvedValue({
      data: { data: { ...inactivePartner, is_active: true } },
    })

    renderWithProviders(
      <Routes>
        <Route path="/sales/customers/:id/edit" element={<PartnerForm partnerType="customer" />} />
      </Routes>,
      { route: '/sales/customers/partner-1/edit' },
    )

    await waitFor(() => {
      expect(screen.getByLabelText(/^name\s*\*?$/i)).toHaveValue('Acme Corp')
    })

    const toggle = screen.getByLabelText(/active/i)
    expect(toggle).toBeChecked()

    await user.click(toggle)
    await user.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith('/partners/partner-1', expect.objectContaining({
        is_active: false,
      }))
    })
  })
})
