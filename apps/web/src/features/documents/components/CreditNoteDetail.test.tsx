/**
 * CreditNoteDetail Component Tests
 * TDD: Tests written FIRST before implementation
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CreditNoteDetail } from './CreditNoteDetail'
import { CreditNoteReason, DocumentStatus, type CreditNote } from '@/types/creditNote'

// Mock the translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const translations: Record<string, string> = {
        'sales:creditNotes.detail.title': 'Credit Note Details',
        'sales:creditNotes.number': 'Number',
        'sales:creditNotes.date': 'Date',
        'sales:creditNotes.sourceInvoice': 'Source Invoice',
        'sales:creditNotes.amount': 'Amount',
        'sales:creditNotes.reasonLabel': 'Reason',
        'sales:creditNotes.notes': 'Notes',
        'sales:creditNotes.statusLabel': 'Status',
        'sales:creditNotes.createdAt': 'Created',
        'sales:creditNotes.updatedAt': 'Last Updated',
        'sales:creditNotes.print': 'Print',
        'sales:creditNotes.viewInvoice': 'View Source Invoice',
        'sales:creditNotes.reasons.return': 'Product Return',
        'sales:creditNotes.reasons.price_adjustment': 'Price Adjustment',
        'sales:creditNotes.reasons.billing_error': 'Billing Error',
        'sales:creditNotes.reasons.damaged_goods': 'Damaged Goods',
        'sales:creditNotes.reasons.service_issue': 'Service Issue',
        'sales:creditNotes.reasons.other': 'Other',
        'common:status.draft': 'Draft',
        'common:status.posted': 'Posted',
        'common:status.loading': 'Loading...',
        'common:actions.close': 'Close',
      }
      let result = translations[key] || key
      if (params) {
        Object.entries(params).forEach(([k, v]) => {
          result = result.replace(`{{${k}}}`, String(v))
        })
      }
      return result
    },
  }),
}))

const createQueryClient = () =>
  new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

const wrapper = ({ children }: { children: React.ReactNode }) => (
  <QueryClientProvider client={createQueryClient()}>{children}</QueryClientProvider>
)

describe('CreditNoteDetail', () => {
  const mockCreditNote: CreditNote = {
    id: '1',
    document_number: 'CN-00001',
    source_invoice_id: 'inv-1',
    source_invoice_number: 'INV-00001',
    partner: { id: 'partner-1', name: 'Test Customer' },
    document_date: '2025-12-01',
    currency: 'TND',
    subtotal: '84.0336',
    tax_amount: '15.9664',
    total: '100.0000',
    reason: CreditNoteReason.RETURN,
    reason_label: 'Product Return',
    notes: 'Customer returned product due to defect',
    status: DocumentStatus.POSTED,
    created_at: '2025-12-01T10:00:00Z',
  }

  const mockOnClose = vi.fn()
  const mockOnViewInvoice = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders credit note details', () => {
    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    expect(screen.getByText('Credit Note Details')).toBeInTheDocument()
    expect(screen.getByText('CN-00001')).toBeInTheDocument()
  })

  it('displays all credit note fields', () => {
    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    expect(screen.getByText('Number')).toBeInTheDocument()
    expect(screen.getByText('CN-00001')).toBeInTheDocument()
    expect(screen.getByText('Date')).toBeInTheDocument()
    expect(screen.getByText('Source Invoice')).toBeInTheDocument()
    expect(screen.getByText('INV-00001')).toBeInTheDocument()
    expect(screen.getByText('Amount')).toBeInTheDocument()
    expect(screen.getByText('100.00')).toBeInTheDocument()
    expect(screen.getByText('Reason')).toBeInTheDocument()
    expect(screen.getByText('Product Return')).toBeInTheDocument()
  })

  it('displays notes when present', () => {
    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    expect(screen.getByText('Notes')).toBeInTheDocument()
    expect(screen.getByText('Customer returned product due to defect')).toBeInTheDocument()
  })

  it('hides notes section when notes are null', () => {
    const creditNoteWithoutNotes = { ...mockCreditNote, notes: null }

    render(
      <CreditNoteDetail
        creditNote={creditNoteWithoutNotes}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    // Notes label should not appear if no notes
    const notesElements = screen.queryAllByText('Notes')
    expect(notesElements).toHaveLength(0)
  })

  it('displays status badge for posted credit notes', () => {
    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    // "Posted" appears in both badge and status field
    const postedElements = screen.getAllByText('Posted')
    expect(postedElements.length).toBeGreaterThan(0)
  })

  it('displays status badge for draft credit notes', () => {
    const draftCreditNote = { ...mockCreditNote, status: DocumentStatus.DRAFT }

    render(
      <CreditNoteDetail
        creditNote={draftCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    // "Draft" appears in both badge and status field
    const draftElements = screen.getAllByText('Draft')
    expect(draftElements.length).toBeGreaterThan(0)
  })

  it('displays print button', () => {
    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    expect(screen.getByText('Print')).toBeInTheDocument()
  })

  it('displays view invoice button', () => {
    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    expect(screen.getByText('View Source Invoice')).toBeInTheDocument()
  })

  it('displays close button when onClose is provided', () => {
    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    expect(screen.getByText('Close')).toBeInTheDocument()
  })

  it('calls onClose when close button is clicked', async () => {
    const user = userEvent.setup()

    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    const closeButton = screen.getByText('Close')
    await user.click(closeButton)

    expect(mockOnClose).toHaveBeenCalled()
  })

  it('calls onViewInvoice when view invoice button is clicked', async () => {
    const user = userEvent.setup()

    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    const viewInvoiceButton = screen.getByText('View Source Invoice')
    await user.click(viewInvoiceButton)

    expect(mockOnViewInvoice).toHaveBeenCalledWith(mockCreditNote.source_invoice_id)
  })

  it('triggers print when print button is clicked', async () => {
    const user = userEvent.setup()
    const printSpy = vi.spyOn(window, 'print').mockImplementation(() => {})

    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    const printButton = screen.getByText('Print')
    await user.click(printButton)

    expect(printSpy).toHaveBeenCalled()

    printSpy.mockRestore()
  })

  it('formats amount with 2 decimals', () => {
    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    expect(screen.getByText('100.00')).toBeInTheDocument()
  })

  it('formats dates correctly', () => {
    render(
      <CreditNoteDetail
        creditNote={mockCreditNote}
        onClose={mockOnClose}
        onViewInvoice={mockOnViewInvoice}
      />,
      { wrapper }
    )

    // Date appears in multiple fields (issue_date, created_at, updated_at)
    const dateElements = screen.getAllByText(/2025-12-01/)
    expect(dateElements.length).toBeGreaterThan(0)
  })

  it('displays all reason types correctly', () => {
    const reasons: Array<CreditNote['reason']> = [
      CreditNoteReason.RETURN,
      CreditNoteReason.PRICE_ADJUSTMENT,
      CreditNoteReason.BILLING_ERROR,
      CreditNoteReason.DAMAGED_GOODS,
      CreditNoteReason.SERVICE_ISSUE,
      CreditNoteReason.OTHER,
    ]

    reasons.forEach((reason) => {
      const creditNote = { ...mockCreditNote, reason }
      const { unmount } = render(
        <CreditNoteDetail
          creditNote={creditNote}
          onClose={mockOnClose}
          onViewInvoice={mockOnViewInvoice}
        />,
        { wrapper }
      )

      // Each reason should have a translation
      expect(screen.getByText(/Product Return|Price Adjustment|Billing Error|Damaged Goods|Service Issue|Other/)).toBeInTheDocument()

      unmount()
    })
  })
})
