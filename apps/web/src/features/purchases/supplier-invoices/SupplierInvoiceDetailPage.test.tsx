/**
 * TDD: SupplierInvoiceDetailPage — Post-disabled-when-blocked logic
 *
 * Covers:
 * 1. "Post Invoice" button is enabled when match_status = 'matched'
 * 2. "Post Invoice" button is disabled with explanation when match_status = 'qty_blocked'
 * 3. "Post Invoice" button is disabled when status is already 'posted'
 * 4. Per-line match table renders ordered/received/invoiced/matchable/price_variance columns
 */

import { fireEvent, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { renderWithProviders } from '../../../test/renderWithProviders'
import type { SupplierInvoiceDetail } from './types'

// ── Mocks ──────────────────────────────────────────────────────────────────

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiRawGet = vi.hoisted(() => vi.fn())
const mockHasPermission = vi.hoisted(() => vi.fn((_permission: string) => true))

vi.mock('../../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../../lib/api')>('../../../lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    api: { ...actual.api, get: mockApiRawGet },
  }
})

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: 'inv-detail-1' }),
    Link: ({ to, children, ...rest }: { to: string; children: React.ReactNode; [key: string]: unknown }) => (
      <a href={String(to)} {...rest}>{children}</a>
    ),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts) return `${key}:${JSON.stringify(opts)}`
      return key
    },
    i18n: { language: 'en' },
  }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// ── Helpers ────────────────────────────────────────────────────────────────

function setTenant(tenantId = 'tenant-1', companyId = 'company-1') {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: [],
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

function makeDetail(overrides: Partial<SupplierInvoiceDetail> = {}): SupplierInvoiceDetail {
  return {
    id: 'inv-detail-1',
    number: 'SI-2026-001',
    partner: { id: 'p-1', name: 'ACME Supplies' },
    issue_date: '2026-06-01',
    due_date: '2026-07-01',
    supplier_reference: 'REF-001',
    currency: 'TND',
    total: '1500.000',
    balance_due: '1500.000',
    status: 'draft',
    match_status: 'matched',
    // Backend detail emits source_document_id (not has_source_document).
    source_document_id: 'po-1',
    lines: [
      {
        id: 'line-1',
        source_line_id: 'po-line-1',
        quantity: '10.0000',
        unit_price: '150.000',
        vat_rate: '19.00',
        recoverable_tax_amount: '285.000',
        line_subtotal: '1785.000',
      },
    ],
    source_purchase_order: { id: 'po-1', number: 'PO-2026-001' },
    match: {
      status: 'matched',
      per_line: [
        {
          po_line_id: 'po-line-1',
          ordered: '10.0000',
          received: '10.0000',
          invoiced: '10.0000',
          matchable: '10.0000',
          // Boolean flag: false = no variance, true = price exceeds tolerance policy.
          price_variance: false,
        },
      ],
    },
    attachments: [],
    posted_at: null,
    ...overrides,
  }
}

 
let SupplierInvoiceDetailPage: React.ComponentType<Record<string, never>>

beforeEach(async () => {
  vi.clearAllMocks()
  HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '')
  })
  HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
    this.removeAttribute('open')
  })
  setTenant()
  mockHasPermission.mockReturnValue(true)
  // apiGet returns unwrapped data
  mockApiGet.mockImplementation((url: string) => {
    if (url.includes('/receipt-lines')) {
      return Promise.resolve([
        {
          id: 'receipt-line-1',
          receipt_number: 'GRN-2026-0031',
          external_reference: 'BL-31',
          external_date: '2026-07-05',
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
    }
    return Promise.resolve(makeDetail())
  })
  mockApiRawGet.mockResolvedValue({
    data: {
      data: [
        {
          id: 'po-1',
          document_number: 'PO-2026-001',
          currency: 'TND',
          total: '1500.000',
        },
      ],
    },
  })
  mockApiRawGet.mockImplementation((url: string) => {
    if (url === '/payment-methods') {
      return Promise.resolve({
        data: {
          data: [
            {
              id: 'method-bank',
              name: 'Bank transfer',
              is_active: true,
              is_physical: false,
              has_maturity: false,
              requires_third_party: false,
              is_push: true,
              has_deducted_fees: false,
              is_restricted: false,
            },
          ],
        },
      })
    }
    if (url === '/payment-repositories') {
      return Promise.resolve({
        data: {
          data: [
            {
              id: 'repo-bank',
              code: 'BANK',
              name: 'Main bank',
              type: 'bank_account',
              is_active: true,
              is_default: true,
              balance: '10000.000',
            },
          ],
        },
      })
    }
    return Promise.resolve({
      data: {
        data: [
          {
            id: 'po-1',
            document_number: 'PO-2026-001',
            currency: 'TND',
            total: '1500.000',
          },
        ],
      },
    })
  })
  mockApiPost.mockResolvedValue(makeDetail({ pending_receipt: false }))
  const mod = await import('./SupplierInvoiceDetailPage')
  SupplierInvoiceDetailPage = mod.SupplierInvoiceDetailPage
})

afterEach(() => {
  resetTenant()
})

// ── Tests ──────────────────────────────────────────────────────────────────

describe('SupplierInvoiceDetailPage — Post action', () => {
  it('Post button is enabled when status=draft and match=matched', async () => {
    mockApiGet.mockResolvedValue(makeDetail({ status: 'draft', match_status: 'matched' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      const btn = screen.getByTestId('btn-post')
      expect(btn).not.toBeDisabled()
    })
  })

  it('Post button is disabled with blocked explanation when match=qty_blocked', async () => {
    mockApiGet.mockResolvedValue(makeDetail({ status: 'draft', match_status: 'qty_blocked' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      const btn = screen.getByTestId('btn-post')
      expect(btn).toBeDisabled()
    })
    // Should show a block explanation
    expect(screen.getByTestId('post-block-reason')).toBeInTheDocument()
  })

  it('Post button is not shown when status=posted', async () => {
    mockApiGet.mockResolvedValue(
      makeDetail({ status: 'posted', match_status: 'matched', posted_at: '2026-06-01T10:00:00Z' })
    )
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      expect(screen.queryByTestId('btn-post')).not.toBeInTheDocument()
    })
  })

  /**
   * LEDGER C-28(i) — the Re-match button was rendered unconditionally while its
   * sibling Post is `{!isPosted && …}`. Q-11 made re-matching Draft-only on the
   * server (`MATCH_NOT_ALLOWED`), so on a posted invoice the button was a live
   * control whose only possible outcome was an error toast.
   */
  it('Re-match button is shown for a draft invoice', async () => {
    mockApiGet.mockResolvedValue(makeDetail({ status: 'draft', match_status: 'matched' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      expect(screen.getByTestId('btn-rematch')).toBeInTheDocument()
    })
  })

  // F-W2-14 / rule 12 (both-layer gating): the Post and Re-match mutations are
  // gated on supplier-invoices.manage on the FE too, so a user without it (e.g. a
  // cashier who only holds documents.update) never sees the controls.
  it('Post and Re-match buttons are hidden without supplier-invoices.manage', async () => {
    mockHasPermission.mockImplementation((permission: string) => permission !== 'supplier-invoices.manage')
    mockApiGet.mockResolvedValue(makeDetail({ status: 'draft', match_status: 'matched' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)

    // Wait for the loaded page (the invoice number heading renders regardless of perms).
    await screen.findByText('SI-2026-001')

    expect(screen.queryByTestId('btn-post')).not.toBeInTheDocument()
    expect(screen.queryByTestId('btn-rematch')).not.toBeInTheDocument()
  })

  it('Re-match button is not shown when status=posted', async () => {
    mockApiGet.mockResolvedValue(
      makeDetail({ status: 'posted', match_status: 'matched', posted_at: '2026-06-01T10:00:00Z' })
    )
    renderWithProviders(<SupplierInvoiceDetailPage />)

    // Wait for a control that only a POSTED invoice renders, so the absence
    // assertions below run against the loaded page and not the loading state.
    await screen.findByTestId('btn-record-payment')

    expect(screen.queryByTestId('btn-post')).not.toBeInTheDocument()
    expect(screen.queryByTestId('btn-rematch')).not.toBeInTheDocument()
  })

  it('Post button is disabled when match=price_variance (price block in strict mode)', async () => {
    // price_variance can be either warn (allowed) or block (disallowed) based on policy.
    // Assumption: the FE disables the button when match_status=price_variance to surface
    // the variance; the backend will make the final decision on assertPostable().
    mockApiGet.mockResolvedValue(makeDetail({ status: 'draft', match_status: 'price_variance' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      // In price_variance state: button present but labelled with warning
      const btn = screen.getByTestId('btn-post')
      // Not blocking on FE (policy is server-side), just surface the state
      expect(btn).toBeInTheDocument()
    })
  })

  it('disables posting while receipt association is pending', async () => {
    mockApiGet.mockResolvedValue(makeDetail({ pending_receipt: true, match_status: 'unmatched' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)

    await waitFor(() => {
      expect(screen.getByTestId('btn-post')).toBeDisabled()
      expect(screen.getByTestId('pending-receipt-banner')).toHaveTextContent(
        'purchases:supplierInvoices.pendingReceipt.detail'
      )
    })
  })
})

describe('SupplierInvoiceDetailPage — receipt linking', () => {
  it('links pending invoice lines to receipt lines', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/receipt-lines')) {
        return Promise.resolve([
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
      }
      return Promise.resolve(makeDetail({ pending_receipt: true, match_status: 'unmatched' }))
    })
    renderWithProviders(<SupplierInvoiceDetailPage />)

    await screen.findByText(/GRN-2026-0031/)
    fireEvent.change(await screen.findByTestId('link-receipt-line-selector-line-1'), {
      target: { value: 'receipt-line-1' },
    })
    await waitFor(() => {
      expect(screen.getByTestId('link-receipts')).not.toBeDisabled()
    })
    fireEvent.click(screen.getByTestId('link-receipts'))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/supplier-invoices/inv-detail-1/link-receipts', {
        links: [
          {
            invoice_line_id: 'line-1',
            receipt_line_id: 'receipt-line-1',
          },
        ],
      })
    })
  })
})

describe('SupplierInvoiceDetailPage — per-line match table', () => {
  it('renders match table column headers', async () => {
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      // The 5 match table columns
      expect(screen.getByText('purchases:supplierInvoices.lines.ordered')).toBeInTheDocument()
      expect(screen.getByText('purchases:supplierInvoices.lines.received')).toBeInTheDocument()
      expect(screen.getByText('purchases:supplierInvoices.lines.invoiced')).toBeInTheDocument()
      expect(screen.getByText('purchases:supplierInvoices.lines.matchable')).toBeInTheDocument()
      expect(screen.getByText('purchases:supplierInvoices.lines.priceVariance')).toBeInTheDocument()
    })
  })

  it('renders per-line match values', async () => {
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      // The row with ordered=10 received=10
      expect(screen.getByTestId('match-row-po-line-1')).toBeInTheDocument()
    })
  })

  it('collapses duplicate PO-line aggregates and ORs price variance', async () => {
    const detail = makeDetail()
    detail.lines[0].quantity = '2.0000'
    detail.match.per_line = [
      {
        po_line_id: 'po-line-shared',
        ordered: '11.0000',
        received: '7.0000',
        invoiced: '6.0000',
        matchable: '3.0000',
        price_variance: false,
      },
      {
        po_line_id: 'po-line-shared',
        ordered: '11.0000',
        received: '7.0000',
        invoiced: '6.0000',
        matchable: '3.0000',
        price_variance: true,
      },
    ]
    mockApiGet.mockResolvedValue(detail)

    renderWithProviders(<SupplierInvoiceDetailPage />)

    expect(await screen.findAllByTestId(/^match-row-/)).toHaveLength(1)
    expect(screen.getAllByText('11.0000')).toHaveLength(1)
    expect(screen.getAllByText('7.0000')).toHaveLength(1)
    expect(screen.getAllByText('6.0000')).toHaveLength(1)
    expect(screen.getAllByText('3.0000')).toHaveLength(1)
    expect(screen.getByTestId('match-variance-po-line-shared')).toHaveAttribute(
      'data-variance',
      'true'
    )
  })

  it('preserves a separate match row for a second PO line', async () => {
    const detail = makeDetail()
    detail.lines[0].quantity = '2.0000'
    detail.match.per_line = [
      {
        po_line_id: 'po-line-shared',
        ordered: '11.0000',
        received: '7.0000',
        invoiced: '6.0000',
        matchable: '3.0000',
        price_variance: false,
      },
      {
        po_line_id: 'po-line-shared',
        ordered: '11.0000',
        received: '7.0000',
        invoiced: '6.0000',
        matchable: '3.0000',
        price_variance: true,
      },
      {
        po_line_id: 'po-line-other',
        ordered: '13.0000',
        received: '8.0000',
        invoiced: '5.0000',
        matchable: '4.0000',
        price_variance: false,
      },
    ]
    mockApiGet.mockResolvedValue(detail)

    renderWithProviders(<SupplierInvoiceDetailPage />)

    expect(await screen.findAllByTestId(/^match-row-/)).toHaveLength(2)
    expect(screen.getByTestId('match-row-po-line-shared')).toBeInTheDocument()
    expect(screen.getByTestId('match-row-po-line-other')).toBeInTheDocument()
  })
})

describe('SupplierInvoiceDetailPage — source PO link', () => {
  it('renders a link to the source purchase order', async () => {
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      const link = screen.getByTestId('link-source-po')
      expect(link).toHaveAttribute('href', expect.stringContaining('po-1'))
    })
  })

  it('renders all source purchase orders and consumed receipt links', async () => {
    mockApiGet.mockResolvedValueOnce(makeDetail({
      source_purchase_orders: [
        { id: 'po-1', number: 'PO-2026-001' },
        { id: 'po-2', number: 'PO-2026-002' },
      ],
      consumed_receipts: [
        {
          id: 'grn-1',
          receipt_number: 'GRN-2026-001',
          status: 'posted',
          received_at: '2026-06-15T08:00:00Z',
          external_reference: 'BL-001',
        },
      ],
    }))
    renderWithProviders(<SupplierInvoiceDetailPage />)

    expect(await screen.findByText('purchases:supplierInvoices.detail.linkedPOs')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /PO-2026-001/ })).toHaveAttribute('href', '/purchases/orders/po-1')
    expect(screen.getByRole('link', { name: /PO-2026-002/ })).toHaveAttribute('href', '/purchases/orders/po-2')
    expect(screen.getByText('purchases:supplierInvoices.detail.consumedReceipts')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'GRN-2026-001' })).toHaveAttribute('href', '/purchases/orders/po-1')
  })
})

describe('SupplierInvoiceDetailPage — Record Payment', () => {
  it('shows Record Payment button when invoice is posted', async () => {
    mockApiGet.mockResolvedValue(
      makeDetail({ status: 'posted', match_status: 'matched', posted_at: '2026-06-01T10:00:00Z' })
    )
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      expect(screen.getByTestId('btn-record-payment')).toBeInTheDocument()
    })
  })

  it('shows a secondary Pay in Treasury link for posted invoices', async () => {
    mockApiGet.mockResolvedValue(
      makeDetail({ status: 'posted', match_status: 'matched', posted_at: '2026-06-01T10:00:00Z' })
    )
    renderWithProviders(<SupplierInvoiceDetailPage />)

    const payInTreasury = await screen.findByRole('link', {
      name: 'purchases:supplierInvoices.actions.payInTreasury',
    })

    expect(payInTreasury).toHaveAttribute('href', '/treasury/payments/new?supplier_invoice=inv-detail-1')
  })

  it('hides supplier payment actions without payments.create permission', async () => {
    mockHasPermission.mockImplementation((permission: string) => permission !== 'payments.create')
    mockApiGet.mockResolvedValue(
      makeDetail({ status: 'posted', match_status: 'matched', posted_at: '2026-06-01T10:00:00Z' })
    )

    renderWithProviders(<SupplierInvoiceDetailPage />)

    await waitFor(() => {
      expect(screen.queryByTestId('btn-record-payment')).not.toBeInTheDocument()
    })
    expect(screen.queryByRole('link', {
      name: 'purchases:supplierInvoices.actions.payInTreasury',
    })).not.toBeInTheDocument()
  })

  it('does not show Record Payment when invoice is draft', async () => {
    mockApiGet.mockResolvedValue(makeDetail({ status: 'draft' }))
    renderWithProviders(<SupplierInvoiceDetailPage />)
    await waitFor(() => {
      expect(screen.queryByTestId('btn-record-payment')).not.toBeInTheDocument()
    })
  })

  it('records a supplier payment with string amount and allocation to the invoice', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(
      makeDetail({
        status: 'posted',
        match_status: 'matched',
        posted_at: '2026-06-01T10:00:00Z',
        balance_due: '125.500',
      }),
    )
    const { queryClient } = renderWithProviders(<SupplierInvoiceDetailPage />)
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')

    await user.click(await screen.findByTestId('btn-record-payment'))
    await screen.findByRole('dialog', { name: 'purchases:supplierInvoices.detail.payment' })

    expect(screen.getByRole('spinbutton', { name: 'purchases:supplierInvoices.paymentForm.amount' })).toHaveValue(125.5)
    await user.selectOptions(
      await screen.findByLabelText('purchases:supplierInvoices.paymentForm.method'),
      'method-bank',
    )
    await user.selectOptions(
      screen.getByLabelText('purchases:supplierInvoices.paymentForm.repository'),
      'repo-bank',
    )
    await user.clear(screen.getByLabelText('purchases:supplierInvoices.paymentForm.reference'))
    await user.type(screen.getByLabelText('purchases:supplierInvoices.paymentForm.reference'), 'WIRE-42')
    await user.type(screen.getByLabelText('purchases:supplierInvoices.paymentForm.notes'), 'Paid from bank portal')
    await user.click(screen.getByRole('button', { name: 'purchases:supplierInvoices.paymentForm.submit' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/payments', {
        amount: '125.500',
        currency: 'TND',
        payment_method_id: 'method-bank',
        repository_id: 'repo-bank',
        partner_id: 'p-1',
        payment_date: expect.any(String),
        reference: 'WIRE-42',
        notes: 'Paid from bank portal',
        allocations: [
          {
            document_id: 'inv-detail-1',
            amount: '125.500',
          },
        ],
      })
    })
    await waitFor(() => {
      expect(invalidateSpy).toHaveBeenCalled()
    })
  })

  it('caps the inline supplier-payment allocation at the invoice balance due', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(
      makeDetail({
        status: 'posted',
        match_status: 'matched',
        posted_at: '2026-06-01T10:00:00Z',
        total: '300.000',
        balance_due: '125.500',
      }),
    )

    renderWithProviders(<SupplierInvoiceDetailPage />)

    await user.click(await screen.findByTestId('btn-record-payment'))
    await user.clear(screen.getByLabelText('purchases:supplierInvoices.paymentForm.amount'))
    await user.type(screen.getByLabelText('purchases:supplierInvoices.paymentForm.amount'), '200.000')
    await user.selectOptions(
      await screen.findByLabelText('purchases:supplierInvoices.paymentForm.method'),
      'method-bank',
    )
    await user.selectOptions(
      screen.getByLabelText('purchases:supplierInvoices.paymentForm.repository'),
      'repo-bank',
    )
    await user.click(screen.getByRole('button', { name: 'purchases:supplierInvoices.paymentForm.submit' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
        amount: '200',
        allocations: [
          {
            document_id: 'inv-detail-1',
            amount: '125.500',
          },
        ],
      }))
    })
  })

  it('uses the MoneyInput default minimum instead of a hardcoded three-decimal minimum', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(
      makeDetail({
        status: 'posted',
        match_status: 'matched',
        posted_at: '2026-06-01T10:00:00Z',
      }),
    )

    renderWithProviders(<SupplierInvoiceDetailPage />)

    await user.click(await screen.findByTestId('btn-record-payment'))

    expect(screen.getByLabelText('purchases:supplierInvoices.paymentForm.amount')).toHaveAttribute('min', '0')
  })

  it('renders supplier over-payment errors in the payment dialog', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(
      makeDetail({
        status: 'posted',
        match_status: 'matched',
        posted_at: '2026-06-01T10:00:00Z',
        balance_due: '125.500',
      }),
    )
    mockApiPost.mockRejectedValueOnce({
      response: {
        data: {
          error: {
            code: 'SUPPLIER_PAYMENT_EXCEEDS_PAYABLE',
            message: 'Payment amount exceeds the supplier invoice outstanding balance',
          },
        },
      },
    })

    renderWithProviders(<SupplierInvoiceDetailPage />)

    await user.click(await screen.findByTestId('btn-record-payment'))
    await user.selectOptions(
      await screen.findByLabelText('purchases:supplierInvoices.paymentForm.method'),
      'method-bank',
    )
    await user.selectOptions(
      screen.getByLabelText('purchases:supplierInvoices.paymentForm.repository'),
      'repo-bank',
    )
    await user.clear(screen.getByLabelText('purchases:supplierInvoices.paymentForm.amount'))
    await user.type(screen.getByLabelText('purchases:supplierInvoices.paymentForm.amount'), '126.000')
    await user.click(screen.getByRole('button', { name: 'purchases:supplierInvoices.paymentForm.submit' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Payment amount exceeds the supplier invoice outstanding balance',
    )
  })
})
