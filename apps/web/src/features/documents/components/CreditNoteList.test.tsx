/**
 * CreditNoteList Component Tests
 * TDD: Tests written FIRST before implementation
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CreditNoteList } from './CreditNoteList'
import { CreditNoteReason, DocumentStatus, type CreditNote } from '@/types/creditNote'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

// Mock the translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const translations: Record<string, string> = {
        'sales:creditNotes.title': 'Credit Notes',
        'sales:creditNotes.number': 'Number',
        'sales:creditNotes.date': 'Date',
        'sales:creditNotes.sourceInvoice': 'Source Invoice',
        'sales:creditNotes.amount': 'Amount',
        'sales:creditNotes.reasonLabel': 'Reason',
        'sales:creditNotes.statusLabel': 'Status',
        'sales:creditNotes.empty.title': 'No credit notes',
        'sales:creditNotes.empty.description': 'Credit notes will appear here once created.',
        'sales:creditNotes.sortBy': 'Sort by',
        'sales:creditNotes.sortByDate': 'Date',
        'sales:creditNotes.sortByAmount': 'Amount',
        'sales:creditNotes.sortByNumber': 'Number',
        'sales:creditNotes.filterByReason': 'Filter by reason',
        'sales:creditNotes.allReasons': 'All reasons',
        'sales:creditNotes.reasons.return': 'Product Return',
        'sales:creditNotes.reasons.price_adjustment': 'Price Adjustment',
        'sales:creditNotes.reasons.billing_error': 'Billing Error',
        'sales:creditNotes.reasons.damaged_goods': 'Damaged Goods',
        'sales:creditNotes.reasons.service_issue': 'Service Issue',
        'sales:creditNotes.reasons.other': 'Other',
        'common:status.loading': 'Loading...',
        'common:status.draft': 'Draft',
        'common:status.posted': 'Posted',
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

describe('CreditNoteList', () => {
  const mockCreditNotes: CreditNote[] = [
    {
      id: '1',
      document_number: 'CN-00001',
      source_invoice_id: 'inv-1',
      source_invoice_number: 'INV-00001',
      partner: { id: 'partner-1', name: 'Customer A' },
      document_date: '2025-12-01',
      currency: 'TND',
      subtotal: '84.0336',
      tax_amount: '15.9664',
      total: '100.0000',
      reason: CreditNoteReason.RETURN,
      reason_label: 'Product Return',
      notes: 'Customer returned product',
      status: DocumentStatus.POSTED,
      created_at: '2025-12-01T10:00:00Z',
    },
    {
      id: '2',
      document_number: 'CN-00002',
      source_invoice_id: 'inv-2',
      source_invoice_number: 'INV-00002',
      partner: { id: 'partner-2', name: 'Customer B' },
      document_date: '2025-12-05',
      currency: 'TND',
      subtotal: '42.0168',
      tax_amount: '7.9832',
      total: '50.0000',
      reason: CreditNoteReason.PRICE_ADJUSTMENT,
      reason_label: 'Price Adjustment',
      notes: 'Price correction',
      status: DocumentStatus.POSTED,
      created_at: '2025-12-05T14:30:00Z',
    },
    {
      id: '3',
      document_number: 'CN-00003',
      source_invoice_id: 'inv-3',
      source_invoice_number: 'INV-00003',
      partner: { id: 'partner-3', name: 'Customer C' },
      document_date: '2025-12-08',
      currency: 'TND',
      subtotal: '168.0672',
      tax_amount: '31.9328',
      total: '200.0000',
      reason: CreditNoteReason.BILLING_ERROR,
      reason_label: 'Billing Error',
      notes: null,
      status: DocumentStatus.DRAFT,
      created_at: '2025-12-08T09:15:00Z',
    },
  ]

  const mockOnSelect = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders credit notes list with all items', () => {
    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    expect(screen.getByText('Credit Notes')).toBeInTheDocument()
    expect(screen.getByText('CN-00001')).toBeInTheDocument()
    expect(screen.getByText('CN-00002')).toBeInTheDocument()
    expect(screen.getByText('CN-00003')).toBeInTheDocument()
  })

  it('renders the draft placeholder for a credit note that has no number yet', () => {
    // R-2 / LEDGER D-T9-1: a DRAFT is born unnumbered — the number is allocated
    // at confirm — so the list must name it, not render an empty cell. Sorting
    // by number must survive it too (an unnumbered draft sorts last).
    const unnumbered: CreditNote = {
      ...mockCreditNotes[0]!,
      id: 'draft-cn',
      document_number: null,
    }

    render(
      <CreditNoteList creditNotes={[unnumbered, mockCreditNotes[1]!]} onSelect={mockOnSelect} />,
      { wrapper }
    )

    expect(screen.getByText('sales:documents.draftNumberPlaceholder')).toBeInTheDocument()
  })

  it('displays all table columns', () => {
    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    // Check table headers exist
    const headers = screen.getAllByRole('columnheader')
    expect(headers).toHaveLength(6)
    expect(screen.getAllByText('Number').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Date').length).toBeGreaterThan(0)
    expect(screen.getByText('Source Invoice')).toBeInTheDocument()
    expect(screen.getAllByText('Amount').length).toBeGreaterThan(0)
    expect(screen.getByText('Reason')).toBeInTheDocument()
    expect(screen.getByText('Status')).toBeInTheDocument()
  })

  it('formats amounts correctly with 2 decimals', () => {
    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    expect(screen.getByText('100.00')).toBeInTheDocument()
    expect(screen.getByText('50.00')).toBeInTheDocument()
    expect(screen.getByText('200.00')).toBeInTheDocument()
  })

  it('displays reason labels correctly', () => {
    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    // Reasons appear in both dropdown and table cells, so use getAllByText
    expect(screen.getAllByText('Product Return').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Price Adjustment').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Billing Error').length).toBeGreaterThan(0)
  })

  it('displays status badges correctly', () => {
    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    const postedBadges = screen.getAllByText('Posted')
    expect(postedBadges).toHaveLength(2)
    expect(screen.getByText('Draft')).toBeInTheDocument()
  })

  it('renders empty state when no credit notes', () => {
    render(<CreditNoteList creditNotes={[]} onSelect={mockOnSelect} />, { wrapper })

    expect(screen.getByText('No credit notes')).toBeInTheDocument()
    expect(screen.getByText('Credit notes will appear here once created.')).toBeInTheDocument()
  })

  it('displays loading state', () => {
    render(
      <CreditNoteList creditNotes={[]} onSelect={mockOnSelect} isLoading={true} />,
      { wrapper }
    )

    expect(screen.getByText('Loading...')).toBeInTheDocument()
  })

  it('calls onSelect when credit note row is clicked', async () => {
    const user = userEvent.setup()

    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    const firstRow = screen.getByText('CN-00001').closest('tr')
    await user.click(firstRow!)

    expect(mockOnSelect).toHaveBeenCalledWith(mockCreditNotes[0])
  })

  it('sorts by date (newest first by default)', () => {
    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    const rows = screen.getAllByRole('row')
    // Skip header row, check data rows
    expect(rows[1]).toHaveTextContent('CN-00003') // 2025-12-08
    expect(rows[2]).toHaveTextContent('CN-00002') // 2025-12-05
    expect(rows[3]).toHaveTextContent('CN-00001') // 2025-12-01
  })

  it('allows sorting by amount', async () => {
    const user = userEvent.setup()

    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    const sortSelect = screen.getByLabelText('Sort by')
    await user.selectOptions(sortSelect, 'amount')

    await waitFor(() => {
      const rows = screen.getAllByRole('row')
      // Sorted by amount descending
      expect(rows[1]).toHaveTextContent('CN-00003') // 200.00
      expect(rows[2]).toHaveTextContent('CN-00001') // 100.00
      expect(rows[3]).toHaveTextContent('CN-00002') // 50.00
    })
  })

  it('allows sorting by number', async () => {
    const user = userEvent.setup()

    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    const sortSelect = screen.getByLabelText('Sort by')
    await user.selectOptions(sortSelect, 'number')

    await waitFor(() => {
      const rows = screen.getAllByRole('row')
      expect(rows[1]).toHaveTextContent('CN-00001')
      expect(rows[2]).toHaveTextContent('CN-00002')
      expect(rows[3]).toHaveTextContent('CN-00003')
    })
  })

  it('displays reason filter dropdown', () => {
    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    expect(screen.getByLabelText('Filter by reason')).toBeInTheDocument()
    // "All reasons" appears as the default selected option
    expect(screen.getAllByText('All reasons').length).toBeGreaterThan(0)
  })

  it('filters by reason', async () => {
    const user = userEvent.setup()

    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    const filterSelect = screen.getByLabelText('Filter by reason')
    await user.selectOptions(filterSelect, 'return')

    // Should only show return reason
    await waitFor(() => {
      expect(screen.getByText('CN-00001')).toBeInTheDocument()
      expect(screen.queryByText('CN-00002')).not.toBeInTheDocument()
      expect(screen.queryByText('CN-00003')).not.toBeInTheDocument()
    })
  })

  it('shows all credit notes when "All reasons" filter is selected', async () => {
    const user = userEvent.setup()

    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    // First filter by a reason
    const filterSelect = screen.getByLabelText('Filter by reason')
    await user.selectOptions(filterSelect, 'return')

    await waitFor(() => {
      expect(screen.getByText('CN-00001')).toBeInTheDocument()
      expect(screen.queryByText('CN-00002')).not.toBeInTheDocument()
    })

    // Then select "All reasons"
    await user.selectOptions(filterSelect, 'all')

    // All should be visible
    await waitFor(() => {
      expect(screen.getByText('CN-00001')).toBeInTheDocument()
      expect(screen.getByText('CN-00002')).toBeInTheDocument()
      expect(screen.getByText('CN-00003')).toBeInTheDocument()
    })
  })

  it('displays source invoice number as clickable link', () => {
    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    expect(screen.getByText('INV-00001')).toBeInTheDocument()
    expect(screen.getByText('INV-00002')).toBeInTheDocument()
    expect(screen.getByText('INV-00003')).toBeInTheDocument()
  })

  it('highlights row on hover', async () => {
    const user = userEvent.setup()

    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    const firstRow = screen.getByText('CN-00001').closest('tr')!

    await user.hover(firstRow)

    expect(firstRow).toHaveClass(colorTokens.intent.neutral.bgHover)
  })

  it('formats dates correctly', () => {
    render(
      <CreditNoteList creditNotes={mockCreditNotes} onSelect={mockOnSelect} />,
      { wrapper }
    )

    // Dates should be formatted (implementation will format as locale date)
    expect(screen.getByText(/2025-12-01/)).toBeInTheDocument()
    expect(screen.getByText(/2025-12-05/)).toBeInTheDocument()
    expect(screen.getByText(/2025-12-08/)).toBeInTheDocument()
  })
})
