import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import enSales from '@/locales/en/sales.json'
import frSales from '@/locales/fr/sales.json'

import { SalesOrderDetailPage } from '../SalesOrderDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockRouteId = vi.hoisted(() => ({ current: 'order-1' }))
const mockNavigate = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))
const mockToastError = vi.hoisted(() => vi.fn())

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
    Link: ({ children, to }: { children: ReactNode; to: string }) => <a href={to}>{children}</a>,
    useNavigate: () => mockNavigate,
    useParams: () => ({ id: mockRouteId.current }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

vi.mock('sonner', () => ({ toast: { error: mockToastError, success: vi.fn() } }))

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
    onConvert,
    onConvertToDelivery,
  }: {
    onConfirm?: () => void
    onConvert?: () => void
    onConvertToDelivery?: () => void
  }) => (
    <div>
      <button type="button" onClick={onConfirm}>confirm-order</button>
      <button type="button" onClick={onConvert}>convert-invoice</button>
      <button type="button" onClick={onConvertToDelivery}>convert-delivery</button>
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

vi.mock('../../components', () => ({
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

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: ({
    isOpen,
    onConfirm,
    title,
  }: {
    isOpen: boolean
    onConfirm: () => void
    title: string
  }) => (isOpen ? <button type="button" onClick={onConfirm}>{title}</button> : null),
}))

vi.mock('../../hooks', () => ({
  useDownloadPdf: () => ({ isPending: false, mutate: vi.fn() }),
  usePreviewPdf: () => ({ isPending: false, mutate: vi.fn() }),
  usePrintPdf: () => ({ isPending: false, mutate: vi.fn() }),
  useRevertDocument: () => ({ isPending: false, mutate: vi.fn() }),
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

function orderFixture() {
  return {
    id: 'order-1',
    type: 'sales_order',
    status: 'confirmed',
    document_number: 'SO-1',
    document_date: '2026-05-11',
    partner_id: 'partner-1',
    partner_name: 'Partner A',
    total: '150.00',
    amount_paid: '0.00',
    outstanding_amount: '150.00',
    balance_due: '150.00',
    currency: 'TND',
    payment_status: 'unpaid',
    payload: { delivery_status: 'not_delivered' },
    notes: null,
    lines: [
      {
        id: 'line-1',
        description: 'Whole item',
        quantity: '4.0000',
        quantity_delivered: '3.0000',
        quantity_decimals: 0,
        unit_price: '150.00',
        line_total: '150.00',
        notes: null,
      },
      {
        id: 'line-2',
        description: 'Fractional item',
        quantity: '3.1250',
        quantity_delivered: '2.5000',
        quantity_decimals: 3,
        unit_price: '150.00',
        line_total: '150.00',
        notes: null,
      },
    ],
  }
}

function mockOrderResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/orders/order-1') return { data: { data: orderFixture() } }
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
  mockApiGet.mockReset()
  mockApiPost.mockReset()
  mockNavigate.mockReset()
  mockTranslate.mockReset()
  mockTranslate.mockImplementation((key: string) => key)
  mockRouteId.current = 'order-1'
  setTenant('tenant-A', 'company-1')
  mockOrderResponses()
  mockApiPost.mockResolvedValue({ id: 'converted-1' })
})

afterEach(() => {
  resetTenant()
})

describe('SalesOrderDetailPage tenant scope', () => {
  it('displays ordered and delivered quantities at the product unit precision', async () => {
    render(<SalesOrderDetailPage />, { wrapper: wrapper(createClient()) })

    const wholeItemRow = (await screen.findByText('Whole item')).closest('tr')
    const fractionalItemRow = screen.getByText('Fractional item').closest('tr')

    if (wholeItemRow === null || fractionalItemRow === null) {
      throw new Error('Expected both sales order line rows to render')
    }

    expect(within(wholeItemRow).getByText('4')).toBeInTheDocument()
    expect(within(wholeItemRow).getByText('3')).toBeInTheDocument()
    expect(within(fractionalItemRow).getByText('3.125')).toBeInTheDocument()
    expect(within(fractionalItemRow).getByText('2.500')).toBeInTheDocument()
  })

  it('wraps sales order detail read key and gates missing tenant/company (.238)', async () => {
    const queryClient = createClient()
    render(<SalesOrderDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['document', 'sales_order', 'order-1', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<SalesOrderDetailPage />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('invalidates confirm and conversion success cascades for only active tenant (.239-.242, .244-.245)', async () => {
    const queryClient = createClient()
    let documentsCalls = 0
    queryClient.setQueryData(['documents', 'tenant-B', 'company-1'], { marker: 'tenant-B-documents' })

    render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <SalesOrderDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/orders/order-1')).toHaveLength(1)
      expect(documentsCalls).toBe(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'confirm-order' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'documents.confirmTitle' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/orders/order-1')).toHaveLength(2)
      expect(documentsCalls).toBe(2)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'convert-invoice' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'orders.convertToInvoiceTitle' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/orders/order-1')).toHaveLength(3)
      expect(documentsCalls).toBe(3)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'convert-delivery' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'orders.convertToDeliveryTitle' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/orders/order-1')).toHaveLength(4)
      expect(documentsCalls).toBe(4)
    })
    expect(queryClient.getQueryData(['documents', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-documents' })
  })

  it('invalidates conversion error detail paths with scoped exact keys (.243, .246)', async () => {
    const queryClient = createClient()
    mockApiPost.mockRejectedValue(new Error('conversion failed'))

    render(<SalesOrderDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/orders/order-1')).toHaveLength(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'convert-invoice' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'orders.convertToInvoiceTitle' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/orders/order-1')).toHaveLength(2)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'convert-delivery' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'orders.convertToDeliveryTitle' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/orders/order-1')).toHaveLength(3)
    })
  })

  it('invalidates payment success cascade with active-tenant payment isolation (.247-.249)', async () => {
    const queryClient = createClient()
    let documentsCalls = 0
    let paymentCalls = 0
    queryClient.setQueryData(['payments', 'tenant-B', 'company-1'], { marker: 'tenant-B-payments' })

    render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <Probe queryKey={['payments']} queryFn={async () => [`payments-${++paymentCalls}`]} />
        <SalesOrderDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/orders/order-1')).toHaveLength(1)
      expect(documentsCalls).toBe(1)
      expect(paymentCalls).toBe(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'record-payment-callout' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'payment-success' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/orders/order-1')).toHaveLength(2)
      expect(documentsCalls).toBe(2)
      expect(paymentCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['payments', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payments' })
  })
})

describe('SalesOrderDetailPage atomic billing refusal', () => {
  const translations: Record<string, string> = {
    'orders.billingRefusal.title': 'Delivery notes were already invoiced',
    'orders.billingRefusal.guarantee': 'No invoice was created. No invoice number was used.',
    'orders.billingRefusal.billedBy.consolidation': 'Billed by consolidation',
    'orders.billingRefusal.billedBy.order_conversion': 'Billed from sales order',
    'orders.billingRefusal.openInvoice': 'Open {{number}}',
    'orders.billingRefusal.invoiceRemaining': 'Invoice remaining lines…',
    'orders.billingRefusal.noRemaining': 'Every order line is already covered by the invoices above.',
    'orders.billingRefusal.remainingUnavailable': 'Remaining lines cannot be determined safely for these delivery notes.',
    'orders.billingRefusal.remainingTitle': 'Invoice remaining lines',
    'orders.billingRefusal.confirmRemaining': 'Create invoice',
  }

  function translate(key: string, options?: Record<string, unknown>): string {
    const template = translations[key] ?? key
    return Object.entries(options ?? {}).reduce(
      (value, [name, replacement]) => value.replace(`{{${name}}}`, String(replacement)),
      template,
    )
  }

  function claimLossError(sourceLineIds?: string[]) {
    return {
      response: {
        status: 422,
        data: {
          error: {
            code: 'DELIVERY_NOTE_ALREADY_INVOICED',
            message: 'Delivery notes were already invoiced',
            details: {
              documents: [
                {
                  id: 'dn-1',
                  document_number: 'DN-1001',
                  reason: 'claim_lost',
                  invoice_id: 'invoice-1',
                  invoice_number: 'INV-1001',
                  invoice_date: '2026-08-17',
                  invoiced_via: 'consolidation',
                  ...(sourceLineIds === undefined ? {} : { source_line_ids: sourceLineIds }),
                },
                {
                  id: 'dn-2',
                  document_number: 'DN-1002',
                  reason: 'already_invoiced',
                  invoice_id: 'invoice-2',
                  invoice_number: 'INV-1002',
                  invoice_date: '2026-08-18',
                  invoiced_via: 'order_conversion',
                  ...(sourceLineIds === undefined ? {} : { source_line_ids: sourceLineIds }),
                },
              ],
            },
          },
        },
      },
    }
  }

  it('keeps every attributed lost claim inline across rerender and requires confirmation for pre-excluded remaining lines', async () => {
    mockTranslate.mockImplementation(translate)
    mockApiPost.mockRejectedValueOnce(claimLossError(['line-1'])).mockResolvedValueOnce({ id: 'invoice-3' })
    const queryClient = createClient()
    const view = render(<SalesOrderDetailPage />, { wrapper: wrapper(queryClient) })

    await userEvent.click(await screen.findByRole('button', { name: 'convert-invoice' }))
    await userEvent.click(screen.getByRole('button', { name: 'orders.convertToInvoiceTitle' }))

    const refusal = await screen.findByRole('alert')
    expect(within(refusal).getByText('No invoice was created. No invoice number was used.')).toBeInTheDocument()
    expect(within(refusal).getByText('DN-1001')).toBeInTheDocument()
    expect(within(refusal).getByText('DN-1002')).toBeInTheDocument()
    expect(refusal).toHaveTextContent('2026-08-17')
    expect(refusal).toHaveTextContent('2026-08-18')
    expect(refusal).toHaveTextContent('Billed by consolidation')
    expect(refusal).toHaveTextContent('Billed from sales order')
    expect(within(refusal).getByRole('link', { name: 'Open INV-1001' })).toHaveAttribute('href', '/sales/invoices/invoice-1')
    expect(within(refusal).getByRole('link', { name: 'Open INV-1002' })).toHaveAttribute('href', '/sales/invoices/invoice-2')
    expect(within(refusal).queryByRole('button', { name: /retry/i })).not.toBeInTheDocument()
    expect(mockToastError).not.toHaveBeenCalled()

    view.rerender(<SalesOrderDetailPage />)
    expect(screen.getByText('No invoice was created. No invoice number was used.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Invoice remaining lines…' }))
    const picker = screen.getByRole('dialog')
    expect(within(picker).queryByText('Whole item')).not.toBeInTheDocument()
    expect(within(picker).getByText('Fractional item')).toBeInTheDocument()
    expect(mockApiPost).toHaveBeenCalledTimes(1)

    await userEvent.click(within(picker).getByRole('button', { name: 'Create invoice' }))
    await waitFor(() => {
      expect(mockApiPost).toHaveBeenLastCalledWith('/orders/order-1/convert-to-invoice', {
        partial: true,
        line_ids: ['line-2'],
      })
    })
  })

  it('keeps invoice links and explains when no order lines remain', async () => {
    mockTranslate.mockImplementation(translate)
    mockApiPost.mockRejectedValueOnce(claimLossError(['line-1', 'line-2']))
    render(<SalesOrderDetailPage />, { wrapper: wrapper(createClient()) })

    await userEvent.click(await screen.findByRole('button', { name: 'convert-invoice' }))
    await userEvent.click(screen.getByRole('button', { name: 'orders.convertToInvoiceTitle' }))

    const refusal = await screen.findByRole('alert')
    expect(within(refusal).getByText('Every order line is already covered by the invoices above.')).toBeInTheDocument()
    expect(within(refusal).getByRole('link', { name: 'Open INV-1001' })).toBeInTheDocument()
    expect(within(refusal).queryByRole('button', { name: 'Invoice remaining lines…' })).not.toBeInTheDocument()
  })

  it('does not offer remaining-line billing when a lost delivery note has unlinked lines', async () => {
    mockTranslate.mockImplementation(translate)
    mockApiPost.mockRejectedValueOnce(claimLossError())
    render(<SalesOrderDetailPage />, { wrapper: wrapper(createClient()) })

    await userEvent.click(await screen.findByRole('button', { name: 'convert-invoice' }))
    await userEvent.click(screen.getByRole('button', { name: 'orders.convertToInvoiceTitle' }))

    const refusal = await screen.findByRole('alert')
    expect(within(refusal).getByText('Remaining lines cannot be determined safely for these delivery notes.')).toBeInTheDocument()
    expect(within(refusal).queryByRole('button', { name: 'Invoice remaining lines…' })).not.toBeInTheDocument()
  })

  it('ships the atomic-refusal guarantee in English and French', () => {
    expect(enSales.orders.billingRefusal.guarantee).toBe('No invoice was created. No invoice number was used.')
    expect(frSales.orders.billingRefusal.guarantee).toBe("Aucune facture n’a été créée. Aucun numéro de facture n’a été utilisé.")
  })
})
