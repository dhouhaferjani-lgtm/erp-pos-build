import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import i18n from '@/lib/i18n'
import { renderWithProviders } from '@/test/renderWithProviders'
import { ReceiptDetailPage } from './ReceiptDetailPage'
import { downloadReceipt, getReceipt, printReceipt, type ReceiptDetail } from '../../api/receiptApi'

vi.mock('../../api/receiptApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../api/receiptApi')>()
  return {
    ...actual,
    getReceipt: vi.fn(),
    printReceipt: vi.fn(),
    downloadReceipt: vi.fn(),
  }
})

vi.mock('../../hooks/usePosTenantScope', () => ({
  usePosTenantScope: () => ({ tenantId: 'tenant-1', companyId: 'company-1', hasTenantScope: true }),
}))

const companyState = vi.hoisted(() => ({
  currentCompanyId: 'company-1' as string | null,
  companies: [{ id: 'company-1', timezone: 'Africa/Tunis' }],
}))

vi.mock('@/stores/companyStore', () => ({
  useCompanyStore: Object.assign(
    (selector: (state: { currentCompanyId: string | null; companies: { id: string; timezone: string }[] }) => unknown) => selector(companyState),
    { getState: () => companyState },
  ),
}))

const detail: ReceiptDetail = {
  id: 'receipt-1',
  receipt_number: 'TN-POS-0001',
  posted_at: '2026-08-17T10:30:00+00:00',
  invoice_type_code: 'SALE',
  training_flag: false,
  receipt_type: 'sale',
  fiscal_status: 'fiscalized',
  location_id: 'location-1',
  location_name: 'Tunis Centre',
  terminal_id: 'terminal-1',
  terminal_code: 'POS-1',
  cashier_id: 'cashier-1',
  cashier_name: 'Amina',
  currency: 'TND',
  subtotal: '10.000',
  tax_amount: '1.900',
  discount_amount: '0.000',
  cash_rounding_adjustment: '0.000',
  cash_rounding_denomination: '0.0500',
  change_due: '2.000',
  total: '11.900',
  notes: null,
  is_voided: false,
  voided_at: null,
  fiscal_hash: 'abc123',
  previous_hash: null,
  chain_sequence: 7,
  receipt_year: 2026,
  fiscal_event_id: 'event-1',
  synced_at: '2026-08-17T10:31:00Z',
  sync_error: null,
  refund_policy_alerts: [],
  lines: [{
    id: 'line-1',
    line_number: 1,
    product_id: null,
    product_name: 'Archived olive oil',
    product_code: 'SNAP-OLIVE',
    quantity: '1.2500',
    quantity_decimals: 3,
    unit_price: '11.900',
    discount_amount: '0.000',
    vat_rate: '19.00',
    vat_amount: '1.900',
    line_total: '10.000',
    returned_quantity: '0.000',
  }],
  vat_details: [{ tax_rate: '19.00', net_amount: '10.000', vat_amount: '1.900', gross_amount: '11.900' }],
  payments: [{
    id: 'payment-1',
    payment_method: 'Cash',
    amount: '13.900',
    card_last_four: '4242',
    instrument_serial: 'INST-001',
    transaction_reference: 'TX-001',
    authorization_code: 'AUTH-001',
  }],
  return_receipts: [{
    id: 'refund-1',
    receipt_number: 'TN-REF-0001',
    posted_at: '2026-08-18T09:00:00Z',
    invoice_type_code: 'REFUND',
    total: '4.000',
    currency: 'TND',
    refund_reason: 'customer return',
    refund_reason_source: 'canonical',
    refund_destination: 'cash',
  }],
  original_receipt_id: null,
  original_receipt: null,
}

function renderDetail(receipt: ReceiptDetail = detail) {
  vi.mocked(getReceipt).mockResolvedValue(receipt)
  return renderWithProviders(
    <Routes>
      <Route path="/pos/receipts/:id" element={<ReceiptDetailPage />} />
    </Routes>,
    { route: `/pos/receipts/${receipt.id}` },
  )
}

describe('ReceiptDetailPage', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
    vi.clearAllMocks()
    vi.mocked(printReceipt).mockResolvedValue(new Blob(['pdf'], { type: 'application/pdf' }))
    vi.mocked(downloadReceipt).mockResolvedValue()
    companyState.currentCompanyId = 'company-1'
    companyState.companies = [{ id: 'company-1', timezone: 'Africa/Tunis' }]
    Object.defineProperty(window.URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:receipt') })
    Object.defineProperty(window.URL, 'revokeObjectURL', { configurable: true, value: vi.fn() })
  })

  it('renders snapshot lines at their quantity precision without a unit label or PDF side effect', async () => {
    renderDetail()

    expect(await screen.findByRole('heading', { name: 'TN-POS-0001' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'TN-POS-0001' }).nextElementSibling).toHaveTextContent(/11[.,]900/)
    expect(screen.queryByText('Sale')).not.toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Unit price (incl. VAT)' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Line net' })).toBeInTheDocument()
    expect(screen.getByText('SNAP-OLIVE')).toBeInTheDocument()
    expect(screen.getByText('Archived olive oil')).toBeInTheDocument()
    expect(screen.getByText('1.250')).toBeInTheDocument()
    expect(screen.queryByText('pc')).not.toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Card last four' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Instrument serial' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Authorization code' })).toBeInTheDocument()
    expect(screen.getByText('4242')).toBeVisible()
    expect(screen.getByText('INST-001')).toBeVisible()
    expect(screen.getByText('AUTH-001')).toBeVisible()
    expect(printReceipt).not.toHaveBeenCalled()
    expect(downloadReceipt).not.toHaveBeenCalled()
  })

  it('labels a legacy-voided receipt on the detail surface', async () => {
    renderDetail({ ...detail, is_voided: true })

    expect(await screen.findByText('Voided')).toBeVisible()
  })

  it('waits for the active company before requesting or rendering fiscal timestamps', () => {
    companyState.companies = []

    renderDetail()

    expect(getReceipt).not.toHaveBeenCalled()
    expect(screen.queryByRole('heading', { name: 'TN-POS-0001' })).not.toBeInTheDocument()
  })

  it('requires confirmation for every print and issues exactly one request after confirmation', async () => {
    renderDetail()
    await screen.findByRole('heading', { name: 'TN-POS-0001' })

    fireEvent.click(screen.getByRole('button', { name: 'Print duplicate' }))
    expect(screen.getByText(/recorded in the fiscal reprint log/i)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(printReceipt).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Print duplicate' }))
    fireEvent.click(screen.getByTestId('confirm-dialog-confirm'))
    await waitFor(() => { expect(printReceipt).toHaveBeenCalledTimes(1) })
  })

  it('links an original receipt to its refunds and a refund back to its original', async () => {
    const { unmount } = renderDetail()
    const refundLink = await screen.findByRole('link', { name: 'TN-REF-0001' })
    expect(refundLink).toHaveAttribute('href', '/pos/receipts/refund-1')
    expect(refundLink.nextElementSibling).toHaveTextContent(/08\/18\/2026/)
    expect(screen.getByText('Reason recorded in the fiscal event')).toBeVisible()
    unmount()

    renderDetail({
      ...detail,
      id: 'refund-1',
      receipt_number: 'TN-REF-0001',
      invoice_type_code: 'REFUND',
      return_receipts: [],
      original_receipt_id: 'receipt-1',
      original_receipt: {
        id: 'receipt-1',
        receipt_number: 'TN-POS-0001',
        posted_at: detail.posted_at,
        total: detail.total,
        currency: detail.currency,
      },
    })
    const originalLink = await screen.findByRole('link', { name: 'TN-POS-0001' })
    expect(originalLink).toHaveAttribute('href', '/pos/receipts/receipt-1')
    expect(originalLink.nextElementSibling).toHaveTextContent(/08\/17\/2026/)
    expect(screen.getByText('Refund')).toBeVisible()
  })
})
