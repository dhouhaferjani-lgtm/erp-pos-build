import { fireEvent, screen, waitFor } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '@/test/renderWithProviders'

import type {
  DuplicateSupplierInvoiceReferenceResult,
  OpenPurchaseOrderForSupplierInvoice,
  PurchaseOrderReceiptLine,
  PurchaseOrderForSupplierInvoice,
  SupplierInvoiceDetail,
} from './types'

const mutateAsync = vi.hoisted(() => vi.fn())
const uploadAttachmentMutateAsync = vi.hoisted(() => vi.fn())
const navigate = vi.hoisted(() => vi.fn())
const mockHasPermission = vi.hoisted(() => vi.fn(() => true))
const mockPolicyAllowsInvoiceFirst = vi.hoisted(() => ({ value: true }))
const productPickerProps = vi.hoisted(() => vi.fn())

const receiptLines = vi.hoisted<PurchaseOrderReceiptLine[]>(() => [
  {
    id: 'receipt-line-1',
    receipt_number: 'GRN-2026-0031',
    product_id: 'product-1',
    variant_id: null,
    received_qty: '10.0000',
    free_qty: '0.0000',
    quantity_invoiced: '4.0000',
    free_quantity_invoiced: '0.0000',
    accrual_unit_cost: '5.200000',
    received_unit_price: '5.200',
    po_line_id: 'po-line-1',
  },
])

const purchaseOrder = vi.hoisted<PurchaseOrderForSupplierInvoice>(() => ({
  id: 'po-1',
  document_number: 'BC-2026-0042',
  partner_id: 'supplier-1',
  partner_name: 'LaboDerm',
  currency: 'TND',
  lines: [
    {
      id: 'po-line-1',
      description: 'Crème solaire SPF50',
      product_id: 'product-1',
      product_name: 'Crème solaire SPF50',
      unit_price: '5.100',
      tax_rate: '19.00',
    },
  ],
}))

const duplicateResult = vi.hoisted<DuplicateSupplierInvoiceReferenceResult>(() => ({
  exists: true,
  invoice_number: 'SI-2026-0007',
}))

vi.mock('./api', () => ({
  useCreateSupplierInvoice: () => ({ mutateAsync, isPending: false }),
  useUploadAttachment: () => ({ mutateAsync: uploadAttachmentMutateAsync, isPending: false }),
  usePurchaseOrderForSupplierInvoice: () => ({ data: purchaseOrder, isLoading: false }),
  usePurchaseOrderReceiptLines: () => ({ data: receiptLines, isLoading: false }),
  usePurchaseOrdersForSupplierInvoice: () => ({ data: [purchaseOrder], isLoading: false }),
  usePurchaseOrderReceiptLinesForSupplierInvoice: () => ({ data: receiptLines, isLoading: false }),
  useOpenPurchaseOrdersForSupplier: () => ({
    data: [
      {
        id: 'po-1',
        document_number: 'BC-2026-0042',
        currency: 'TND',
        total: '61.880',
      } satisfies OpenPurchaseOrderForSupplierInvoice,
    ],
    isLoading: false,
  }),
  useDuplicateSupplierInvoiceReference: () => ({ data: duplicateResult, isFetching: false }),
}))

vi.mock('@/components/molecules/pickers', () => ({
  PartnerPicker: ({
    onChange,
  }: {
    onChange: (next: { id: string; name: string; type: 'supplier' }) => void
  }) => (
    <button
      type="button"
      data-testid="supplier-picker"
      onClick={() => { onChange({ id: 'supplier-1', name: 'LaboDerm', type: 'supplier' }) }}
    >
      supplier-picker
    </button>
  ),
  ProductPicker: ({
    onChange,
    testId,
    productType,
  }: {
    onChange: (next: { id: string; sku: string; name: string; requires_batch_tracking?: boolean }) => void
    testId?: string
    productType?: 'part' | 'consumable' | 'good' | 'all'
  }) => (
    <>
      {productPickerProps({ testId, productType })}
      <button
        type="button"
        data-testid={testId ?? 'product-picker'}
        onClick={() => {
          onChange({
            id: testId?.includes('1') === true ? 'product-manual-2' : 'product-manual-1',
            sku: testId?.includes('1') === true ? 'SKU-2' : 'SKU-1',
            name: testId?.includes('1') === true ? 'Second product' : 'First product',
            requires_batch_tracking: testId?.includes('batch') === true,
          })
        }}
      >
        product-picker
      </button>
    </>
  ),
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: vi.fn((url: string) => {
        if (url === '/procurement-policies') {
          return Promise.resolve({ data: { data: { allow_invoice_first: mockPolicyAllowsInvoiceFirst.value } } })
        }
        if (url === '/locations') {
          return Promise.resolve({ data: { data: [{ id: 'location-1', name: 'Main Warehouse' }] } })
        }
        return Promise.resolve({ data: { data: [] } })
      }),
    },
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => navigate,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, string>) => {
      if (params !== undefined) return `${key}:${JSON.stringify(params)}`
      return key
    },
  }),
}))

vi.mock('sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn(), warning: vi.fn() },
}))

let SupplierInvoiceCreatePage: React.ComponentType<Record<string, never>>

function getSaveButton(): HTMLButtonElement {
  return screen.getByRole('button', { name: 'purchases:supplierInvoices.create.saveDraft' })
}

async function findSaveButton(): Promise<HTMLButtonElement> {
  return screen.findByRole('button', { name: 'purchases:supplierInvoices.create.saveDraft' })
}

beforeEach(async () => {
  vi.clearAllMocks()
  mockHasPermission.mockReturnValue(true)
  mockPolicyAllowsInvoiceFirst.value = true
  receiptLines.splice(0, receiptLines.length, {
    id: 'receipt-line-1',
    receipt_number: 'GRN-2026-0031',
    product_id: 'product-1',
    variant_id: null,
    received_qty: '10.0000',
    free_qty: '0.0000',
    quantity_invoiced: '4.0000',
    free_quantity_invoiced: '0.0000',
    accrual_unit_cost: '5.200000',
    received_unit_price: '5.200',
    po_line_id: 'po-line-1',
  })
  Object.assign(purchaseOrder, {
    id: 'po-1',
    document_number: 'BC-2026-0042',
    partner_id: 'supplier-1',
    partner_name: 'LaboDerm',
    currency: 'TND',
    lines: [
      {
        id: 'po-line-1',
        description: 'Crème solaire SPF50',
        product_id: 'product-1',
        product_name: 'Crème solaire SPF50',
        unit_price: '5.100',
        tax_rate: '19.00',
      },
    ],
  } satisfies PurchaseOrderForSupplierInvoice)
  mutateAsync.mockResolvedValue({
    id: 'invoice-1',
    number: 'SI-2026-0008',
  } satisfies Partial<SupplierInvoiceDetail>)
  uploadAttachmentMutateAsync.mockResolvedValue({ id: 'attachment-1' })
  const mod = await import('./SupplierInvoiceCreatePage')
  SupplierInvoiceCreatePage = mod.SupplierInvoiceCreatePage
})

describe('SupplierInvoiceCreatePage', () => {
  it('renders canonical footer actions for cancel and save variants', async () => {
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new',
    })

    expect(screen.getByRole('button', { name: 'common:actions.cancel' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'purchases:supplierInvoices.create.saveDraft' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'actions.openSaveMenu' })).toBeInTheDocument()
  })

  it('renders a scan-instead link with the locked supplier_invoice kind when permitted', async () => {
    mockHasPermission.mockReturnValue(true)
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new',
    })

    expect(screen.getByRole('link', { name: 'documentIngestions:actions.scanInstead' })).toHaveAttribute(
      'href',
      '/purchases/scans/new?kind=supplier_invoice',
    )
  })

  it('submits a pending receipt invoice without source documents', async () => {
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new',
    })

    fireEvent.click(screen.getByTestId('supplier-picker'))
    fireEvent.click(await screen.findByTestId('invoice-first-pending'))
    fireEvent.click(screen.getByTestId('manual-line-product-picker-0'))
    fireEvent.change(screen.getByTestId('manual-line-quantity-0'), { target: { value: '3.0000' } })
    fireEvent.change(screen.getByTestId('manual-line-unit-price-0'), { target: { value: '8.125' } })
    fireEvent.change(screen.getByTestId('manual-line-vat-rate-0'), { target: { value: '0.00' } })

    fireEvent.click(getSaveButton())

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        partner_id: 'supplier-1',
        currency: 'TND',
        issue_date: new Date().toISOString().slice(0, 10),
        pending_receipt: true,
        lines: [
          {
            product_id: 'product-manual-1',
            quantity: '3.0000',
            unit_price: '8.125',
            vat_rate: '0.00',
          },
        ],
      })
    })
  })

  it('uses an all-products picker for manual supplier-invoice lines', async () => {
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new',
    })

    await waitFor(() => {
      expect(productPickerProps).toHaveBeenCalledWith(
        expect.objectContaining({
          testId: 'manual-line-product-picker-0',
          productType: 'all',
        }),
      )
    })
  })

  it('submits a delivered invoice-first payload with delivery metadata', async () => {
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new',
    })

    fireEvent.click(screen.getByTestId('supplier-picker'))
    fireEvent.click(await screen.findByTestId('invoice-first-delivered'))
    await screen.findByText('Main Warehouse')
    fireEvent.change(await screen.findByTestId('invoice-first-location-id'), { target: { value: 'location-1' } })
    fireEvent.change(screen.getByTestId('invoice-first-external-reference'), { target: { value: 'BL-7781' } })
    fireEvent.change(screen.getByTestId('invoice-first-external-date'), { target: { value: '2026-07-06' } })
    fireEvent.click(screen.getByTestId('manual-line-product-picker-0'))
    fireEvent.change(screen.getByTestId('manual-line-quantity-0'), { target: { value: '2.0000' } })
    fireEvent.change(screen.getByTestId('manual-line-unit-price-0'), { target: { value: '4.500' } })
    fireEvent.change(screen.getByTestId('manual-line-vat-rate-0'), { target: { value: '19.00' } })

    await waitFor(() => {
      expect(getSaveButton()).not.toBeDisabled()
    })
    fireEvent.click(getSaveButton())

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        partner_id: 'supplier-1',
        currency: 'TND',
        issue_date: new Date().toISOString().slice(0, 10),
        invoice_first_delivered: true,
        location_id: 'location-1',
        idempotency_key: expect.stringMatching(/^supplier-invoice-delivered-/),
        external_reference: 'BL-7781',
        external_date: '2026-07-06',
        lines: [
          {
            product_id: 'product-manual-1',
            quantity: '2.0000',
            unit_price: '4.500',
            vat_rate: '19.00',
          },
        ],
      })
    })
  })

  it('submits multiple invoice-first rows selected from product pickers', async () => {
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new',
    })

    fireEvent.click(screen.getByTestId('supplier-picker'))
    fireEvent.click(await screen.findByTestId('invoice-first-pending'))
    fireEvent.click(screen.getByTestId('manual-line-product-picker-0'))
    fireEvent.change(screen.getByTestId('manual-line-quantity-0'), { target: { value: '3.0000' } })
    fireEvent.change(screen.getByTestId('manual-line-unit-price-0'), { target: { value: '8.125' } })
    fireEvent.click(screen.getByTestId('add-manual-line'))
    fireEvent.click(screen.getByTestId('manual-line-product-picker-1'))
    fireEvent.change(screen.getByTestId('manual-line-quantity-1'), { target: { value: '4.0000' } })
    fireEvent.change(screen.getByTestId('manual-line-unit-price-1'), { target: { value: '9.125' } })

    fireEvent.click(getSaveButton())

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({
        pending_receipt: true,
        lines: [
          expect.objectContaining({ product_id: 'product-manual-1', quantity: '3.0000', unit_price: '8.125' }),
          expect.objectContaining({ product_id: 'product-manual-2', quantity: '4.0000', unit_price: '9.125' }),
        ],
      }))
    })
  })

  it('hides invoice-first fork when policy or permission is missing', async () => {
    mockPolicyAllowsInvoiceFirst.value = false
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new',
    })

    await waitFor(() => {
      expect(screen.queryByTestId('invoice-first-pending')).not.toBeInTheDocument()
      expect(screen.queryByTestId('invoice-first-delivered')).not.toBeInTheDocument()
    })
  })

  it('prefills matchable receipt quantity and submits a single-PO string payload', async () => {
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new?po=po-1',
    })

    expect(await screen.findByDisplayValue('6.0000')).toBeInTheDocument()
    expect(screen.getByDisplayValue('5.200')).toBeInTheDocument()
    expect(screen.getByText('purchases:supplierInvoices.create.match.matched')).toBeInTheDocument()

    fireEvent.change(screen.getByTestId('supplier-reference'), { target: { value: 'FA-8842' } })
    fireEvent.click(getSaveButton())

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        partner_id: 'supplier-1',
        source_document_id: 'po-1',
        source_document_ids: ['po-1'],
        currency: 'TND',
        issue_date: new Date().toISOString().slice(0, 10),
        supplier_reference: 'FA-8842',
        lines: [
          {
            source_line_id: 'po-line-1',
            quantity: '6.0000',
            unit_price: '5.200',
            vat_rate: '19.00',
          },
        ],
      })
    })
    expect(navigate).toHaveBeenCalledWith('/purchases/supplier-invoices/invoice-1')
  })

  it('submits source_document_ids for receipt-prefilled invoices spanning multiple POs', async () => {
    receiptLines.splice(0, receiptLines.length,
      {
        id: 'receipt-line-1',
        receipt_number: 'GRN-2026-0031',
        product_id: 'product-1',
        variant_id: null,
        received_qty: '10.0000',
        free_qty: '0.0000',
        quantity_invoiced: '4.0000',
        free_quantity_invoiced: '0.0000',
        accrual_unit_cost: '5.200000',
        received_unit_price: '5.200',
        po_line_id: 'po-line-1',
      },
      {
        id: 'receipt-line-2',
        receipt_number: 'GRN-2026-0032',
        product_id: 'product-2',
        variant_id: null,
        received_qty: '3.0000',
        free_qty: '0.0000',
        quantity_invoiced: '0.0000',
        free_quantity_invoiced: '0.0000',
        accrual_unit_cost: '7.000000',
        received_unit_price: '7.000',
        po_line_id: 'po-line-2',
      },
    )
    Object.assign(purchaseOrder, {
      ...purchaseOrder,
      lines: [
        ...purchaseOrder.lines,
        {
          id: 'po-line-2',
          description: 'Gel lavant',
          product_id: 'product-2',
          product_name: 'Gel lavant',
          unit_price: '7.000',
          tax_rate: '19.00',
        },
      ],
    } satisfies PurchaseOrderForSupplierInvoice)

    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new?po=po-1&po=po-2&entry=receipts',
    })

    expect(await screen.findByDisplayValue('6.0000')).toBeInTheDocument()
    expect(screen.getByDisplayValue('3.0000')).toBeInTheDocument()
    expect(screen.getByText('purchases:supplierInvoices.create.linkedPOs:{"numbers":"BC-2026-0042"}')).toBeInTheDocument()

    fireEvent.click(getSaveButton())

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({
        source_document_id: 'po-1',
        source_document_ids: ['po-1', 'po-2'],
        lines: [
          expect.objectContaining({ source_line_id: 'po-line-1', quantity: '6.0000' }),
          expect.objectContaining({ source_line_id: 'po-line-2', quantity: '3.0000' }),
        ],
      }))
    })
  })

  it('uploads selected attachments after creating the draft', async () => {
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new?po=po-1',
    })

    const file = new File(['invoice'], 'supplier-invoice.pdf', { type: 'application/pdf' })
    fireEvent.change(await screen.findByTestId('supplier-invoice-attachments'), {
      target: { files: [file] },
    })
    fireEvent.click(getSaveButton())

    await waitFor(() => {
      expect(uploadAttachmentMutateAsync).toHaveBeenCalledWith({ documentId: 'invoice-1', file })
    })
    expect(navigate).toHaveBeenCalledWith('/purchases/supplier-invoices/invoice-1')
  })

  it('navigates to the created draft and warns when attachment upload fails', async () => {
    uploadAttachmentMutateAsync.mockRejectedValueOnce(new Error('upload failed'))
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new?po=po-1',
    })

    const file = new File(['invoice'], 'supplier-invoice.pdf', { type: 'application/pdf' })
    fireEvent.change(await screen.findByTestId('supplier-invoice-attachments'), {
      target: { files: [file] },
    })
    fireEvent.click(getSaveButton())

    await waitFor(() => {
      expect(navigate).toHaveBeenCalledWith('/purchases/supplier-invoices/invoice-1')
    })
    expect(toast.warning).toHaveBeenCalledWith('purchases:supplierInvoices.create.attachmentUploadFailed')
    expect(toast.error).not.toHaveBeenCalled()
  })

  it('shows a non-blocking duplicate supplier-reference warning after blur', async () => {
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new?po=po-1',
    })

    const reference = await screen.findByTestId('supplier-reference')
    fireEvent.change(reference, { target: { value: 'FA-8842' } })
    fireEvent.blur(reference)

    expect(await screen.findByText('purchases:supplierInvoices.create.duplicateWarning:{"number":"SI-2026-0007"}')).toBeInTheDocument()
  })

  it('omits receipt rows with no paid uninvoiced quantity from prefill and payload', async () => {
    receiptLines.push({
      id: 'receipt-line-free-only',
      receipt_number: 'GRN-2026-0032',
      product_id: 'product-1',
      variant_id: null,
      received_qty: '2.0000',
      free_qty: '3.0000',
      quantity_invoiced: '2.0000',
      free_quantity_invoiced: '0.0000',
      accrual_unit_cost: '5.200000',
      received_unit_price: '5.200',
      po_line_id: 'po-line-1',
    })

    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new?po=po-1',
    })

    expect(await screen.findByDisplayValue('6.0000')).toBeInTheDocument()
    expect(screen.queryByDisplayValue('0.0000')).not.toBeInTheDocument()

    fireEvent.click(getSaveButton())

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({
        lines: [
          expect.objectContaining({
            quantity: '6.0000',
          }),
        ],
      }))
    })
  })

  it('uses accrual cost as the match-preview basis while keeping supplier price editable', async () => {
    receiptLines[0] = {
      ...receiptLines[0],
      accrual_unit_cost: '5.450000',
      received_unit_price: '5.200',
    }

    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new?po=po-1',
    })

    expect(await screen.findByDisplayValue('5.200')).toBeInTheDocument()
    expect(screen.getByText('purchases:supplierInvoices.create.match.priceVariance')).toBeInTheDocument()
  })

  it('prefills multiple receipt lines for one PO line with each receipt price and accrual basis', async () => {
    receiptLines.splice(0, receiptLines.length,
      {
        id: 'receipt-line-1',
        receipt_number: 'GRN-2026-0031',
        product_id: 'product-1',
        variant_id: null,
        received_qty: '10.0000',
        free_qty: '0.0000',
        quantity_invoiced: '0.0000',
        free_quantity_invoiced: '0.0000',
        accrual_unit_cost: '12.500000',
        received_unit_price: '12.500',
        po_line_id: 'po-line-1',
      },
      {
        id: 'receipt-line-2',
        receipt_number: 'GRN-2026-0032',
        product_id: 'product-1',
        variant_id: null,
        received_qty: '5.0000',
        free_qty: '0.0000',
        quantity_invoiced: '0.0000',
        free_quantity_invoiced: '0.0000',
        accrual_unit_cost: '12.800000',
        received_unit_price: '12.800',
        po_line_id: 'po-line-1',
      },
    )

    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new?po=po-1',
    })

    expect(await screen.findByDisplayValue('10.0000')).toBeInTheDocument()
    expect(screen.getByDisplayValue('5.0000')).toBeInTheDocument()
    expect(screen.getByDisplayValue('12.500')).toBeInTheDocument()
    expect(screen.getByDisplayValue('12.800')).toBeInTheDocument()
    expect(screen.getAllByText('purchases:supplierInvoices.create.match.matched')).toHaveLength(2)
  })

  it('surfaces create validation failures via toast', async () => {
    mutateAsync.mockRejectedValueOnce(new Error('Validation failed'))
    renderWithProviders(<SupplierInvoiceCreatePage />, {
      route: '/purchases/supplier-invoices/new?po=po-1',
    })

    fireEvent.click(await findSaveButton())

    await waitFor(() => {
      expect(toast.error).toHaveBeenCalledWith('Validation failed')
    })
    expect(navigate).not.toHaveBeenCalled()
  })
})
