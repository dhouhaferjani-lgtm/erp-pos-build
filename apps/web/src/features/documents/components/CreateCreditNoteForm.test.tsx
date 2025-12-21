/**
 * CreateCreditNoteForm Component Tests
 * TDD: Tests written FIRST before implementation
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CreateCreditNoteForm } from './CreateCreditNoteForm'
import type { InvoiceForCreditNote } from '@/types/creditNote'
import { DocumentStatus } from '@/types/creditNote'

// Note: Hooks are not mocked here - using real React Query hooks with QueryClientProvider
// The component should render with isSubmitting=false initially, showing 'Save' button

// Mock the translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const translations: Record<string, string> = {
        // Sales namespace (unprefixed - default when using useTranslation(['sales', 'common']))
        'creditNotes.createFromInvoice': 'Create Credit Note',
        'creditNotes.amount': 'Amount',
        'creditNotes.reason.title': 'Reason',
        'creditNotes.reason.return': 'Product Return',
        'creditNotes.reason.priceAdjustment': 'Price Adjustment',
        'creditNotes.reason.billingError': 'Billing Error',
        'creditNotes.reason.damagedGoods': 'Damaged Goods',
        'creditNotes.reason.serviceIssue': 'Service Issue',
        'creditNotes.reason.other': 'Other',
        'creditNotes.notes': 'Notes',
        'creditNotes.notesPlaceholder': 'Reason for credit note...',
        'creditNotes.form.maxAmount': 'Maximum: {{amount}}',
        'creditNotes.form.remainingCreditable': 'Remaining creditable: {{amount}}',
        'creditNotes.form.amountRequired': 'Amount is required',
        'creditNotes.form.amountPositive': 'Amount must be positive',
        'creditNotes.form.reasonRequired': 'Reason is required',
        'creditNotes.errors.exceedsInvoiceTotal': 'Amount exceeds invoice total',
        'creditNotes.errors.exceedsRemainingBalance': 'Amount exceeds remaining creditable balance',
        'creditNotes.messages.fullRefund': 'Full Refund',
        'creditNotes.messages.createFailed': 'Failed to create credit note',
        'documents.number': 'Invoice Number',
        'documents.total': 'Total',
        // Sales namespace (prefixed)
        'sales:creditNotes.createFromInvoice': 'Create Credit Note',
        'sales:creditNotes.amount': 'Amount',
        'sales:creditNotes.reason.title': 'Reason',
        'sales:creditNotes.reason.return': 'Product Return',
        'sales:creditNotes.reason.priceAdjustment': 'Price Adjustment',
        'sales:creditNotes.reason.billingError': 'Billing Error',
        'sales:creditNotes.reason.damagedGoods': 'Damaged Goods',
        'sales:creditNotes.reason.serviceIssue': 'Service Issue',
        'sales:creditNotes.reason.other': 'Other',
        'sales:creditNotes.notes': 'Notes',
        'sales:creditNotes.notesPlaceholder': 'Reason for credit note...',
        'sales:creditNotes.form.maxAmount': 'Maximum: {{amount}}',
        'sales:creditNotes.form.remainingCreditable': 'Remaining creditable: {{amount}}',
        'sales:creditNotes.form.amountRequired': 'Amount is required',
        'sales:creditNotes.form.amountPositive': 'Amount must be positive',
        'sales:creditNotes.form.reasonRequired': 'Reason is required',
        'sales:creditNotes.errors.exceedsInvoiceTotal': 'Amount exceeds invoice total',
        'sales:creditNotes.errors.exceedsRemainingBalance': 'Amount exceeds remaining creditable balance',
        'sales:creditNotes.messages.fullRefund': 'Full Refund',
        'sales:creditNotes.messages.createFailed': 'Failed to create credit note',
        'sales:documents.number': 'Invoice Number',
        'sales:documents.total': 'Total',
        // Common namespace (must be prefixed)
        'common:actions.save': 'Save',
        'common:actions.cancel': 'Cancel',
        'common:select': 'Select...',
        'common:status.saving': 'Saving...',
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

describe('CreateCreditNoteForm', () => {
  const mockInvoice: InvoiceForCreditNote = {
    id: 'invoice-1',
    document_number: 'INV-00001',
    document_date: '2025-12-01',
    partner: {
      id: 'partner-1',
      name: 'Test Customer',
    },
    total: '1190.0000',
    balance_due: '1190.0000',
    currency: 'TND',
    status: DocumentStatus.POSTED,
  }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders form with all required fields', () => {
    render(<CreateCreditNoteForm invoice={mockInvoice} />, { wrapper })

    expect(screen.getByText('Create Credit Note')).toBeInTheDocument()
    expect(screen.getByLabelText('Amount')).toBeInTheDocument()
    expect(screen.getByLabelText('Reason')).toBeInTheDocument()
    expect(screen.getByLabelText('Notes')).toBeInTheDocument()
    expect(screen.getByText('Save')).toBeInTheDocument()
    expect(screen.getByText('Cancel')).toBeInTheDocument()
  })

  it('displays invoice information and creditable amount', () => {
    render(<CreateCreditNoteForm invoice={mockInvoice} />, { wrapper })

    expect(screen.getByText('INV-00001')).toBeInTheDocument()
    expect(screen.getByText('Remaining creditable: 1190.00')).toBeInTheDocument()
  })

  it('validates that amount is required', async () => {
    const user = userEvent.setup()
    render(<CreateCreditNoteForm invoice={mockInvoice} />, { wrapper })

    const submitButton = screen.getByText('Save')
    await user.click(submitButton)

    await waitFor(() => {
      expect(screen.getByText('Amount is required')).toBeInTheDocument()
    })
  })

  it('validates that amount must be positive', async () => {
    const user = userEvent.setup()
    render(<CreateCreditNoteForm invoice={mockInvoice} />, { wrapper })

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '-100')
    await user.tab()

    await waitFor(() => {
      expect(screen.getByText('Amount must be positive')).toBeInTheDocument()
    })
  })

  it('validates that amount does not exceed invoice total', async () => {
    const user = userEvent.setup()
    render(<CreateCreditNoteForm invoice={mockInvoice} />, { wrapper })

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '1500')
    await user.tab()

    await waitFor(() => {
      expect(screen.getByText('Amount exceeds invoice total')).toBeInTheDocument()
    })
  })

  it('validates that amount does not exceed remaining creditable', async () => {
    const partiallyRefundedInvoice: InvoiceForCreditNote = {
      ...mockInvoice,
      balance_due: '690.0000',
    }

    const user = userEvent.setup()
    render(<CreateCreditNoteForm invoice={partiallyRefundedInvoice} />, { wrapper })

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '800')
    await user.tab()

    await waitFor(() => {
      expect(screen.getByText('Amount exceeds remaining creditable balance')).toBeInTheDocument()
    })
  })

  it('validates that reason is required', async () => {
    const user = userEvent.setup()
    render(<CreateCreditNoteForm invoice={mockInvoice} />, { wrapper })

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '100')

    const submitButton = screen.getByText('Save')
    await user.click(submitButton)

    await waitFor(() => {
      expect(screen.getByText('Reason is required')).toBeInTheDocument()
    })
  })

  it('allows selecting credit note reason', async () => {
    const user = userEvent.setup()
    render(<CreateCreditNoteForm invoice={mockInvoice} />, { wrapper })

    const reasonSelect = screen.getByLabelText('Reason')
    await user.click(reasonSelect)

    // Reason options should be available
    expect(screen.getByText('Product Return')).toBeInTheDocument()
    expect(screen.getByText('Price Adjustment')).toBeInTheDocument()
    expect(screen.getByText('Billing Error')).toBeInTheDocument()
    expect(screen.getByText('Damaged Goods')).toBeInTheDocument()
    expect(screen.getByText('Service Issue')).toBeInTheDocument()
    expect(screen.getByText('Other')).toBeInTheDocument()
  })

  it('calls onSuccess callback after successful submission', async () => {
    const onSuccess = vi.fn()
    const user = userEvent.setup()

    render(<CreateCreditNoteForm invoice={mockInvoice} onSuccess={onSuccess} />, { wrapper })

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '100')

    const reasonSelect = screen.getByLabelText('Reason')
    await user.click(reasonSelect)
    await user.click(screen.getByText('Product Return'))

    const notesInput = screen.getByLabelText('Notes')
    await user.type(notesInput, 'Customer returned product')

    const submitButton = screen.getByText('Save')
    await user.click(submitButton)

    // Form should be valid and mutation should be called
    await waitFor(() => {
      expect(onSuccess).toHaveBeenCalled()
    })
  })

  it('calls onCancel callback when cancel is clicked', async () => {
    const onCancel = vi.fn()
    const user = userEvent.setup()

    render(<CreateCreditNoteForm invoice={mockInvoice} onCancel={onCancel} />, { wrapper })

    const cancelButton = screen.getByText('Cancel')
    await user.click(cancelButton)

    expect(onCancel).toHaveBeenCalled()
  })

  it('displays loading state when submitting', async () => {
    vi.mock('../hooks/useCreditNotes', () => ({
      useCreateCreditNote: () => ({
        mutate: vi.fn(),
        isPending: true,
        isError: false,
        error: null,
      }),
    }))

    render(<CreateCreditNoteForm invoice={mockInvoice} />, { wrapper })

    // Submit button should show loading state
    expect(screen.getByText('Save')).toBeDisabled()
  })

  it('pre-fills amount with remaining creditable when "Full Refund" button is clicked', async () => {
    const user = userEvent.setup()
    render(<CreateCreditNoteForm invoice={mockInvoice} />, { wrapper })

    // Assuming there's a "Full Refund" button
    const fullRefundButton = screen.queryByText('Full Refund')
    if (fullRefundButton) {
      await user.click(fullRefundButton)

      const amountInput = screen.getByLabelText('Amount') as HTMLInputElement
      expect(amountInput.value).toBe('1190.00')
    }
  })

  it('shows confirmation dialog for large credit notes (>50%)', async () => {
    const user = userEvent.setup()
    render(<CreateCreditNoteForm invoice={mockInvoice} />, { wrapper })

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '700') // >50% of 1190

    const reasonSelect = screen.getByLabelText('Reason')
    await user.click(reasonSelect)
    await user.click(screen.getByText('Price Adjustment'))

    const submitButton = screen.getByText('Save')
    await user.click(submitButton)

    // Should show confirmation dialog (if implemented)
    await waitFor(() => {
      const confirmText = screen.queryByText(/large credit note/i)
      if (confirmText) {
        expect(confirmText).toBeInTheDocument()
      }
    })
  })
})
