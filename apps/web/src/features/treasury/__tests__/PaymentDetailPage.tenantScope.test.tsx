import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { PaymentDetailPage } from '../PaymentDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const mockRouteId = vi.hoisted(() => ({ current: 'payment-1' }))
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet, post: mockApiPost },
    apiDelete: mockApiDelete,
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
    useNavigate: () => mockNavigate,
    useParams: () => ({ id: mockRouteId.current }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

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
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
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
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function paymentFixture(status: 'pending' | 'completed' | 'failed' | 'reversed' = 'completed') {
  return {
    id: 'payment-1',
    partner_id: 'partner-1',
    partner: { id: 'partner-1', name: 'Partner A', type: 'customer' },
    payment_method_id: 'method-1',
    payment_method: { id: 'method-1', code: 'CASH', name: 'Cash' },
    instrument_id: null,
    repository_id: 'repo-1',
    amount: '150.00',
    currency: 'TND',
    payment_date: '2026-05-11',
    status,
    payment_type: 'document_payment',
    allocated_amount: '0.00',
    unallocated_amount: '150.00',
    reference: 'PAY-1',
    notes: null,
    allocations: [],
    created_at: '2026-05-11T10:00:00Z',
  }
}

function mockDetailResponses(status: 'pending' | 'completed' | 'failed' | 'reversed' = 'completed') {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/payments/payment-1/can-refund') {
      return { data: { data: { can_refund: true, status, amount: '150.00' } } }
    }
    if (url === '/payments/payment-1/refund-history') {
      return { data: { data: [] } }
    }
    if (url === '/payments/payment-1') {
      return { data: { data: paymentFixture(status) } }
    }
    return { data: { data: [] } }
  })
}

function PaymentsProbe() {
  useQuery({ queryKey: tenantScopedKey(['payments']), queryFn: async () => mockApiGet('/payments') })
  return null
}

beforeEach(() => {
  vi.clearAllMocks()
  mockRouteId.current = 'payment-1'
  setTenant('tenant-A', 'company-1')
  mockDetailResponses()
  mockApiPost.mockResolvedValue({ data: { data: {} } })
  mockApiDelete.mockResolvedValue({ data: { data: {} } })
})

afterEach(() => {
  resetTenant()
})

describe('PaymentDetailPage tenant scope', () => {
  it('wraps detail read keys and gates missing tenant/company (.677-.679)', async () => {
    const queryClient = createClient()
    render(<PaymentDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['payment', 'payment-1', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment', 'payment-1', 'can-refund', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment', 'payment-1', 'refund-history', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<PaymentDetailPage />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('invalidates delete against only the active-tenant payments cache (.680)', async () => {
    let paymentsCalls = 0
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/payments/payment-1') return { data: { data: paymentFixture('pending') } }
      if (url === '/payments') return { data: { data: [`payments-${++paymentsCalls}`] } }
      return { data: { data: [] } }
    })

    const queryClient = createClient()
    queryClient.setQueryData(['payments', 'tenant-B', 'company-1'], { marker: 'tenant-B-payments' })
    render(
      <>
        <PaymentsProbe />
        <PaymentDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(paymentsCalls).toBe(1)
      expect(screen.getByRole('button', { name: 'payments.messages.cancelPayment' })).toBeInTheDocument()
    })

    await userEvent.click(screen.getByRole('button', { name: 'payments.messages.cancelPayment' }))
    const confirmButtons = screen.getAllByRole('button', { name: 'payments.messages.cancelPayment' })
    await act(async () => {
      await userEvent.click(confirmButtons[confirmButtons.length - 1])
    })

    await waitFor(() => {
      expect(paymentsCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['payments', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payments' })
  })

  it('invalidates full refund detail cascades and leaves tenant-B detail cache untouched (.681-.683)', async () => {
    const queryClient = createClient()
    queryClient.setQueryData(['payment', 'payment-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-payment' })
    queryClient.setQueryData(['payment', 'payment-1', 'can-refund', 'tenant-B', 'company-1'], { marker: 'tenant-B-can-refund' })
    queryClient.setQueryData(['payment', 'payment-1', 'refund-history', 'tenant-B', 'company-1'], { marker: 'tenant-B-refunds' })

    render(<PaymentDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1')).toHaveLength(1)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1/can-refund')).toHaveLength(1)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1/refund-history')).toHaveLength(1)
    })

    await userEvent.click(screen.getByRole('button', { name: 'payments.refund.refund' }))
    await userEvent.type(screen.getByLabelText('payments.refund.reason'), 'Customer requested refund')
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'common:actions.confirm' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1')).toHaveLength(2)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1/can-refund')).toHaveLength(2)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1/refund-history')).toHaveLength(2)
    })
    expect(queryClient.getQueryData(['payment', 'payment-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payment' })
    expect(queryClient.getQueryData(['payment', 'payment-1', 'can-refund', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-can-refund' })
    expect(queryClient.getQueryData(['payment', 'payment-1', 'refund-history', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-refunds' })
  })

  it('invalidates partial refund detail cascades with per-call counters (.684-.686)', async () => {
    const queryClient = createClient()
    render(<PaymentDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1')).toHaveLength(1)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1/can-refund')).toHaveLength(1)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1/refund-history')).toHaveLength(1)
    })

    await userEvent.click(screen.getByRole('button', { name: 'payments.refund.partialRefund' }))
    await userEvent.type(screen.getByLabelText('payments.amount'), '25')
    await userEvent.type(screen.getByLabelText('payments.refund.reason'), 'Partial return')
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'common:actions.confirm' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1')).toHaveLength(2)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1/can-refund')).toHaveLength(2)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1/refund-history')).toHaveLength(2)
    })
  })

  it('invalidates reverse detail plus active-tenant payment list and leaves tenant-B list untouched (.687-.688)', async () => {
    let paymentsCalls = 0
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/payments/payment-1/can-refund') return { data: { data: { can_refund: true, status: 'completed', amount: '150.00' } } }
      if (url === '/payments/payment-1/refund-history') return { data: { data: [] } }
      if (url === '/payments/payment-1') return { data: { data: paymentFixture('completed') } }
      if (url === '/payments') return { data: { data: [`payments-${++paymentsCalls}`] } }
      return { data: { data: [] } }
    })

    const queryClient = createClient()
    queryClient.setQueryData(['payments', 'tenant-B', 'company-1'], { marker: 'tenant-B-payments' })
    render(
      <>
        <PaymentsProbe />
        <PaymentDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(paymentsCalls).toBe(1)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1')).toHaveLength(1)
      expect(screen.getByRole('button', { name: 'payments.refund.reverse' })).toBeInTheDocument()
    })

    await userEvent.click(screen.getByRole('button', { name: 'payments.refund.reverse' }))
    await userEvent.type(screen.getByLabelText('payments.refund.reason'), 'Reversal reason')
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'common:actions.confirm' }))
    })

    await waitFor(() => {
      expect(paymentsCalls).toBe(2)
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payments/payment-1')).toHaveLength(2)
    })
    expect(queryClient.getQueryData(['payments', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payments' })
  })
})
