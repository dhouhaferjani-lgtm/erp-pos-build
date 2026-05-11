import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { PaymentForm } from '../PaymentForm'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const mockSearchParams = vi.hoisted(() => new URLSearchParams())
const mockTranslate = vi.hoisted(() => vi.fn((key: string, options?: { defaultValue?: string }) => options?.defaultValue ?? key))
const mockWithholdingPreviewMutate = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPost: mockApiPost,
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
    useNavigate: () => mockNavigate,
    useSearchParams: () => [mockSearchParams] as const,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('@/components/organisms', () => ({
  AddPartnerModal: () => null,
  AddRepositoryModal: () => null,
}))

vi.mock('../components', () => ({
  PaymentAllocationForm: () => null,
}))

vi.mock('@/features/withholding', () => ({
  useWithholdingPreview: () => ({ data: undefined, mutate: mockWithholdingPreviewMutate }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'TND',
    decimals: 2,
    format: (value: number) => value.toFixed(2),
    symbol: 'TND',
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

function mockListResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/payment-methods') return { data: { data: [{ id: 'method-1', name: 'Cash', is_physical: false }] } }
    if (url === '/partners') return { data: { data: [{ id: 'partner-1', name: 'Partner A' }] } }
    if (url === '/payment-repositories') return { data: { data: [{ id: 'repo-1', code: 'CASH', name: 'Cash Register', type: 'cash_register' }] } }
    if (url === '/partners/partner-1/open-invoices') return { data: { data: [] } }
    if (url.startsWith('/invoices/')) {
      return {
        data: {
          data: {
            id: 'invoice-1',
            document_number: 'INV-1',
            partner_id: 'partner-1',
            partner_name: 'Partner A',
            total: '100.00',
            subtotal: '100.00',
            tax_amount: '0.00',
            amount_residual: 100,
          },
        },
      }
    }
    return { data: { data: [] } }
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockSearchParams.delete('invoice')
  mockSearchParams.delete('purchase_order')
  mockSearchParams.delete('delivery_note')
  mockListResponses()
  mockApiPost.mockResolvedValue({ id: 'payment-1', payment_number: 'PAY-1', amount: 100 })
})

afterEach(() => {
  resetTenant()
  mockSearchParams.delete('invoice')
  mockSearchParams.delete('purchase_order')
  mockSearchParams.delete('delivery_note')
})

describe('PaymentForm tenant scope', () => {
  it('wraps form lookup keys and gates missing tenant/company (.674-.680)', async () => {
    const queryClient = createClient()
    render(<PaymentForm />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['payment-methods', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['partners', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment-repositories', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('scopes invoice and open-invoice reads (.671, .680)', async () => {
    mockSearchParams.set('invoice', 'invoice-1')
    const queryClient = createClient()
    const { unmount } = render(<PaymentForm />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['invoice', 'invoice-1', 'tenant-A', 'company-1'])).toBeDefined()
    })

    unmount()
    mockSearchParams.delete('invoice')
    const openInvoiceClient = createClient()
    render(<PaymentForm />, { wrapper: wrapper(openInvoiceClient) })
    await waitFor(() => {
      expect(screen.getByRole('option', { name: 'Partner A' })).toBeInTheDocument()
    })
    const partnerSelect = screen.getByLabelText('treasury:payments.partner *')
    await userEvent.selectOptions(partnerSelect, 'partner-1')

    await waitFor(() => {
      expect(partnerSelect).toHaveValue('partner-1')
      expect(mockApiGet).toHaveBeenCalledWith('/partners/partner-1/open-invoices')
      expect(openInvoiceClient.getQueryData(['open-invoices', 'partner-1', 'tenant-A', 'company-1'])).toBeDefined()
    })
  })

  it('invalidates active-tenant payment cascades and leaves tenant-B cache untouched (.681-.686)', async () => {
    let paymentCalls = 0
    let invoiceCalls = 0
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/payment-methods') return { data: { data: [{ id: 'method-1', name: 'Cash', is_physical: false }] } }
      if (url === '/partners') return { data: { data: [{ id: 'partner-1', name: 'Partner A' }] } }
      if (url === '/payment-repositories') return { data: { data: [{ id: 'repo-1', code: 'CASH', name: 'Cash Register', type: 'cash_register' }] } }
      if (url === '/partners/partner-1/open-invoices') return { data: { data: [] } }
      if (url === '/payments') return { data: { data: [`payments-${++paymentCalls}`] } }
      if (url === '/invoices') return { data: { data: [`invoices-${++invoiceCalls}`] } }
      return { data: { data: [] } }
    })

    const queryClient = createClient()
    queryClient.setQueryData(['payments', 'tenant-B', 'company-1'], { marker: 'tenant-B-payments' })
    queryClient.setQueryData(['invoices', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoices' })
    queryClient.setQueryData(['open-invoices', 'partner-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-open' })
    queryClient.setQueryDefaults(['payments', 'tenant-A', 'company-1'], { queryFn: async () => mockApiGet('/payments') })
    queryClient.setQueryDefaults(['invoices', 'tenant-A', 'company-1'], { queryFn: async () => mockApiGet('/invoices') })

    function Probe() {
      useQuery({ queryKey: ['payments', 'tenant-A', 'company-1'], queryFn: async () => mockApiGet('/payments') })
      useQuery({ queryKey: ['invoices', 'tenant-A', 'company-1'], queryFn: async () => mockApiGet('/invoices') })
      return null
    }

    render(<Probe />, { wrapper: wrapper(queryClient) })
    await waitFor(() => {
      expect(paymentCalls).toBe(1)
      expect(invoiceCalls).toBe(1)
    })

    render(<PaymentForm />, { wrapper: wrapper(queryClient) })
    await waitFor(() => {
      expect(screen.getByRole('option', { name: 'Cash' })).toBeInTheDocument()
      expect(screen.getByRole('option', { name: 'Partner A' })).toBeInTheDocument()
      expect(screen.getByRole('option', { name: 'Cash Register (CASH)' })).toBeInTheDocument()
    })

    await userEvent.type(screen.getByLabelText('treasury:payments.form.amount *'), '25')
    await userEvent.selectOptions(screen.getByLabelText('treasury:payments.form.paymentMethod *'), 'method-1')
    await userEvent.selectOptions(screen.getByLabelText('treasury:payments.form.repository *'), 'repo-1')
    await userEvent.selectOptions(screen.getByLabelText('treasury:payments.partner *'), 'partner-1')

    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'common:save' }))
    })

    await waitFor(() => {
      expect(paymentCalls).toBe(2)
      expect(invoiceCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['payments', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payments' })
    expect(queryClient.getQueryData(['invoices', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoices' })
    expect(queryClient.getQueryData(['open-invoices', 'partner-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-open' })
  })
})
