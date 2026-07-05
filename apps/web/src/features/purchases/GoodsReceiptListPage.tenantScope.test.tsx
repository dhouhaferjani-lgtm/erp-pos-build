import { QueryClient, useQuery } from '@tanstack/react-query'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { toast } from 'sonner'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '../../test/renderWithProviders'

import { GoodsReceiptListPage } from './GoodsReceiptListPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockAxiosPost = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string, options?: Record<string, string>) => {
  if (key === 'inventory:goodsReceipt.successMessageWithReceipt') {
    return `Goods receipt: ${options?.['receiptNumber'] ?? '?'}`
  }

  return key
}))

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGet,
      post: mockAxiosPost,
    },
  }
})

vi.mock('../../hooks/useCompany', () => ({
  useCompany: () => ({
    currentCompany: {
      id: 'company-1',
      currency: 'TND',
    },
  }),
}))

vi.mock('../../components/ui/ConfirmDialog', () => ({
  ConfirmDialog: ({
    isOpen,
    onConfirm,
    confirmText,
  }: {
    isOpen: boolean
    onConfirm: () => void
    confirmText: string
  }) => isOpen ? (
    <button type="button" onClick={onConfirm}>
      {confirmText}
    </button>
  ) : null,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  }
})

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: ['admin'],
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

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function cacheKeys(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

const pendingPurchaseOrder = {
  id: 'po-1',
  document_number: 'PO-1',
  partner_id: 'partner-1',
  partner_name: 'Supplier',
  status: 'confirmed',
  issue_date: '2026-05-11',
  total: 10,
  currency: 'TND',
  lines: [
    {
      id: 'line-1',
      product_id: 'product-1',
      product_name: 'Part',
      description: 'Part',
      quantity: 2,
      quantity_received: 0,
      quantity_decimals: 4,
      requires_batch_tracking: false,
      unit_price: 5,
    },
  ],
  payload: {
    fully_received: false,
  },
}

const fullyReceivedPurchaseOrder = {
  ...pendingPurchaseOrder,
  id: 'po-2',
  document_number: 'PO-2',
  payload: {
    fully_received: true,
  },
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockImplementation(async (url: string, options?: { params?: { status?: string } }) => {
    if (url === '/purchase-orders/po-1') {
      return { data: { data: pendingPurchaseOrder } }
    }
    if (options?.params?.status === 'received') {
      return { data: { data: [] } }
    }
    return { data: { data: [pendingPurchaseOrder, fullyReceivedPurchaseOrder] } }
  })
  mockAxiosPost.mockResolvedValue({
    data: {
      data: pendingPurchaseOrder,
      meta: {
        goods_receipt: {
          id: 'gr-1',
          receipt_number: 'GRN-2026-0032',
        },
      },
    },
  })
})

afterEach(() => {
  resetTenant()
})

describe('GoodsReceiptListPage tenant scope', () => {
  function StockLevelsProbe({ onFetch }: { onFetch: () => void }) {
    useQuery({
      queryKey: tenantScopedKey(['stock-levels']),
      queryFn: async () => {
        onFetch()
        return []
      },
    })
    return null
  }

  it('scopes goods-receipt purchase-order query keys (.581-.583)', () => {
    const queryClient = createPersistentQueryClient()

    renderWithProviders(<GoodsReceiptListPage />, { queryClient })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['purchase-orders', 'pending-receipt', 'tenant-A', 'company-1'],
      ['purchase-orders', 'received', 'tenant-A', 'company-1'],
      ['purchase-orders', 'confirmed-fully-received', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch without tenant/company state', () => {
    resetTenant()

    renderWithProviders(<GoodsReceiptListPage />)

    expect(mockApiGet).not.toHaveBeenCalled()
  })

  it('renders a clean date for purchase-order rows shaped with document_date', async () => {
    const poWithDocumentDate = {
      ...pendingPurchaseOrder,
      id: 'po-document-date',
      document_number: 'PO-DOC-DATE',
      issue_date: undefined,
      document_date: '2026-06-28',
    }

    mockApiGet.mockImplementation((url: string, options?: { params?: { status?: string } }) => {
      if (url === '/purchase-orders/po-document-date') {
        return Promise.resolve({ data: { data: poWithDocumentDate } })
      }
      if (options?.params?.status === 'received') {
        return Promise.resolve({ data: { data: [] } })
      }
      return Promise.resolve({ data: { data: [poWithDocumentDate] } })
    })

    renderWithProviders(<GoodsReceiptListPage />)

    expect(await screen.findByText('PO-DOC-DATE')).toBeInTheDocument()
    expect(screen.queryByText('Invalid Date')).not.toBeInTheDocument()
    expect(screen.getByText(/6\/28\/2026|06\/28\/2026/)).toBeInTheDocument()
  })

  it('links purchase orders, suppliers, and product previews from receipt rows', async () => {
    renderWithProviders(<GoodsReceiptListPage />)

    expect(await screen.findByRole('link', { name: 'PO-1' })).toHaveAttribute('href', '/purchases/orders/po-1')
    expect(screen.getByRole('link', { name: 'Supplier' })).toHaveAttribute('href', '/purchases/suppliers/partner-1')
    expect(screen.getByRole('link', { name: 'Part' })).toHaveAttribute('href', '/inventory/products/product-1')
  })

  it('submits partial quantities from the receipt list receive dialog', async () => {
    renderWithProviders(<GoodsReceiptListPage />)

    await userEvent.click(await screen.findByText('inventory:goodsReceipt.receiveAll'))
    const quantityInput = await screen.findByLabelText(/purchaseOrders.receive.quantity Part/)
    await userEvent.clear(quantityInput)
    await userEvent.type(quantityInput, '1')
    await userEvent.click(screen.getByRole('button', { name: 'purchaseOrders.receive.saveAndPost' }))

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/purchase-orders/po-1')
      expect(mockAxiosPost).toHaveBeenCalledWith('/purchase-orders/po-1/receive', {
        quantities: {
          'line-1': '1',
        },
      })
    })
  })

  it('surfaces the created GRN number after receiving goods from the receipt list', async () => {
    renderWithProviders(<GoodsReceiptListPage />)

    await userEvent.click(await screen.findByText('inventory:goodsReceipt.receiveAll'))
    await userEvent.click(screen.getByRole('button', { name: 'purchaseOrders.receive.saveAndPost' }))

    await waitFor(() => {
      expect(toast.success).toHaveBeenCalledWith('Goods receipt: GRN-2026-0032')
    })
  })

  it('allows invoice creation when received purchase-order selection spans multiple POs for one supplier', async () => {
    const receivedPoA = {
      ...fullyReceivedPurchaseOrder,
      id: 'po-received-a',
      document_number: 'PO-RECEIVED-A',
      partner_id: 'supplier-1',
      partner_name: 'Same Supplier',
    }
    const receivedPoB = {
      ...fullyReceivedPurchaseOrder,
      id: 'po-received-b',
      document_number: 'PO-RECEIVED-B',
      partner_id: 'supplier-1',
      partner_name: 'Same Supplier',
    }
    mockApiGet.mockImplementation(async (_url: string, options?: { params?: { status?: string } }) => {
      if (options?.params?.status === 'received') {
        return { data: { data: [receivedPoA, receivedPoB] } }
      }
      return { data: { data: [] } }
    })

    renderWithProviders(<GoodsReceiptListPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'inventory:goodsReceipt.tabs.received' }))
    const checkboxes = await screen.findAllByLabelText('purchases:supplierInvoices.create.selectReceiptPo')
    await userEvent.click(checkboxes[0])
    await userEvent.click(checkboxes[1])

    const action = screen.getByTestId('invoice-receipts')
    expect(action).toBeEnabled()
    await userEvent.click(action)

    expect(mockNavigate).toHaveBeenCalledWith('/purchases/supplier-invoices/new?po=po-received-a&po=po-received-b&entry=receipts')
  })

  it('blocks invoice creation when received purchase-order selection spans multiple suppliers', async () => {
    const receivedPoA = {
      ...fullyReceivedPurchaseOrder,
      id: 'po-received-a',
      document_number: 'PO-RECEIVED-A',
      partner_id: 'supplier-1',
      partner_name: 'Supplier A',
    }
    const receivedPoB = {
      ...fullyReceivedPurchaseOrder,
      id: 'po-received-b',
      document_number: 'PO-RECEIVED-B',
      partner_id: 'supplier-2',
      partner_name: 'Supplier B',
    }
    mockApiGet.mockImplementation(async (_url: string, options?: { params?: { status?: string } }) => {
      if (options?.params?.status === 'received') {
        return { data: { data: [receivedPoA, receivedPoB] } }
      }
      return { data: { data: [] } }
    })

    renderWithProviders(<GoodsReceiptListPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'inventory:goodsReceipt.tabs.received' }))
    const checkboxes = await screen.findAllByLabelText('purchases:supplierInvoices.create.selectReceiptPo')
    await userEvent.click(checkboxes[0])
    await userEvent.click(checkboxes[1])

    const action = screen.getByTestId('invoice-receipts')
    expect(action).toBeDisabled()
    expect(action).toHaveAttribute('title', 'purchases:supplierInvoices.create.crossSupplierTooltip')
    expect(screen.getByText('purchases:supplierInvoices.create.crossSupplierTooltip')).toBeInTheDocument()
  })

  it('refetches current-tenant purchase and stock caches and preserves tenant-B cache (.584-.585)', async () => {
    const queryClient = createPersistentQueryClient()
    const counters = {
      confirmed: 0,
      received: 0,
      stock: 0,
    }
    mockApiGet.mockImplementation(async (url: string, options?: { params?: { status?: string } }) => {
      if (url === '/purchase-orders/po-1') {
        return { data: { data: pendingPurchaseOrder } }
      }
      if (url === '/goods-receipts') {
        // Draft-count workbench badge (W3) — not part of the PO cache counters.
        return { data: { data: [], meta: { total: 0 } } }
      }
      if (options?.params?.status === 'received') {
        counters.received += 1
        return { data: { data: [] } }
      }
      counters.confirmed += 1
      return { data: { data: [pendingPurchaseOrder, fullyReceivedPurchaseOrder] } }
    })

    renderWithProviders(
      <>
        <GoodsReceiptListPage />
        <StockLevelsProbe onFetch={() => { counters.stock += 1 }} />
      </>,
      { queryClient },
    )

    await waitFor(() => {
      expect(counters.confirmed).toBe(2)
      expect(counters.received).toBe(1)
      expect(counters.stock).toBe(1)
    })
    queryClient.setQueryData(['purchase-orders', 'pending-receipt', 'tenant-B', 'company-1'], {
      marker: 'tenant-B-purchase-orders',
    })
    queryClient.setQueryData(['stock-levels', 'tenant-B', 'company-1'], {
      marker: 'tenant-B-stock',
    })

    fireEvent.click(await screen.findByText('inventory:goodsReceipt.receiveAll'))
    fireEvent.click(await screen.findByRole('button', { name: 'purchaseOrders.receive.saveAndPost' }))

    await waitFor(() => {
      expect(counters.confirmed).toBe(4)
      expect(counters.received).toBe(2)
      expect(counters.stock).toBe(2)
    })
    expect(queryClient.getQueryData(['purchase-orders', 'pending-receipt', 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B-purchase-orders',
    })
    expect(queryClient.getQueryData(['stock-levels', 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B-stock',
    })
  })
})
