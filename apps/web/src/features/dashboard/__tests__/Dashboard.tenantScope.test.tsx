import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { Dashboard } from '../Dashboard'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockFetchOnboardingStatus = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
  }
})

vi.mock('@/features/settings/api/onboardingApi', () => ({
  fetchOnboardingStatus: mockFetchOnboardingStatus,
}))

vi.mock('@/hooks/usePageTitle', () => ({
  usePageTitle: vi.fn(),
}))

vi.mock('@/features/treasury/components/CashPositionWidget', () => ({
  CashPositionWidget: () => null,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: tenantId,
      // The onboarding-status query is now gated on hasPermission('settings.view')
      // (docs/superpowers/tickets/2026-08-02-settings-setup-route-ungated.md item 3,
      // mirrors the backend `can:settings.view` gate on GET onboarding/status).
      // 'admin' holds settings.view — needed so this test's assertion that the
      // onboarding-status query actually fires (line below) stays valid.
      roles: ['admin'],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [{
      id: companyId,
      name: 'Company A',
      legalName: 'Company A LLC',
      taxId: 'TN123',
      countryCode: 'TN',
      currency: 'TND',
      locale: 'en_US',
      timezone: 'Africa/Tunis',
    }],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
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

function dashboardStats() {
  return {
    revenue: { current: 100, previous: 90, change: 10 },
    invoices: { total: 2, pending: 1, overdue: 0 },
    partners: { total: 3, newThisMonth: 1 },
    payments: { received: 80, pending: 20 },
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockFetchOnboardingStatus.mockResolvedValue([])
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/dashboard/stats') return { data: { data: dashboardStats() } }
    if (url.startsWith('/documents')) {
      return {
        data: {
          data: [
            {
              id: 'dn-1',
              document_number: 'DN-001',
              type: 'delivery_note',
              partner_name: 'Customer A',
              total_amount: '42.00',
              status: 'posted',
              created_at: '2026-05-11T10:00:00Z',
            },
          ],
        },
      }
    }
    if (url.startsWith('/payments')) {
      return {
        data: {
          data: [
            {
              id: 'payment-1',
              payment_number: 'PAY-001',
              partner_name: 'Customer A',
              amount: '42.00',
              payment_method_name: 'Cash',
              created_at: '2026-05-11T10:00:00Z',
            },
          ],
        },
      }
    }
    return { data: { data: [] } }
  })
})

afterEach(() => {
  resetTenant()
})

describe('Dashboard tenant scope', () => {
  it('wraps dashboard read keys and gates missing tenant/company (.137-.140)', async () => {
    const queryClient = createClient()
    render(<Dashboard />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['onboarding-status', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['dashboard', 'stats', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['dashboard', 'documents', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['dashboard', 'payments', 'tenant-A', 'company-1'])).toBeDefined()
    })

    act(() => {
      resetTenant()
    })
    const apiCalls = mockApiGet.mock.calls.length
    const onboardingCalls = mockFetchOnboardingStatus.mock.calls.length
    render(<Dashboard />, { wrapper: wrapper(createClient()) })

    expect(mockApiGet).toHaveBeenCalledTimes(apiCalls)
    expect(mockFetchOnboardingStatus).toHaveBeenCalledTimes(onboardingCalls)
  })

  it('links recent document and payment rows to their detail pages', async () => {
    render(<Dashboard />, { wrapper: wrapper(createClient()) })

    expect(await screen.findByRole('link', { name: /DN-001/ })).toHaveAttribute('href', '/inventory/delivery-notes/dn-1')
    expect(screen.getByRole('link', { name: /PAY-001/ })).toHaveAttribute('href', '/treasury/payments/payment-1')
  })
})
