import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { InvoiceDetailPage } from '../InvoiceDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockRouteId = vi.hoisted(() => ({ current: 'invoice-1' }))
const mockNavigate = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

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

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'company-1', currency: 'TND' } }),
}))

vi.mock('../../components/DocumentHeader', () => ({
  DocumentHeader: ({
    actions,
    children,
    financialCallout,
  }: {
    actions?: ReactNode
    children?: ReactNode
    financialCallout?: ReactNode
  }) => (
    <div>
      {actions}
      {financialCallout}
      {children}
    </div>
  ),
}))

vi.mock('../../components/DocumentActionBar', () => ({
  DocumentActionBar: ({
    onConfirm,
    onCreateCreditNote,
    onPost,
  }: {
    onConfirm?: () => void
    onCreateCreditNote?: () => void
    onPost?: () => void
  }) => (
    <div>
      <button type="button" onClick={onConfirm}>confirm-invoice</button>
      <button type="button" onClick={onPost}>post-invoice</button>
      <button type="button" onClick={onCreateCreditNote}>create-credit-note</button>
    </div>
  ),
}))

vi.mock('../../components/DocumentOutstandingCallout', () => ({
  DocumentOutstandingCallout: ({ onRecordPayment }: { onRecordPayment?: () => void }) => (
    <button type="button" onClick={onRecordPayment}>record-payment-callout</button>
  ),
}))

vi.mock('../../components/DocumentTotals', () => ({ DocumentTotals: () => null }))
vi.mock('../../components/RelatedDocumentsTab', () => ({ RelatedDocumentsTab: () => null }))
vi.mock('../../components/DocumentAttachments', () => ({ DocumentAttachments: () => null }))
vi.mock('../components/CloseWithWriteoffSection', () => ({ CloseWithWriteoffSection: () => null }))

vi.mock('../../components/DeliveryConfirmationModal', () => ({
  DeliveryConfirmationModal: ({ onConfirmAndPost }: { onConfirmAndPost: () => void }) => (
    <button type="button" onClick={onConfirmAndPost}>confirm-deliveries-and-post</button>
  ),
}))

vi.mock('../../components', () => ({
  CreateCreditNoteForm: ({ onSuccess }: { onSuccess: () => void | Promise<void> }) => (
    <button type="button" onClick={() => { void onSuccess(); }}>credit-note-success</button>
  ),
  CreditNoteList: () => null,
  OutstandingAmountSection: ({ onRecordPayment }: { onRecordPayment?: () => void }) => (
    <button type="button" onClick={onRecordPayment}>record-payment-section</button>
  ),
  PaymentHistorySection: () => null,
}))

vi.mock('@/components/organisms/RecordPaymentModal', () => ({
  RecordPaymentModal: ({
    isOpen,
    onSuccess,
  }: {
    isOpen: boolean
    onSuccess: () => void | Promise<void>
  }) => (isOpen ? <button type="button" onClick={() => { void onSuccess(); }}>payment-success</button> : null),
}))

vi.mock('@/components/molecules/EntityLink', () => ({
  EntityLink: ({ label }: { label: ReactNode }) => <span>{label}</span>,
}))

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: ({
    confirmText,
    isOpen,
    onConfirm,
    title,
  }: {
    confirmText?: string
    isOpen: boolean
    onConfirm: () => void
    title: string
  }) => (isOpen ? <button type="button" onClick={onConfirm}>{confirmText ?? title}</button> : null),
}))

vi.mock('../../hooks', () => ({
  useCreditNotes: () => ({ data: [] }),
  useDownloadPdf: () => ({ isPending: false, mutate: vi.fn() }),
  usePreviewPdf: () => ({ isPending: false, mutate: vi.fn() }),
  usePrintPdf: () => ({ isPending: false, mutate: vi.fn() }),
  useSendDocumentEmail: () => ({ isPending: false, mutate: vi.fn() }),
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

function invoiceFixture() {
  return {
    id: 'invoice-1',
    type: 'invoice',
    status: 'confirmed',
    document_number: 'INV-1',
    document_date: '2026-05-11',
    due_date: '2026-05-25',
    partner_id: 'partner-1',
    partner_name: 'Partner A',
    total: '150.00',
    amount_paid: '0.00',
    outstanding_amount: '150.00',
    balance_due: '150.00',
    currency: 'TND',
    payment_status: 'unpaid',
    notes: null,
    lines: [
      {
        id: 'line-1',
        description: 'Service',
        quantity: '1.00',
        unit_price: '150.00',
        line_total: '150.00',
        notes: null,
      },
    ],
  }
}

function mockInvoiceResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/invoice-1') return { data: { data: invoiceFixture() } }
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
  mockRouteId.current = 'invoice-1'
  setTenant('tenant-A', 'company-1')
  mockInvoiceResponses()
  mockApiPost.mockResolvedValue({ data: { data: invoiceFixture() } })
})

afterEach(() => {
  resetTenant()
})

describe('InvoiceDetailPage tenant scope', () => {
  it('wraps invoice detail read key and gates missing tenant/company (.204)', async () => {
    const queryClient = createClient()
    render(<InvoiceDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['document', 'invoice', 'invoice-1', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<InvoiceDetailPage />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('invalidates confirm and post detail/documents cascades for only the active tenant (.205-.208)', async () => {
    const queryClient = createClient()
    let documentsCalls = 0
    queryClient.setQueryData(['documents', 'tenant-B', 'company-1'], { marker: 'tenant-B-documents' })

    render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <InvoiceDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/invoices/invoice-1')).toHaveLength(1)
      expect(documentsCalls).toBe(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'confirm-invoice' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'common:confirm' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/invoices/invoice-1')).toHaveLength(2)
      expect(documentsCalls).toBe(2)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'post-invoice' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'invoices.post' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/invoices/invoice-1')).toHaveLength(3)
      expect(documentsCalls).toBe(3)
    })
    expect(queryClient.getQueryData(['documents', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-documents' })
  })

  it('invalidates confirm-deliveries cascade with delivery-note predicate isolation (.209-.211)', async () => {
    const queryClient = createClient()
    let documentsCalls = 0
    let deliveryCalls = 0
    queryClient.setQueryData(['delivery-notes', 'tenant-B', 'company-1'], { marker: 'tenant-B-delivery-notes' })

    render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <Probe queryKey={['delivery-notes']} queryFn={async () => [`delivery-${++deliveryCalls}`]} />
        <InvoiceDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/invoices/invoice-1')).toHaveLength(1)
      expect(documentsCalls).toBe(1)
      expect(deliveryCalls).toBe(1)
    })

    await act(async () => {
      await userEvent.click(await screen.findByRole('button', { name: 'confirm-deliveries-and-post' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/invoices/invoice-1')).toHaveLength(2)
      expect(documentsCalls).toBe(2)
      expect(deliveryCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['delivery-notes', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-delivery-notes' })
  })

  it('invalidates credit-note creation cascade with active-tenant predicates (.212-.214)', async () => {
    const queryClient = createClient()
    let documentsCalls = 0
    let creditNoteCalls = 0
    queryClient.setQueryData(['credit-notes', 'tenant-B', 'company-1'], { marker: 'tenant-B-credit-notes' })

    render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <Probe queryKey={['credit-notes']} queryFn={async () => [`credit-${++creditNoteCalls}`]} />
        <InvoiceDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/invoices/invoice-1')).toHaveLength(1)
      expect(documentsCalls).toBe(1)
      expect(creditNoteCalls).toBe(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'create-credit-note' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'credit-note-success' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/invoices/invoice-1')).toHaveLength(2)
      expect(documentsCalls).toBe(2)
      expect(creditNoteCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['credit-notes', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-credit-notes' })
  })

  it('invalidates payment success cascade with active-tenant payment isolation (.215-.217)', async () => {
    const queryClient = createClient()
    let documentsCalls = 0
    let paymentCalls = 0
    queryClient.setQueryData(['payments', 'tenant-B', 'company-1'], { marker: 'tenant-B-payments' })

    render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <Probe queryKey={['payments']} queryFn={async () => [`payments-${++paymentCalls}`]} />
        <InvoiceDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/invoices/invoice-1')).toHaveLength(1)
      expect(documentsCalls).toBe(1)
      expect(paymentCalls).toBe(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'record-payment-callout' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'payment-success' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/invoices/invoice-1')).toHaveLength(2)
      expect(documentsCalls).toBe(2)
      expect(paymentCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['payments', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payments' })
  })
})
