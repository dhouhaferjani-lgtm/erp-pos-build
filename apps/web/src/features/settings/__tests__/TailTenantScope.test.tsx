import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useVatPeriods } from '@/features/vat-reporting/hooks/useVatPeriods'
import { VehicleListPage } from '@/features/vehicles/VehicleListPage'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { ReceiptSettingsTab } from '../components/ReceiptSettingsTab'
import { SetupChecklist } from '../components/SetupChecklist'
import { UserEditModal } from '../components/UserEditModal'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())
const mockFetchOnboardingStatus = vi.hoisted(() => vi.fn())
const mockUpdateUser = vi.hoisted(() => vi.fn())
const mockGetVatPeriods = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGet,
      put: mockApiPut,
    },
  }
})

vi.mock('../api/onboardingApi', () => ({
  fetchOnboardingStatus: mockFetchOnboardingStatus,
}))

vi.mock('@/features/users/api/users', () => ({
  updateUser: mockUpdateUser,
}))

vi.mock('@/features/vat-reporting/api', () => ({
  getVatPeriods: mockGetVatPeriods,
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: unknown) => typeof fallback === 'string' ? fallback : key,
  }),
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [
      {
        id: companyId,
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

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
      </MemoryRouter>
    )
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockImplementation((url: string) => {
    if (url === '/companies/company-1/pos-settings') {
      return Promise.resolve({
        data: {
          data: {
            receipt_header: null,
            receipt_footer: null,
            receipt_thank_you: null,
            receipt_show_vat_breakdown: true,
            receipt_show_fiscal_info: true,
            receipt_show_payment_details: true,
            receipt_show_customer: true,
            auto_print_receipts: false,
            receipt_logo: null,
          },
        },
      })
    }
    if (url.startsWith('/vehicles')) return Promise.resolve({ data: { data: [], meta: { total: 0 } } })
    return Promise.resolve({ data: { data: [] } })
  })
  mockApiPut.mockResolvedValue({})
  mockFetchOnboardingStatus.mockResolvedValue([])
  mockUpdateUser.mockResolvedValue({})
  mockGetVatPeriods.mockResolvedValue({ data: [] })
})

afterEach(() => {
  resetTenant()
})

describe('tail tenant scope', () => {
  it('scopes final settings, vehicle, and VAT reads/invalidations (.617-.623)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')

    render(
      <>
        <ReceiptSettingsTab />
        <SetupChecklist />
        <VehicleListPage />
        <UserEditModal
          user={{
            id: 'user-2',
            name: 'Cashier',
            email: 'cashier@example.com',
            phone: null,
            status: 'active',
            roles: ['operator'],
            canDiscount: false,
            maxDiscountPercent: null,
            lastLoginAt: null,
            createdAt: '2026-05-11T00:00:00Z',
          }}
          roles={[{ name: 'operator', permissions: [] }]}
          onClose={vi.fn()}
          onSuccess={vi.fn()}
          onError={vi.fn()}
        />
      </>,
      { wrapper: wrapper(queryClient) },
    )
    renderHook(() => useVatPeriods({ year: 2026 }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['receipt-settings', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['onboarding-status', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['vehicles', '', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['vat-periods', { year: 2026 }, 'tenant-A', 'company-1'])).toBeDefined()
    })

    await user.click(screen.getByRole('button', { name: 'settings:userEdit.save' }))

    await waitFor(() => {
      expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['users', 'tenant-A', 'company-1'] })
    })
    expect(invalidateSpy).not.toHaveBeenCalledWith({ queryKey: ['users', 'tenant-B', 'company-2'] })
  })

  it('does not fetch final reads without tenant/company state', () => {
    const queryClient = createClient()
    resetTenant()

    render(<VehicleListPage />, { wrapper: wrapper(queryClient) })

    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
