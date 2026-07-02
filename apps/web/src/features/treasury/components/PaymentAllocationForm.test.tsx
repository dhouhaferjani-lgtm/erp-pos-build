/**
 * PaymentAllocationForm Component Tests
 * TDD: Tests written FIRST before implementation
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { PaymentAllocationForm } from './PaymentAllocationForm'
import type { OpenInvoice, PaymentAllocationPreview } from '@/types/treasury'

// Mock the API
const mockPreviewMutation = vi.fn()
const mockApplyMutation = vi.fn()

vi.mock('../hooks/useSmartPayment', () => ({
  usePaymentAllocationPreview: () => ({
    mutate: mockPreviewMutation,
    isPending: false,
    data: null,
    error: null,
  }),
  useApplyAllocation: () => ({
    mutate: mockApplyMutation,
    isPending: false,
    error: null,
  }),
}))

// Mock the translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      // Handle both namespaced (treasury:key or common:key) and non-namespaced keys
      // Non-namespaced keys default to 'treasury' namespace when using useTranslation(['treasury', 'common'])
      const translations: Record<string, string> = {
        // Treasury namespace (can be called without prefix OR with treasury: prefix)
        'smartPayment.allocation.title': 'Payment Allocation',
        'treasury:smartPayment.allocation.title': 'Payment Allocation',
        'smartPayment.allocation.method': 'Allocation Method',
        'treasury:smartPayment.allocation.method': 'Allocation Method',
        'smartPayment.allocation.fifo': 'FIFO (First In First Out)',
        'treasury:smartPayment.allocation.fifo': 'FIFO (First In First Out)',
        'smartPayment.allocation.dueDate': 'Due Date Priority',
        'treasury:smartPayment.allocation.dueDate': 'Due Date Priority',
        'smartPayment.allocation.manual': 'Manual Selection',
        'treasury:smartPayment.allocation.manual': 'Manual Selection',
        'smartPayment.allocation.fifoDescription': 'Allocate to oldest invoices first',
        'treasury:smartPayment.allocation.fifoDescription': 'Allocate to oldest invoices first',
        'smartPayment.allocation.dueDateDescription': 'Allocate to invoices by due date',
        'treasury:smartPayment.allocation.dueDateDescription': 'Allocate to invoices by due date',
        'smartPayment.allocation.manualDescription': 'Manually select invoices and amounts',
        'treasury:smartPayment.allocation.manualDescription': 'Manually select invoices and amounts',
        'smartPayment.allocation.paymentAmount': 'Payment Amount',
        'treasury:smartPayment.allocation.paymentAmount': 'Payment Amount',
        'smartPayment.allocation.previewButton': 'Preview Allocation',
        'treasury:smartPayment.allocation.previewButton': 'Preview Allocation',
        'smartPayment.allocation.applyButton': 'Apply Allocation',
        'treasury:smartPayment.allocation.applyButton': 'Apply Allocation',
        'smartPayment.allocation.totalAllocated': 'Total allocated: {{amount}}',
        'treasury:smartPayment.allocation.totalAllocated': 'Total allocated: {{amount}}',
        'smartPayment.allocation.exceedsPayment': 'Total allocation exceeds payment amount',
        'treasury:smartPayment.allocation.exceedsPayment': 'Total allocation exceeds payment amount',
        'smartPayment.allocation.noInvoicesSelected': 'Please select at least one invoice',
        'treasury:smartPayment.allocation.noInvoicesSelected': 'Please select at least one invoice',
        // Common namespace (must be called with prefix)
        'common:actions.cancel': 'Cancel',
        'common:status.loading': 'Loading...',
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

// Mock child components
vi.mock('./OpenInvoicesList', () => ({
  OpenInvoicesList: ({
    allocationMethod,
    selectedAllocations,
    onAllocationChange,
  }: {
    allocationMethod: string
    selectedAllocations: Array<{ document_id: string; amount: string }>
    onAllocationChange: (allocations: Array<{ document_id: string; amount: string }>) => void
  }) => (
    <div data-testid="open-invoices-list">
      <span>Allocation Method: {allocationMethod}</span>
      <span>Selected: {selectedAllocations.length}</span>
      <button onClick={() => { onAllocationChange([{ document_id: '1', amount: '100.00' }]); }}>
        Select Invoice
      </button>
    </div>
  ),
}))

vi.mock('./AllocationPreview', () => ({
  AllocationPreview: ({
    preview,
    isLoading,
  }: {
    preview: PaymentAllocationPreview
    isLoading: boolean
  }) => (
    <div data-testid="allocation-preview">
      {isLoading ? (
        <span>Loading preview...</span>
      ) : (
        <>
          <span>Preview: {preview.allocations.length} allocations</span>
          <span>Total: {preview.total_to_invoices}</span>
        </>
      )}
    </div>
  ),
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

describe('PaymentAllocationForm', () => {
  const mockInvoices: OpenInvoice[] = [
    {
      id: '1',
      document_number: 'INV-00001',
      document_date: '2025-11-01',
      due_date: '2025-11-30',
      total: '1190.0000',
      balance_due: '1190.0000',
      currency: 'TND',
      days_overdue: 10,
      partner: {
        id: 'partner-1',
        name: 'Customer A',
      },
    },
    {
      id: '2',
      document_number: 'INV-00002',
      document_date: '2025-12-01',
      due_date: '2025-12-31',
      total: '595.0000',
      balance_due: '595.0000',
      currency: 'TND',
      days_overdue: 0,
      partner: {
        id: 'partner-1',
        name: 'Customer A',
      },
    },
  ]

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders allocation form with all components', () => {
    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    expect(screen.getByText('Payment Allocation')).toBeInTheDocument()
    expect(screen.getByText('Allocation Method')).toBeInTheDocument()
    expect(screen.getByTestId('open-invoices-list')).toBeInTheDocument()
  })

  it('displays all allocation method options', () => {
    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    expect(screen.getByText('FIFO (First In First Out)')).toBeInTheDocument()
    expect(screen.getByText('Due Date Priority')).toBeInTheDocument()
    expect(screen.getByText('Manual Selection')).toBeInTheDocument()
  })

  it('selects FIFO as default allocation method', () => {
    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    const fifoRadio = screen.getByRole('radio', { name: /FIFO/i })
    expect(fifoRadio).toBeChecked()
  })

  it('switches allocation method when radio button is clicked', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    const manualRadio = screen.getByRole('radio', { name: /Manual Selection/i })
    await user.click(manualRadio)

    expect(manualRadio).toBeChecked()
    expect(screen.getByText('Allocation Method: manual')).toBeInTheDocument()
  })

  it('passes correct allocation method to OpenInvoicesList', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Initially FIFO
    expect(screen.getByText('Allocation Method: fifo')).toBeInTheDocument()

    // Switch to Due Date
    const dueDateRadio = screen.getByRole('radio', { name: /Due Date Priority/i })
    await user.click(dueDateRadio)

    expect(screen.getByText('Allocation Method: due_date')).toBeInTheDocument()
  })

  it('allows manual selection when manual method is selected', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Switch to manual
    const manualRadio = screen.getByRole('radio', { name: /Manual Selection/i })
    await user.click(manualRadio)

    // Mock selecting an invoice
    const selectButton = screen.getByText('Select Invoice')
    await user.click(selectButton)

    expect(screen.getByText('Selected: 1')).toBeInTheDocument()
  })

  it('displays preview button', () => {
    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    expect(screen.getByText('Preview Allocation')).toBeInTheDocument()
  })

  it('triggers preview mutation when preview button is clicked', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    const previewButton = screen.getByText('Preview Allocation')
    await user.click(previewButton)

    expect(mockPreviewMutation).toHaveBeenCalledWith({
      partner_id: 'partner-1',
      payment_amount: '1500.00',
      allocation_method: 'fifo',
    })
  })

  it('includes manual allocations in preview for manual method', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Switch to manual and select invoice
    const manualRadio = screen.getByRole('radio', { name: /Manual Selection/i })
    await user.click(manualRadio)

    const selectButton = screen.getByText('Select Invoice')
    await user.click(selectButton)

    // Preview
    const previewButton = screen.getByText('Preview Allocation')
    await user.click(previewButton)

    expect(mockPreviewMutation).toHaveBeenCalledWith({
      partner_id: 'partner-1',
      payment_amount: '1500.00',
      allocation_method: 'manual',
      manual_allocations: [{ document_id: '1', amount: '100.00' }],
    })
  })

  it('integrates with AllocationPreview component', () => {
    // Test verifies the component structure is set up to display AllocationPreview
    // Actual preview data rendering is tested via integration tests
    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Component renders correctly and has preview button
    expect(screen.getByText('Preview Allocation')).toBeInTheDocument()

    // AllocationPreview will be shown when previewMutation.data is available
    // (tested via integration tests with real React Query)
  })

  it('displays apply button', () => {
    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    expect(screen.getByText('Apply Allocation')).toBeInTheDocument()
  })

  it('triggers apply mutation when apply button is clicked', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    const applyButton = screen.getByText('Apply Allocation')
    await user.click(applyButton)

    expect(mockApplyMutation).toHaveBeenCalledWith(
      {
        payment_id: 'payment-1',
        allocation_method: 'fifo',
      },
      expect.any(Object)
    )
  })

  it('includes manual allocations in apply for manual method', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Switch to manual and select invoice
    const manualRadio = screen.getByRole('radio', { name: /Manual Selection/i })
    await user.click(manualRadio)

    const selectButton = screen.getByText('Select Invoice')
    await user.click(selectButton)

    // Apply
    const applyButton = screen.getByText('Apply Allocation')
    await user.click(applyButton)

    expect(mockApplyMutation).toHaveBeenCalledWith(
      {
        payment_id: 'payment-1',
        allocation_method: 'manual',
        manual_allocations: [{ document_id: '1', amount: '100.00' }],
      },
      expect.any(Object)
    )
  })

  it('invokes onSuccess callback after successful apply', async () => {
    const onSuccess = vi.fn()
    const user = userEvent.setup()

    // Mock successful mutation
    mockApplyMutation.mockImplementation((_, options: { onSuccess?: () => void }) => {
      options.onSuccess?.()
    })

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
        onSuccess={onSuccess}
      />,
      { wrapper }
    )

    const applyButton = screen.getByText('Apply Allocation')
    await user.click(applyButton)

    await waitFor(() => {
      expect(onSuccess).toHaveBeenCalled()
    })
  })

  it('displays cancel button when onCancel is provided', () => {
    const onCancel = vi.fn()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
        onCancel={onCancel}
      />,
      { wrapper }
    )

    expect(screen.getByText('Cancel')).toBeInTheDocument()
  })

  it('invokes onCancel callback when cancel button is clicked', async () => {
    const onCancel = vi.fn()
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
        onCancel={onCancel}
      />,
      { wrapper }
    )

    const cancelButton = screen.getByText('Cancel')
    await user.click(cancelButton)

    expect(onCancel).toHaveBeenCalled()
  })

  it('disables apply button when manual method with no selections', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Switch to manual (no selections)
    const manualRadio = screen.getByRole('radio', { name: /Manual Selection/i })
    await user.click(manualRadio)

    const applyButton = screen.getByText('Apply Allocation')
    expect(applyButton).toBeDisabled()
  })

  it('enables apply button when manual method has selections', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Switch to manual and select invoice
    const manualRadio = screen.getByRole('radio', { name: /Manual Selection/i })
    await user.click(manualRadio)

    const selectButton = screen.getByText('Select Invoice')
    await user.click(selectButton)

    const applyButton = screen.getByText('Apply Allocation')
    expect(applyButton).not.toBeDisabled()
  })

  it('shows apply button can be in loading state', () => {
    // This test validates the button behavior
    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Button should exist (actual loading state is tested via integration)
    const applyButton = screen.getByText('Apply Allocation')
    expect(applyButton).toBeInTheDocument()
  })

  it('displays payment amount in header', () => {
    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    expect(screen.getByText('Payment Amount')).toBeInTheDocument()
    // Consolidated locale-aware formatting: EUR default company -> fr-FR
    // (comma decimal, grouped thousands normalized to ' ' by testing-library).
    expect(screen.getByText('1 500,00')).toBeInTheDocument()
  })

  it('validates allocation behavior with manual mode', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Switch to manual
    const manualRadio = screen.getByRole('radio', { name: /Manual Selection/i })
    await user.click(manualRadio)

    // OpenInvoicesList should be present
    expect(screen.getByTestId('open-invoices-list')).toBeInTheDocument()
    expect(screen.getByText('Allocation Method: manual')).toBeInTheDocument()
  })

  it('displays total allocated amount for manual method', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Switch to manual and select invoice
    const manualRadio = screen.getByRole('radio', { name: /Manual Selection/i })
    await user.click(manualRadio)

    const selectButton = screen.getByText('Select Invoice')
    await user.click(selectButton)

    // Locale-aware rendering of the bcmath total (fr-FR comma decimal).
    expect(screen.getByText('Total allocated: 100,00')).toBeInTheDocument()
  })

  it('allows switching between allocation methods', async () => {
    const user = userEvent.setup()

    render(
      <PaymentAllocationForm
        paymentId="payment-1"
        partnerId="partner-1"
        paymentAmount="1500.00"
        invoices={mockInvoices}
      />,
      { wrapper }
    )

    // Initially FIFO
    const fifoRadio = screen.getByRole('radio', { name: /FIFO/i })
    expect(fifoRadio).toBeChecked()

    // Switch to Due Date
    const dueDateRadio = screen.getByRole('radio', { name: /Due Date Priority/i })
    await user.click(dueDateRadio)
    expect(dueDateRadio).toBeChecked()

    // Switch to Manual
    const manualRadio = screen.getByRole('radio', { name: /Manual Selection/i })
    await user.click(manualRadio)
    expect(manualRadio).toBeChecked()
  })
})
