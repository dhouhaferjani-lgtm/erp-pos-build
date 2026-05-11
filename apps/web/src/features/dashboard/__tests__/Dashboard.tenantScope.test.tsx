import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, waitFor } from '@testing-library/react'
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
      roles: [],
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
    if (url.startsWith('/documents')) return { data: { data: [] } }
    if (url.startsWith('/payments')) return { data: { data: [] } }
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
})
