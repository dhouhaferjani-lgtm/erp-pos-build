import { Route, Routes } from 'react-router-dom'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { defaultCompanyConfig, mechanicCompanyConfig } from '@/test/fixtures/companyConfig'
import { renderWithProviders } from '@/test/renderWithProviders'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { PartnerDetailPage } from './PartnerDetailPage'

const mockApi = vi.hoisted(() => ({
  get: vi.fn(),
  delete: vi.fn(),
}))
const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: mockApi, apiGet: mockApiGet }
})

vi.mock('./hooks/usePartnerBalanceRealtime', () => ({
  usePartnerBalanceRealtime: vi.fn(),
}))

vi.mock('@/features/documents/delivery-notes/PartnerDeliveryNotesTab', () => ({
  PartnerDeliveryNotesTab: ({ canCreateInvoice }: { canCreateInvoice: boolean }) => (
    <div data-testid="delivery-note-panel">
      {canCreateInvoice ? <button type="button">Create invoice from selected (0)</button> : null}
    </div>
  ),
  PartnerUnbilledBalanceLine: () => (
    <a href="?tab=delivery-notes">Delivered, not yet invoiced</a>
  ),
}))

const partner = {
  id: 'partner-1',
  name: 'Acme Corp',
  type: 'customer' as const,
  customer_category: 'business' as const,
  company_legal_name: 'Acme Corporation',
  business_registration_number: '123456',
  payment_terms: 'net_30',
  payment_terms_days: null,
  credit_limit: '1000.000',
  discount_percentage: '0.000',
  invoice_consolidation: false,
  consolidation_frequency: null,
  email: 'contact@example.com',
  phone: null,
  street_address: null,
  street_address_2: null,
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
  receivable_balance: '0.000',
  payable_balance: '0.000',
  credit_balance: '0.000',
  created_at: '2026-08-01T10:00:00Z',
  updated_at: '2026-08-01T10:00:00Z',
}

function seedUser({
  roles = [],
  permissions = [],
}: {
  roles?: string[]
  permissions?: string[]
}) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-A',
      roles,
      permissions,
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: 'company-1',
    companies: [{
      id: 'company-1',
      name: 'Test Company',
      legalName: 'Test Company',
      taxId: null,
      countryCode: 'TN',
      currency: 'TND',
      locale: 'en_US',
      timezone: 'Africa/Tunis',
    }],
    isLoading: false,
  })
}

function renderPartner(path: string, companyConfig = mechanicCompanyConfig) {
  return renderWithProviders(
    <Routes>
      <Route path="/sales/customers/:id" element={<PartnerDetailPage />} />
      <Route path="/purchases/suppliers/:id" element={<PartnerDetailPage />} />
    </Routes>,
    { route: path, companyConfig },
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  mockApiGet.mockResolvedValue([])
  mockApi.get.mockImplementation((url: string) => {
    if (url === '/partners/partner-1') return Promise.resolve({ data: { data: partner } })
    if (url.includes('/account-balance/')) {
      return Promise.resolve({
        data: { data: { partner_id: 'partner-1', currency: 'TND', unallocated_balance: '0.000', deposit_count: 0 } },
      })
    }
    return Promise.resolve({
      data: {
        data: [],
        meta: { current_page: 1, last_page: 1, total: 0, per_page: 10, from: null, to: null },
      },
    })
  })
})

describe('partner delivery-note tab gates', () => {
  it('hides both tab entry and action from an admin when the Sales module is disabled', async () => {
    seedUser({ roles: ['admin'] })
    renderPartner('/sales/customers/partner-1', defaultCompanyConfig)

    await screen.findByRole('heading', { name: 'Acme Corp' })
    expect(screen.queryByRole('tab', { name: /Delivery notes/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Create invoice from selected/ })).not.toBeInTheDocument()
    expect(screen.queryByText('Delivered, not yet invoiced')).not.toBeInTheDocument()
  })

  it('hides the tab independently when deliveries.view is unavailable', async () => {
    seedUser({ permissions: [] })
    renderPartner('/sales/customers/partner-1')

    await screen.findByRole('heading', { name: 'Acme Corp' })
    expect(screen.queryByRole('tab', { name: /Delivery notes/ })).not.toBeInTheDocument()
  })

  it('shows the list but not its action with deliveries.view only', async () => {
    const user = userEvent.setup()
    seedUser({ permissions: ['deliveries.view'] })
    renderPartner('/sales/customers/partner-1')

    await user.click(await screen.findByRole('tab', { name: /Delivery notes/ }))
    expect(screen.getByTestId('delivery-note-panel')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Create invoice from selected/ })).not.toBeInTheDocument()
  })

  it('shows the customer tab, balance line, and action when both gates pass', async () => {
    const user = userEvent.setup()
    seedUser({ roles: ['admin'] })
    renderPartner('/sales/customers/partner-1')

    expect(await screen.findByText('Delivered, not yet invoiced')).toBeInTheDocument()
    await user.click(screen.getByRole('tab', { name: /Delivery notes/ }))
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Create invoice from selected/ })).toBeInTheDocument()
    })
  })

  it('never renders the tab in supplier context', async () => {
    seedUser({ roles: ['admin'] })
    renderPartner('/purchases/suppliers/partner-1')

    await screen.findByRole('heading', { name: 'Acme Corp' })
    expect(screen.queryByRole('tab', { name: /Delivery notes/ })).not.toBeInTheDocument()
  })
})
