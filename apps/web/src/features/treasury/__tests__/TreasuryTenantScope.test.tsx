import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { InstrumentListPage } from '../InstrumentListPage'
import { PaymentListPage } from '../PaymentListPage'
import { RepositoryListPage } from '../RepositoryListPage'
import { SplitPaymentForm } from '../SplitPaymentForm'
import { AddPaymentMethodModal } from '../components/AddPaymentMethodModal'
import { usePaymentMethods } from '../hooks/usePaymentMethods'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGet,
      post: vi.fn(),
      patch: vi.fn(),
    },
    apiPost: mockApiPost,
  }
})

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: () => true }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ format: (value: number) => value.toFixed(2) }),
  getDecimals: () => 2,
  getLocale: () => 'en-US',
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
  mockApiGet.mockResolvedValue({ data: { data: [], meta: { total: 0 } } })
  mockApiPost.mockResolvedValue({
    data: {
      id: 'method-1',
      code: 'CASH',
      name: 'Cash',
      is_physical: true,
      has_maturity: false,
      requires_third_party: false,
      is_push: false,
      has_deducted_fees: false,
      is_restricted: false,
      fee_type: 'none',
    },
  })
})

afterEach(() => {
  resetTenant()
})

describe('treasury tenant scope', () => {
  it('scopes treasury reads and invalidations (.679-.688)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')
    queryClient.setQueryData(['payment-methods', 'tenant-A', 'company-1'], [])
    queryClient.setQueryData(['payment-methods', 'tenant-B', 'company-2'], [])

    render(
      <>
        <InstrumentListPage />
        <PaymentListPage />
        <RepositoryListPage />
        <SplitPaymentForm documentId="doc-1" totalAmount={100} onSuccess={vi.fn()} onCancel={vi.fn()} />
        <AddPaymentMethodModal isOpen={true} onClose={vi.fn()} />
      </>,
      { wrapper: wrapper(queryClient) },
    )
    renderHook(() => usePaymentMethods(), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['instruments', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payments', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment-repositories', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment-methods', 'tenant-A', 'company-1'])).toBeDefined()
    })

    await user.type(screen.getByLabelText(/treasury:paymentMethods.code/), 'CASH')
    await user.type(screen.getByLabelText(/treasury:paymentMethods.name/), 'Cash')
    await user.click(screen.getByRole('button', { name: 'common:actions.save' }))

    await waitFor(() => {
      expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['payment-methods', 'tenant-A', 'company-1'] })
    })
    expect(invalidateSpy).not.toHaveBeenCalledWith({ queryKey: ['payment-methods', 'tenant-B', 'company-2'] })
  })

  it('does not fetch treasury reads without tenant/company state', () => {
    const queryClient = createClient()
    resetTenant()

    render(<SplitPaymentForm documentId="doc-1" totalAmount={100} onSuccess={vi.fn()} onCancel={vi.fn()} />, {
      wrapper: wrapper(queryClient),
    })

    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
