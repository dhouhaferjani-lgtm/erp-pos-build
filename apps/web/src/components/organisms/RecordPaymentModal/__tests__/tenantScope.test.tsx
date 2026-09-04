import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { RecordPaymentModal } from '../RecordPaymentModal'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPost: mockApiPost,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'TND',
    decimals: 2,
    format: (amount: string | number) => String(amount),
    symbol: 'TND',
  }),
}))

vi.mock('../../AddRepositoryModal', () => ({
  AddRepositoryModal: ({ onSuccess }: { onSuccess: () => void | Promise<void> }) => (
    <button type="button" onClick={() => { void onSuccess(); }}>repository-success</button>
  ),
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

function prefill() {
  return {
    partner_id: 'partner-1',
    partner_name: 'Partner A',
    amount: 100,
    reference: 'INV-1',
    document_id: 'doc-1',
    document_type: 'invoice' as const,
  }
}

function mockLookupResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/payment-methods') {
      return { data: { data: [{ id: 'method-1', code: 'CASH', name: 'Cash', is_physical: false, has_maturity: false }] } }
    }
    if (url === '/payment-repositories') {
      return { data: { data: [{ id: 'repo-1', code: 'CASH', name: 'Cash Register', type: 'cash_register' }] } }
    }
    if (url === '/partners/partner-1/open-invoices') {
      return { data: { data: [{ id: 'other-doc', document_number: 'INV-2', balance_due: '50.00', due_date: '2026-05-20' }] } }
    }
    return { data: { data: [] } }
  })
}

function Probe({ queryKey, queryFn }: { queryKey: readonly unknown[]; queryFn: () => Promise<unknown> }) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  useQuery({ queryKey: tenantScopedKey(queryKey), queryFn, enabled: tenantId !== null && companyId !== null })
  return null
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockLookupResponses()
  mockApiPost.mockResolvedValue({
    payments: [{ id: 'payment-1', payment_number: 'PAY-1', amount: '100.00' }],
    document: { id: 'doc-1', document_number: 'INV-1', balance_due: '0.00', status: 'paid' },
    excess_handling: { excess_amount: '0.00', allocation_method: 'advance', allocations: [] },
  })
})

afterEach(() => {
  resetTenant()
})

describe('RecordPaymentModal tenant scope', () => {
  it('wraps lookup keys and gates missing tenant/company (.008-.010)', async () => {
    const queryClient = createClient()
    render(<RecordPaymentModal isOpen onClose={vi.fn()} prefill={prefill()} />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['payment-methods', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment-repositories', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['open-invoices', 'partner-1', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<RecordPaymentModal isOpen onClose={vi.fn()} prefill={prefill()} />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('invalidates payment recording cascades for only the active tenant (.011-.016)', async () => {
    const queryClient = createClient()
    let paymentCalls = 0
    let invoiceCalls = 0
    let invoicesCalls = 0
    let documentCalls = 0
    let documentsCalls = 0
    let openInvoiceCalls = 0

    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/payment-methods') {
        return { data: { data: [{ id: 'method-1', code: 'CASH', name: 'Cash', is_physical: false, has_maturity: false }] } }
      }
      if (url === '/payment-repositories') {
        return { data: { data: [{ id: 'repo-1', code: 'CASH', name: 'Cash Register', type: 'cash_register' }] } }
      }
      if (url === '/partners/partner-1/open-invoices') {
        openInvoiceCalls += 1
        return { data: { data: [{ id: `open-${openInvoiceCalls}`, document_number: 'INV-2', balance_due: '50.00', due_date: '2026-05-20' }] } }
      }
      return { data: { data: [] } }
    })

    queryClient.setQueryData(['payments', 'tenant-B', 'company-1'], { marker: 'tenant-B-payments' })
    queryClient.setQueryData(['invoice', 'doc-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoice' })
    queryClient.setQueryData(['invoices', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoices' })
    queryClient.setQueryData(['documents', 'tenant-B', 'company-1'], { marker: 'tenant-B-documents' })
    queryClient.setQueryData(['open-invoices', 'partner-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-open' })
    queryClient.setQueryData(['document', 'doc-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-document' })

    render(
      <>
        <Probe queryKey={['payments']} queryFn={async () => [`payments-${++paymentCalls}`]} />
        <Probe queryKey={['invoice', 'doc-1']} queryFn={async () => ({ id: `invoice-${++invoiceCalls}` })} />
        <Probe queryKey={['invoices']} queryFn={async () => [`invoices-${++invoicesCalls}`]} />
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <Probe queryKey={['document', 'doc-1']} queryFn={async () => ({ id: `document-${++documentCalls}` })} />
        <RecordPaymentModal isOpen onClose={vi.fn()} prefill={prefill()} />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(paymentCalls).toBe(1)
      expect(invoiceCalls).toBe(1)
      expect(invoicesCalls).toBe(1)
      expect(documentCalls).toBe(1)
      expect(documentsCalls).toBe(1)
      expect(openInvoiceCalls).toBe(1)
      expect(screen.getByRole('option', { name: 'Cash' })).toBeInTheDocument()
      expect(screen.getByRole('option', { name: 'Cash Register (CASH)' })).toBeInTheDocument()
    })

    await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method *'), 'method-1')
    await userEvent.type(screen.getByLabelText('treasury:payments.amount *'), '100')
    await userEvent.selectOptions(screen.getByLabelText('treasury:repositories.title'), 'repo-1')
    await userEvent.click(screen.getByRole('button', { name: 'common:actions.confirm' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: /treasury:payments.record/ }))
    })

    await waitFor(() => {
      expect(paymentCalls).toBe(2)
      expect(invoiceCalls).toBe(2)
      expect(invoicesCalls).toBe(2)
      expect(documentCalls).toBe(2)
      expect(documentsCalls).toBe(2)
      expect(openInvoiceCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['payments', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payments' })
    expect(queryClient.getQueryData(['invoice', 'doc-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoice' })
    expect(queryClient.getQueryData(['invoices', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoices' })
    expect(queryClient.getQueryData(['documents', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-documents' })
    expect(queryClient.getQueryData(['open-invoices', 'partner-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-open' })
    expect(queryClient.getQueryData(['document', 'doc-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-document' })
  })

  it('invalidates repository creation against active tenant only (.017)', async () => {
    const queryClient = createClient()
    queryClient.setQueryData(['payment-repositories', 'tenant-B', 'company-1'], { marker: 'tenant-B-repositories' })
    render(<RecordPaymentModal isOpen onClose={vi.fn()} prefill={prefill()} />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payment-repositories')).toHaveLength(1)
    })

    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'repository-success' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payment-repositories')).toHaveLength(2)
    })
    expect(queryClient.getQueryData(['payment-repositories', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-repositories' })
  })
  it('adds a key and synchronously locks duplicate payment recording', async () => {
    let resolvePost: (value: unknown) => void = () => {}
    mockApiPost.mockImplementation(() => new Promise((resolve) => { resolvePost = resolve }))
    render(<RecordPaymentModal isOpen onClose={vi.fn()} prefill={prefill()} />, {
      wrapper: wrapper(createClient()),
    })

    await screen.findByRole('option', { name: 'Cash' })
    await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method *'), 'method-1')
    await userEvent.type(screen.getByLabelText('treasury:payments.amount *'), '100')
    await userEvent.selectOptions(screen.getByLabelText('treasury:repositories.title'), 'repo-1')
    await userEvent.click(screen.getByRole('button', { name: 'common:actions.confirm' }))
    const record = screen.getByRole('button', { name: /treasury:payments.record/ })

    act(() => {
      fireEvent.click(record)
      fireEvent.click(record)
    })

    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })
    // The key is read back through a typed narrowing helper rather than an
    // `expect.stringMatching` matcher (which is typed `any`); the UUID shape is
    // asserted separately.
    expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
      idempotency_key: postedIdempotencyKey(0),
    }))
    expect(postedIdempotencyKey(0)).toMatch(/^[0-9a-f-]{36}$/)
    await act(async () => {
      resolvePost({
        payments: [{ id: 'payment-1', payment_number: 'PAY-1', amount: '100.00' }],
        document: { id: 'doc-1', document_number: 'INV-1', balance_due: '0.00', status: 'paid' },
        excess_handling: { excess_amount: '0.00', allocation_method: 'advance', allocations: [] },
      })
      await Promise.resolve()
    })
  })
})

/** Read the idempotency_key off a recorded POST body without an unsafe cast. */
function postedIdempotencyKey(callIndex: number): string {
  const body: unknown = mockApiPost.mock.calls[callIndex]?.[1]
  if (typeof body !== 'object' || body === null || !('idempotency_key' in body)) {
    throw new Error(`POST #${String(callIndex)} carried no request body`)
  }
  const key: unknown = body.idempotency_key
  if (typeof key !== 'string') {
    throw new Error(`POST #${String(callIndex)} carried no idempotency_key`)
  }
  return key
}
