import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { PaymentDetailPage } from './PaymentDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())

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
    Link: ({ children, to }: { children: ReactNode; to: string }) => <a href={to}>{children}</a>,
    useNavigate: () => mockNavigate,
    useParams: () => ({ id: 'payment-1' }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, sub?: unknown) => (typeof sub === 'string' ? sub : key),
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

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function paymentFixture() {
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
    status: 'completed',
    payment_type: 'document_payment',
    allocated_amount: '0.00',
    unallocated_amount: '150.00',
    reference: 'PAY-1',
    notes: null,
    allocations: [],
    created_at: '2026-05-11T10:00:00Z',
  }
}

function createClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/payments/payment-1/can-refund') {
      return { data: { data: { can_refund: true, status: 'completed', amount: '150.00' } } }
    }
    if (url === '/payments/payment-1/refund-history') {
      return { data: { data: [] } }
    }
    if (url === '/payments/payment-1') {
      return { data: { data: paymentFixture() } }
    }
    return { data: { data: [] } }
  })
  mockApiPost.mockResolvedValue({ data: { data: {} } })
  mockApiDelete.mockResolvedValue({ data: { data: {} } })
})

afterEach(() => {
  resetTenant()
})

describe('PaymentDetailPage presentation', () => {
  it('renders exactly one h1 via the shared PageHeader', async () => {
    render(<PaymentDetailPage />, { wrapper: wrapper(createClient()) })

    await waitFor(() => {
      expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('payments.title')
    })
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders the payment status through a StatusBadge (rounded-full pill)', async () => {
    render(<PaymentDetailPage />, { wrapper: wrapper(createClient()) })

    const badge = await screen.findByText('payments.statuses.completed')
    expect(badge.tagName).toBe('SPAN')
    expect(badge.className).toContain('rounded-full')
  })

  it('links supplier-payment allocations to supplier invoice detail pages', async () => {
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/payments/payment-1/can-refund') {
        return { data: { data: { can_refund: true, status: 'completed', amount: '150.00' } } }
      }
      if (url === '/payments/payment-1/refund-history') {
        return { data: { data: [] } }
      }
      if (url === '/payments/payment-1') {
        return {
          data: {
            data: {
              ...paymentFixture(),
              payment_type: 'supplier_payment',
              partner: { id: 'partner-1', name: 'Supplier A', type: 'supplier' },
              allocations: [
                {
                  id: 'allocation-1',
                  document_id: 'supplier-invoice-1',
                  document_number: 'SIN-001',
                  amount: '150.00',
                },
              ],
            },
          },
        }
      }
      return { data: { data: [] } }
    })

    render(<PaymentDetailPage />, { wrapper: wrapper(createClient()) })

    const allocationLink = await screen.findByRole('link', { name: 'SIN-001' })
    expect(allocationLink).toHaveAttribute('href', '/purchases/supplier-invoices/supplier-invoice-1')
  })
})
