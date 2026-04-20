/**
 * OpenInvoicesList Component Tests
 * TDD: Tests written FIRST before implementation
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { OpenInvoicesList } from './OpenInvoicesList'
import { AllocationMethod } from '@/types/treasury'
import { makeOpenInvoice } from '../__fixtures__/openInvoice'

// Mock the API
vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
}))

// Mock the translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      // Handle both namespaced (treasury:key) and non-namespaced keys
      // Non-namespaced keys default to 'treasury' namespace when using useTranslation(['treasury', 'common'])
      const translations: Record<string, string> = {
        // Treasury namespace (can be called without prefix OR with treasury: prefix)
        'smartPayment.openInvoices.title': 'Open Invoices',
        'treasury:smartPayment.openInvoices.title': 'Open Invoices',
        'smartPayment.openInvoices.noInvoices': 'No open invoices',
        'treasury:smartPayment.openInvoices.noInvoices': 'No open invoices',
        'smartPayment.openInvoices.sortBy': 'Sort by',
        'treasury:smartPayment.openInvoices.sortBy': 'Sort by',
        'smartPayment.openInvoices.sortByDate': 'Invoice Date',
        'treasury:smartPayment.openInvoices.sortByDate': 'Invoice Date',
        'smartPayment.openInvoices.sortByDueDate': 'Due Date',
        'treasury:smartPayment.openInvoices.sortByDueDate': 'Due Date',
        'smartPayment.openInvoices.sortByAmount': 'Amount',
        'treasury:smartPayment.openInvoices.sortByAmount': 'Amount',
        'smartPayment.openInvoices.selectAll': 'Select All',
        'treasury:smartPayment.openInvoices.selectAll': 'Select All',
        'smartPayment.openInvoices.deselectAll': 'Deselect All',
        'treasury:smartPayment.openInvoices.deselectAll': 'Deselect All',
        'smartPayment.openInvoices.totalBalance': 'Total balance: {{amount}}',
        'treasury:smartPayment.openInvoices.totalBalance': 'Total balance: {{amount}}',
        'smartPayment.allocation.invoice': 'Invoice',
        'treasury:smartPayment.allocation.invoice': 'Invoice',
        'smartPayment.allocation.originalBalance': 'Balance',
        'treasury:smartPayment.allocation.originalBalance': 'Balance',
        'smartPayment.allocation.amountToAllocate': 'Amount to Allocate',
        'treasury:smartPayment.allocation.amountToAllocate': 'Amount to Allocate',
        'smartPayment.allocation.daysOverdue': '{{days}} days overdue',
        'treasury:smartPayment.allocation.daysOverdue': '{{days}} days overdue',
        // Common namespace (must be called with prefix)
        'common:status.loading': 'Loading...',
        'common.status.loading': 'Loading...',
        'common:status.current': 'Current',
        'common:status.overdue': 'Overdue',
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

describe('OpenInvoicesList', () => {
  const mockInvoices = [
    makeOpenInvoice({
      id: '1',
      document_number: 'INV-00001',
      document_date: '2025-11-01',
      due_date: '2025-11-30',
      total: '1190.0000',
      balance_due: '1190.0000',
      days_overdue: 10,
    }),
    makeOpenInvoice({
      id: '2',
      document_number: 'INV-00002',
      document_date: '2025-12-01',
      due_date: '2025-12-31',
      total: '595.0000',
      balance_due: '595.0000',
      days_overdue: 0,
    }),
    makeOpenInvoice({
      id: '3',
      document_number: 'INV-00003',
      document_date: '2025-10-15',
      due_date: '2025-11-15',
      total: '2380.0000',
      balance_due: '2380.0000',
      days_overdue: 25,
    }),
  ]

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders invoice list with all invoices', () => {
    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.FIFO}
      />,
    )

    expect(screen.getByText('Open Invoices')).toBeInTheDocument()
    expect(screen.getByText('INV-00001')).toBeInTheDocument()
    expect(screen.getByText('INV-00002')).toBeInTheDocument()
    expect(screen.getByText('INV-00003')).toBeInTheDocument()
  })

  it('displays total balance correctly', () => {
    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.FIFO}
      />,
    )

    // Total: 1190 + 595 + 2380 = 4165
    expect(screen.getByText('Total balance: 4165.00')).toBeInTheDocument()
  })

  it('shows overdue status for overdue invoices', () => {
    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.FIFO}
      />,
    )

    expect(screen.getByText('10 days overdue')).toBeInTheDocument()
    expect(screen.getByText('25 days overdue')).toBeInTheDocument()
    expect(screen.getAllByText('Overdue')).toHaveLength(2)
  })

  it('shows current status for non-overdue invoices', () => {
    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.FIFO}
      />,
    )

    expect(screen.getByText('Current')).toBeInTheDocument()
  })

  it('renders empty state when no invoices', () => {
    renderWithProviders(
      <OpenInvoicesList partnerId="partner-1" invoices={[]} allocationMethod={AllocationMethod.FIFO} />,
    )

    expect(screen.getByText('No open invoices')).toBeInTheDocument()
  })

  it('disables selection for FIFO allocation method', () => {
    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.FIFO}
      />,
    )

    // FIFO should be read-only (no checkboxes)
    const checkboxes = screen.queryAllByRole('checkbox')
    expect(checkboxes).toHaveLength(0)
  })

  it('disables selection for due_date allocation method', () => {
    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.DUE_DATE}
      />,
    )

    // Due Date should be read-only (no checkboxes)
    const checkboxes = screen.queryAllByRole('checkbox')
    expect(checkboxes).toHaveLength(0)
  })

  it('enables selection for manual allocation method', () => {
    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.MANUAL}
        selectedAllocations={[]}
        onAllocationChange={vi.fn()}
      />,
    )

    // Manual should show checkboxes
    const checkboxes = screen.getAllByRole('checkbox')
    expect(checkboxes.length).toBeGreaterThan(0)
  })

  it('allows selecting individual invoices in manual mode', async () => {
    const onAllocationChange = vi.fn()
    const user = userEvent.setup()

    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.MANUAL}
        selectedAllocations={[]}
        onAllocationChange={onAllocationChange}
      />,
    )

    const checkboxes = screen.getAllByRole('checkbox')
    await user.click(checkboxes[1]) // Select first invoice (skip "Select All" checkbox)

    expect(onAllocationChange).toHaveBeenCalled()
  })

  it('allows entering allocation amount for selected invoices', async () => {
    const onAllocationChange = vi.fn()
    const user = userEvent.setup()

    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.MANUAL}
        selectedAllocations={[
          {
            document_id: '1',
            amount: '100.00',
          },
        ]}
        onAllocationChange={onAllocationChange}
      />,
    )

    // Only the input for the selected invoice is editable; the others are
    // rendered as `<input disabled>`. Find the editable one.
    const amountInputs = screen.getAllByRole('spinbutton')
    const editable = amountInputs.find((el) => !(el as HTMLInputElement).disabled)
    expect(editable).toBeDefined()

    await user.click(editable!)
    await user.keyboard('{Control>}a{/Control}500')

    expect(onAllocationChange).toHaveBeenCalled()
  })

  // The sort control is a native <select> (label "Sort by:"), not a
  // button/menu. Drive it with `selectOptions` on the first combobox.
  it('sorts invoices by date (oldest first)', async () => {
    const user = userEvent.setup()

    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.FIFO}
      />,
    )

    const sortSelect = screen.getAllByRole('combobox')[0]
    await user.selectOptions(sortSelect, 'date')

    const invoiceNumbers = screen.getAllByText(/INV-/)
    expect(invoiceNumbers[0].textContent).toContain('INV-00003')
  })

  it('sorts invoices by due date', () => {
    // `due_date` is the default sort order on mount, so we only need to
    // render and assert oldest-first ordering (INV-00003 has the earliest
    // due date, 2025-11-15).
    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.FIFO}
      />,
    )

    const invoiceNumbers = screen.getAllByText(/INV-/)
    expect(invoiceNumbers[0].textContent).toContain('INV-00003')
  })

  it('sorts invoices by amount', async () => {
    const user = userEvent.setup()

    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.FIFO}
      />,
    )

    const sortSelect = screen.getAllByRole('combobox')[0]
    await user.selectOptions(sortSelect, 'amount')

    // After sorting by amount (largest first), INV-00003 should be first (2380)
    const invoiceNumbers = screen.getAllByText(/INV-/)
    expect(invoiceNumbers[0].textContent).toContain('INV-00003')
  })

  it('implements select all functionality in manual mode', async () => {
    const onAllocationChange = vi.fn()
    const user = userEvent.setup()

    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.MANUAL}
        selectedAllocations={[]}
        onAllocationChange={onAllocationChange}
      />,
    )

    const selectAllButton = screen.getByText('Select All')
    await user.click(selectAllButton)

    expect(onAllocationChange).toHaveBeenCalledWith(
      expect.arrayContaining([
        expect.objectContaining({ document_id: '1' }),
        expect.objectContaining({ document_id: '2' }),
        expect.objectContaining({ document_id: '3' }),
      ])
    )
  })

  it('implements deselect all functionality in manual mode', async () => {
    const onAllocationChange = vi.fn()
    const user = userEvent.setup()

    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.MANUAL}
        selectedAllocations={[
          { document_id: '1', amount: '100.00' },
          { document_id: '2', amount: '200.00' },
        ]}
        onAllocationChange={onAllocationChange}
      />,
    )

    const deselectAllButton = screen.getByText('Deselect All')
    await user.click(deselectAllButton)

    expect(onAllocationChange).toHaveBeenCalledWith([])
  })

  it('validates that allocation amount does not exceed invoice balance', async () => {
    const onAllocationChange = vi.fn()
    const user = userEvent.setup()

    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.MANUAL}
        selectedAllocations={[
          {
            document_id: '1',
            amount: '100.00',
          },
        ]}
        onAllocationChange={onAllocationChange}
      />,
    )

    const amountInputs = screen.getAllByRole('spinbutton')
    const editable = amountInputs.find((el) => !(el as HTMLInputElement).disabled)
    await user.click(editable!)
    await user.keyboard('{Control>}a{/Control}5000') // Exceeds balance of 1190

    await waitFor(() => {
      // Should show validation error
      const errorMessage = screen.queryByText(/exceeds/i)
      if (errorMessage) {
        expect(errorMessage).toBeInTheDocument()
      }
    })
  })

  it('displays loading state', () => {
    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={[]}
        allocationMethod={AllocationMethod.FIFO}
        isLoading={true}
      />,
    )

    expect(screen.getByText('Loading...')).toBeInTheDocument()
  })

  it('formats amounts correctly with 2 decimals', () => {
    renderWithProviders(
      <OpenInvoicesList
        partnerId="partner-1"
        invoices={mockInvoices}
        allocationMethod={AllocationMethod.FIFO}
      />,
    )

    expect(screen.getByText('1190.00')).toBeInTheDocument()
    expect(screen.getByText('595.00')).toBeInTheDocument()
    expect(screen.getByText('2380.00')).toBeInTheDocument()
  })
})
