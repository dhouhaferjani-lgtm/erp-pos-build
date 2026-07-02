import { screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import i18n from '@/lib/i18n'
import { renderWithProviders } from '@/test/renderWithProviders'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { PartnerListPage } from './PartnerListPage'
import { makePartnerListRow, makePartnersListResponse } from './__fixtures__/partner'

const mockApiInstance = vi.hoisted(() => ({
  get: vi.fn(),
}))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: mockApiInstance,
  }
})

vi.mock('./hooks/usePartnerBalanceRealtime', () => ({
  usePartnerBalanceRealtime: vi.fn(),
}))

function seedTenant() {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-A',
      roles: [],
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
        locale: 'ar_TN',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

describe('PartnerListPage Arabic customer localization', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    seedTenant()
    await i18n.changeLanguage('ar')
  })

  afterEach(async () => {
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
    await i18n.changeLanguage('en')
  })

  it('renders the customer list header, filters, table, and document title in Arabic', async () => {
    mockApiInstance.get.mockResolvedValue({
      data: makePartnersListResponse({
        data: [
          makePartnerListRow({
            id: 'customer-1',
            name: 'شركة الشمال',
            type: 'customer',
            receivable_balance: '172.000',
          }),
        ],
        meta: {
          total: 172,
          current_page: 1,
          per_page: 25,
          last_page: 7,
          from: 1,
          to: 25,
        },
        aggregates: { total_partners: 172, total_active: 171, total_receivable: '172.000', total_payable: '0.000' },
      }),
    })

    const { container } = renderWithProviders(<PartnerListPage partnerType="customer" />, { route: '/sales/customers' })

    expect(await screen.findByRole('heading', { name: 'العملاء' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /إضافة عميل/ })).toBeInTheDocument()
    expect(screen.getByLabelText('لديه رصيد مستحق')).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'فرز حسب الرصيد' })).toBeInTheDocument()

    expect(within(container).queryByText(/customer|customers/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/Has outstanding balance|BALANCE|Partners/i)).not.toBeInTheDocument()
    expect(screen.getByText('172 عميلًا المجموع')).toBeInTheDocument()

    await waitFor(() => {
      expect(document.title).toMatch(/^العملاء \| /)
      expect(document.title).not.toContain('Partners')
    })
  })
})
