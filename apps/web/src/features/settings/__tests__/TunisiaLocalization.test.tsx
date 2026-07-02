import { screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { AddCompanyModal } from '@/components/organisms/AddCompanyModal/AddCompanyModal'
import { PartnerForm } from '@/features/partners/PartnerForm'
import { renderWithProviders } from '@/test/renderWithProviders'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const mockGetCountries = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApi = vi.hoisted(() => ({
  get: vi.fn(),
}))

vi.mock('@/features/settings/api/country', () => ({
  getCountries: mockGetCountries,
}))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: mockApi,
    apiPost: mockApiPost,
    apiPatch: mockApiPatch,
  }
})

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

function seedTunisiaCompany() {
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
        name: 'PharmaBio Tunisie',
        legalName: 'PharmaBio Tunisie',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'fr_TN',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

describe('Tunisia localization defaults', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedTunisiaCompany()
    mockGetCountries.mockResolvedValue([
      { code: 'TN', name: 'Tunisia', is_active: true },
      { code: 'FR', name: 'France', is_active: true },
    ])
  })

  it('defaults the add-company form to Tunisia regional settings', () => {
    renderWithProviders(<AddCompanyModal isOpen onClose={vi.fn()} />)

    expect(screen.getByLabelText(/Country/)).toHaveValue('TN')
    expect(screen.getByText('Currency: TND | Timezone: Africa/Tunis')).toBeInTheDocument()
    expect(screen.getByPlaceholderText('+216 71 123 456')).toBeInTheDocument()
    expect(screen.getByPlaceholderText('1000')).toBeInTheDocument()
  })

  it('defaults new partner address and tax country to Tunisia labels', async () => {
    renderWithProviders(<PartnerForm partnerType="customer" />, {
      route: '/sales/customers/new',
    })

    await waitFor(() => {
      expect(screen.getByLabelText('Country')).toHaveValue('TN')
    })
    expect(screen.getByLabelText('Country (VAT)')).toHaveValue('TN')
    expect(screen.getByLabelText('Gouvernorat')).toBeInTheDocument()
  })
})
