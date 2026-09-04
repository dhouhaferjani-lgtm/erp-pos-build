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
  // T12 deviation D2: SplitPaymentForm now feeds the formatter decimal STRINGS,
  // so this shared mock must accept the real formatter input without coercion.
  useCurrency: () => ({ format: (value: string | number) => String(value) }),
  getDecimals: () => 2,
  getLocale: () => 'en-US',
}))

// Promoted repository bank-validation lane (Phase 2.0.0): modal tests supply company config.
vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({ config: { country_code: 'TN' } }),
  useCompanyConfigOptional: () => ({ config: { country_code: 'TN' } }),
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
  mockApiGet.mockImplementation((url: string) => Promise.resolve(url.includes('/treasury/maturing-instruments')
    ? {
        data: {
          data: [],
          meta: {
            from: '2026-07-11',
            to: '2026-10-09',
            buckets: {
              overdue: { count: 0, total_in: '0.000', total_out: '0.000' },
              d0_7: { count: 0, total_in: '0.000', total_out: '0.000' },
              d8_30: { count: 0, total_in: '0.000', total_out: '0.000' },
              d31_60: { count: 0, total_in: '0.000', total_out: '0.000' },
              d61_90: { count: 0, total_in: '0.000', total_out: '0.000' },
              d90_plus: { count: 0, total_in: '0.000', total_out: '0.000' },
            },
            grand_total: { count: 0, total_in: '0.000', total_out: '0.000' },
          },
        },
      }
    : { data: { data: [], meta: { total: 0 } } }))
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
        <SplitPaymentForm documentId="doc-1" totalAmount="100" onSuccess={vi.fn()} onCancel={vi.fn()} />
        <AddPaymentMethodModal isOpen={true} onClose={vi.fn()} />
      </>,
      { wrapper: wrapper(queryClient) },
    )
    renderHook(() => usePaymentMethods(), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueriesData({ queryKey: ['instruments'] }).some(([key, value]) => (
        key.at(-2) === 'tenant-A' && key.at(-1) === 'company-1' && value !== undefined
      ))).toBe(true)
      // PaymentListPage keys its query as
      // ['payments', search, page, perPage, tenant, company] since list
      // pagination became mandatory; the initial search term is '' and the
      // first page is 1 at the 25-row default.
      expect(queryClient.getQueryData(['payments', '', 1, 25, 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment-repositories', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment-methods', 'tenant-A', 'company-1'])).toBeDefined()
    })

    await user.type(screen.getByLabelText(/treasury:paymentMethods.code/), 'CASH')
    await user.type(screen.getByLabelText(/treasury:paymentMethods.name/), 'Cash')
    await user.click(screen.getByRole('button', { name: 'common:actions.save' }))

    // Invalidation filters are bare literal prefixes (2026-07 sweep):
    // tenantScopedKey appends tenant/company as suffixes, but React Query
    // matches filter keys as positional PREFIXES, so a suffixed filter only
    // ever matched the exact full key. The bare prefix reaches every
    // tenant's cached entry; data isolation lives in the query KEYS above.
    await waitFor(() => {
      expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['payment-methods'] })
    })
    // The inactive tenant-B entry has no observer, so it stays marked stale —
    // proving the bare prefix actually matched the tenant-suffixed keys.
    // (The active tenant-A query refetches immediately, resetting its
    // isInvalidated flag, so assert on the inactive entry.)
    expect(
      queryClient.getQueryState(['payment-methods', 'tenant-B', 'company-2'])?.isInvalidated,
    ).toBe(true)
  })

  it('does not fetch treasury reads without tenant/company state', () => {
    const queryClient = createClient()
    resetTenant()

    render(<SplitPaymentForm documentId="doc-1" totalAmount="100" onSuccess={vi.fn()} onCancel={vi.fn()} />, {
      wrapper: wrapper(queryClient),
    })

    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
