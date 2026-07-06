import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { toast } from 'sonner'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { PurchaseOrderDetailPage } from '../PurchaseOrderDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockAxiosPost = vi.hoisted(() => vi.fn())
const mockRouteId = vi.hoisted(() => ({ current: 'po-1' }))
const mockNavigate = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string, options?: Record<string, string>) => {
  if (key === 'purchaseOrders.receivedOfTotal') {
    return `${options?.['received'] ?? '?'} of ${options?.['total'] ?? '?'}`
  }
  if (key === 'documents.messages.goodsReceivedWithReceipt') {
    return `Goods received: ${options?.['receiptNumber'] ?? '?'}`
  }

  return key
}))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet, post: mockAxiosPost },
    apiPost: mockApiPost,
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
    useParams: () => ({ id: mockRouteId.current }),
    useNavigate: () => mockNavigate,
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

vi.mock('../../components/DocumentActionBar', () => ({
  DocumentActionBar: ({
    onConfirm,
    onReceiveGoods,
    onRecordPayment,
  }: {
    onConfirm?: () => void
    onReceiveGoods?: () => void
    onRecordPayment?: () => void
  }) => (
    <div>
      <button type="button" onClick={onConfirm}>confirm-po</button>
      <button type="button" onClick={onReceiveGoods}>receive-goods</button>
      <button type="button" onClick={onRecordPayment}>record-payment</button>
    </div>
  ),
}))

vi.mock('../../components/RelatedDocumentsTab', () => ({ RelatedDocumentsTab: () => null }))
vi.mock('../../components/DocumentAttachments', () => ({ DocumentAttachments: () => null }))
vi.mock('../../components/PurchaseOrderLandedCostBreakdown', () => ({ PurchaseOrderLandedCostBreakdown: () => null }))
vi.mock('../../components/PaymentStatusBadge', () => ({ PaymentStatusBadge: () => null }))

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

function purchaseOrderFixture() {
  return {
    id: 'po-1',
    type: 'purchase_order',
    status: 'confirmed',
    document_number: 'PO-1',
    document_date: '2026-05-11',
    due_date: '2026-05-20',
    partner_id: 'supplier-1',
    partner_name: 'Supplier A',
    subtotal: '100.00',
    tax_amount: '0.00',
    total: '100.00',
    amount_paid: '0.00',
    outstanding_amount: '100.00',
    balance_due: '100.00',
    currency: 'TND',
    payment_status: 'unpaid',
    notes: null,
    lines: [
      {
        id: 'line-1',
        product_id: 'product-1',
        product_name: 'Stock',
        description: 'Stock',
        product_code: 'STK-001',
        product_barcode: '619000000001',
        primary_image_url: '/stock.png',
        quantity: '5.00',
        requires_batch_tracking: false,
        unit_price: '100.00',
        line_total: '100.00',
        notes: null,
      },
    ],
  }
}

function mockPurchaseOrderResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/purchase-orders/po-1') return { data: { data: purchaseOrderFixture() } }
    if (url === '/purchase-orders/po-1/receipt-status') {
      return {
        data: {
          data: {
            status: 'not_received',
            total_ordered: '5.0000',
            total_received: '0.0000',
            percentage: 0,
            lines: [
              {
                line_id: 'line-1',
                product_name: 'Stock',
                quantity_ordered: '5.0000',
                quantity_received: '0.0000',
                quantity_remaining: '5.0000',
                is_complete: false,
              },
            ],
          },
        },
      }
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
  mockRouteId.current = 'po-1'
  setTenant('tenant-A', 'company-1')
  mockPurchaseOrderResponses()
  mockApiPost.mockResolvedValue({ data: { data: purchaseOrderFixture() } })
  mockAxiosPost.mockResolvedValue({
    data: {
      data: purchaseOrderFixture(),
      meta: {
        goods_receipt: {
          id: 'gr-1',
          receipt_number: 'GRN-2026-0031',
        },
      },
    },
  })
})

afterEach(() => {
  resetTenant()
})

describe('PurchaseOrderDetailPage tenant scope', () => {
  it('wraps purchase order detail read key and gates missing tenant/company (.221)', async () => {
    const queryClient = createClient()
    render(<PurchaseOrderDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['document', 'purchase_order', 'po-1', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<PurchaseOrderDetailPage />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('invalidates confirm and receive-goods cascades for only active tenant (.222-.226)', async () => {
    const queryClient = createClient()
    let documentsCalls = 0
    let stockCalls = 0
    queryClient.setQueryData(['documents', 'tenant-B', 'company-1'], { marker: 'tenant-B-documents' })
    queryClient.setQueryData(['stock-levels', 'tenant-B', 'company-1'], { marker: 'tenant-B-stock' })

    render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <Probe queryKey={['stock-levels']} queryFn={async () => [`stock-${++stockCalls}`]} />
        <PurchaseOrderDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/purchase-orders/po-1')).toHaveLength(1)
      expect(documentsCalls).toBe(1)
      expect(stockCalls).toBe(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'confirm-po' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'documents.confirmTitle' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/purchase-orders/po-1')).toHaveLength(2)
      expect(documentsCalls).toBe(2)
      expect(stockCalls).toBe(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'receive-goods' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'purchaseOrders.receive.saveAndPost' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/purchase-orders/po-1')).toHaveLength(3)
      expect(documentsCalls).toBe(3)
      expect(stockCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['documents', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-documents' })
    expect(queryClient.getQueryData(['stock-levels', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-stock' })
  })

  it('invalidates payment success cascade with active-tenant payment isolation (.227-.229)', async () => {
    const queryClient = createClient()
    let documentsCalls = 0
    let paymentCalls = 0
    queryClient.setQueryData(['payments', 'tenant-B', 'company-1'], { marker: 'tenant-B-payments' })

    render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <Probe queryKey={['payments']} queryFn={async () => [`payments-${++paymentCalls}`]} />
        <PurchaseOrderDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/purchase-orders/po-1')).toHaveLength(1)
      expect(documentsCalls).toBe(1)
      expect(paymentCalls).toBe(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'record-payment' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'payment-success' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/purchase-orders/po-1')).toHaveLength(2)
      expect(documentsCalls).toBe(2)
      expect(paymentCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['payments', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payments' })
  })

  it('submits partial received quantities as strings', async () => {
    render(<PurchaseOrderDetailPage />, { wrapper: wrapper(createClient()) })

    await userEvent.click(await screen.findByRole('button', { name: 'receive-goods' }))
    const quantityInput = await screen.findByLabelText(/purchaseOrders.receive.quantity Stock/)
    await userEvent.clear(quantityInput)
    await userEvent.type(quantityInput, '2.50')
    await userEvent.click(screen.getByRole('button', { name: 'purchaseOrders.receive.saveAndPost' }))

    await waitFor(() => {
      expect(mockAxiosPost).toHaveBeenCalledWith('/purchase-orders/po-1/receive', {
        quantities: {
          'line-1': '2.5',
        },
      })
    })
  })

  it('surfaces the created GRN number after receiving goods', async () => {
    render(<PurchaseOrderDetailPage />, { wrapper: wrapper(createClient()) })

    await userEvent.click(await screen.findByRole('button', { name: 'receive-goods' }))
    await userEvent.click(screen.getByRole('button', { name: 'purchaseOrders.receive.saveAndPost' }))

    await waitFor(() => {
      expect(toast.success).toHaveBeenCalledWith('Goods received: GRN-2026-0031')
    })
  })

  it('renders receipt status and line progress from the receipt-status endpoint', async () => {
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/purchase-orders/po-1') return { data: { data: purchaseOrderFixture() } }
      if (url === '/purchase-orders/po-1/receipt-status') {
        return {
          data: {
            data: {
              status: 'partially_received',
              total_ordered: '10.0000',
              total_received: '4.0000',
              percentage: 40,
              lines: [
                {
                  line_id: 'line-1',
                  product_name: 'Stock',
                  quantity_ordered: '10.0000',
                  quantity_received: '4.0000',
                  quantity_remaining: '6.0000',
                  is_complete: false,
                },
              ],
            },
          },
        }
      }

      return { data: { data: [] } }
    })

    render(<PurchaseOrderDetailPage />, { wrapper: wrapper(createClient()) })

    expect(await screen.findByText('purchaseOrders.receiptStatus.partially_received')).toBeInTheDocument()
    expect(screen.getByText('4 of 10')).toBeInTheDocument()
    expect(mockApiGet).toHaveBeenCalledWith('/purchase-orders/po-1/receipt-status')
  })

  it('invalidates receipt status after receiving goods succeeds', async () => {
    const queryClient = createClient()
    render(<PurchaseOrderDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/purchase-orders/po-1/receipt-status')
    })

    let receiptStatusFetches = mockApiGet.mock.calls.filter(
      ([url]) => url === '/purchase-orders/po-1/receipt-status',
    ).length

    await userEvent.click(await screen.findByRole('button', { name: 'receive-goods' }))
    await userEvent.click(screen.getByRole('button', { name: 'purchaseOrders.receive.saveAndPost' }))

    await waitFor(() => {
      receiptStatusFetches = mockApiGet.mock.calls.filter(
        ([url]) => url === '/purchase-orders/po-1/receipt-status',
      ).length
      expect(receiptStatusFetches).toBeGreaterThan(1)
    })
  })

  it('renders purchase order lines with ProductCell identity fields', async () => {
    render(<PurchaseOrderDetailPage />, { wrapper: wrapper(createClient()) })

    expect(await screen.findByRole('img', { name: 'Stock' })).toHaveAttribute('src', '/stock.png')
    expect(screen.getByText('STK-001')).toBeInTheDocument()
    expect(screen.getByText('619000000001')).toBeInTheDocument()
  })

  it('requires and submits batch data for batch-tracked receipt lines', async () => {
    const batchTrackedPurchaseOrder = {
      ...purchaseOrderFixture(),
      lines: [
        {
          ...purchaseOrderFixture().lines[0],
          requires_batch_tracking: true,
        },
      ],
    }
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/purchase-orders/po-1') return { data: { data: batchTrackedPurchaseOrder } }
      return { data: { data: [] } }
    })

    render(<PurchaseOrderDetailPage />, { wrapper: wrapper(createClient()) })

    await userEvent.click(await screen.findByRole('button', { name: 'receive-goods' }))
    expect(screen.getByRole('button', { name: 'purchaseOrders.receive.saveAndPost' })).toBeDisabled()

    await userEvent.type(await screen.findByLabelText(/purchaseOrders.receive.batchNumber Stock/), 'LOT-2026-A')
    await userEvent.type(screen.getByLabelText(/purchaseOrders.receive.expiryDate Stock/), '2027-03-31')
    await userEvent.click(screen.getByRole('button', { name: 'purchaseOrders.receive.saveAndPost' }))

    await waitFor(() => {
      expect(mockAxiosPost).toHaveBeenCalledWith('/purchase-orders/po-1/receive', {
        quantities: {
          'line-1': '5.0000',
        },
        batches: {
          'line-1': {
            batch_number: 'LOT-2026-A',
            expiry_date: '2027-03-31',
          },
        },
      })
    })
  })
})
