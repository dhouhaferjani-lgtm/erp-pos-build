/**
 * CreateCreditNoteForm Component Tests
 * TDD: Tests written FIRST before implementation
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { CreateCreditNoteForm } from './CreateCreditNoteForm'
import { makeInvoiceForCreditNote } from '../__fixtures__/creditNote'

// Mock the credit-note hook at the module level so every render sees the
// same mutation shape. A hoisted `mutationState` lets individual tests flip
// `isPending` (e.g. the "displays loading state" test) without using
// `vi.mock` inside `it`, which Vitest hoists to the top of the file and
// would otherwise leak into every test in the suite.
const { mutationState, mockMutate } = vi.hoisted(() => ({
  mutationState: {
    isPending: false,
    isError: false,
    error: null as unknown,
  },
  mockMutate: vi.fn(),
}))

vi.mock('@/components/atoms/MoneyInput', () => ({
  MoneyInput: ({
    id,
    value,
    onChange,
    disabled,
    className,
    error: _error,
    currency: _currency,
    ...rest
  }: {
    id?: string
    value: string
    onChange: (v: string) => void
    disabled?: boolean
    className?: string
    error?: boolean
    currency?: string
    [k: string]: unknown
  }) => (
    <input
      id={id}
      type="number"
      value={value}
      onChange={(e) => { onChange(e.target.value) }}
      disabled={disabled}
      className={className}
      {...rest}
    />
  ),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'TND',
    decimals: 3,
    toFixed: (v: number) => v.toFixed(3),
    format: (v: number) => v.toFixed(3),
    symbol: 'TND',
  }),
}))

vi.mock('../hooks/useCreditNotes', () => ({
  useCreateCreditNote: () => ({
    mutate: mockMutate,
    mutateAsync: mockMutate,
    isPending: mutationState.isPending,
    isError: mutationState.isError,
    error: mutationState.error,
  }),
}))

// Mock the translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown> | string) => {
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
      if (params && typeof params === 'object') {
        Object.entries(params).forEach(([k, v]) => {
          result = result.replace(`{{${k}}}`, String(v))
        })
      }
      return result
    },
  }),
}))

describe('CreateCreditNoteForm', () => {
  const mockInvoice = makeInvoiceForCreditNote()

  beforeEach(() => {
    vi.clearAllMocks()
    // Reset mutation state between tests — no leaking isPending from the
    // "displays loading state" case.
    mutationState.isPending = false
    mutationState.isError = false
    mutationState.error = null
  })

  it('renders form with all required fields', () => {
    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} />)

    expect(screen.getByText('Create Credit Note')).toBeInTheDocument()
    expect(screen.getByLabelText('Amount')).toBeInTheDocument()
    expect(screen.getByLabelText('Reason')).toBeInTheDocument()
    expect(screen.getByLabelText('Notes')).toBeInTheDocument()
    expect(screen.getByText('Save')).toBeInTheDocument()
    expect(screen.getByText('Cancel')).toBeInTheDocument()
  })

  it('displays invoice information and creditable amount', () => {
    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} />)

    expect(screen.getByText('INV-00001')).toBeInTheDocument()
    // TND currency → 3-decimal display (currency-aware toFixed).
    expect(screen.getByText('Remaining creditable: 1190.000')).toBeInTheDocument()
  })

  it('displays source line quantities at the product unit precision', async () => {
    const user = userEvent.setup()
    const invoice = {
      ...mockInvoice,
      lines: [{
        id: 'line-1',
        product_id: 'product-1',
        product_code: 'PCS-1',
        description: 'Precision part',
        quantity: 1,
        quantity_decimals: 3,
        unit_price: '10.000',
        tax_rate: '0.00',
        total: '10.000',
      }],
    }

    renderWithProviders(<CreateCreditNoteForm invoice={invoice} />)
    await user.click(screen.getByRole('button', { name: 'sales:creditNotes.form.lineBased' }))

    expect(screen.getByText('1.000')).toBeInTheDocument()
  })

  // The Save button is disabled until a reason is selected (post-Phase-4
  // behaviour), so Zod's "amount required" message never surfaces from a
  // plain click. Instead we check that the submit button is unclickable
  // with an empty form — same safety invariant, matching current UI.
  it('blocks submission when amount and reason are both empty', () => {
    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} />)

    const submitButton = screen.getByRole('button', { name: 'Save' })
    expect(submitButton).toBeDisabled()
  })

  it('validates that amount must be positive', async () => {
    const user = userEvent.setup()
    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} />)

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '-100')
    await user.tab()

    await waitFor(() => {
      expect(screen.getByText('Amount must be positive')).toBeInTheDocument()
    })
  })

  it('validates that amount does not exceed invoice total', async () => {
    const user = userEvent.setup()
    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} />)

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '1500')
    await user.tab()

    await waitFor(() => {
      expect(screen.getByText('Amount exceeds invoice total')).toBeInTheDocument()
    })
  })

  it('validates that amount does not exceed remaining creditable', async () => {
    const partiallyRefundedInvoice = makeInvoiceForCreditNote({
      balance_due: '690.0000',
    })

    const user = userEvent.setup()
    renderWithProviders(<CreateCreditNoteForm invoice={partiallyRefundedInvoice} />)

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '800')
    await user.tab()

    await waitFor(() => {
      expect(screen.getByText('Amount exceeds remaining creditable balance')).toBeInTheDocument()
    })
  })

  it('keeps submit disabled until a reason is selected', async () => {
    const user = userEvent.setup()
    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} />)

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '100')

    // Even with an amount filled in, Save stays disabled until reason
    // is chosen — no "Reason is required" message because the submit
    // handler is never invoked.
    const submitButton = screen.getByRole('button', { name: 'Save' })
    expect(submitButton).toBeDisabled()

    const reasonSelect = screen.getByLabelText('Reason')
    await user.selectOptions(reasonSelect, 'return')

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Save' })).not.toBeDisabled()
    })
  })

  it('allows selecting credit note reason', async () => {
    const user = userEvent.setup()
    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} />)

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

    // The mocked mutation calls `mockMutate`; the component's onSubmit
    // invokes the mutation with its own `onSuccess` handler. We simulate
    // the success side-effect by dispatching `onSuccess` from the mock.
    mockMutate.mockImplementation(
      (_req: unknown, opts?: { onSuccess?: (data: unknown) => void }) => {
        opts?.onSuccess?.({ id: 'credit-note-1' })
      },
    )

    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} onSuccess={onSuccess} />)

    const amountInput = screen.getByLabelText('Amount')
    await user.type(amountInput, '100')

    const reasonSelect = screen.getByLabelText('Reason')
    await user.selectOptions(reasonSelect, 'return')

    const notesInput = screen.getByLabelText('Notes')
    await user.type(notesInput, 'Customer returned product')

    const submitButton = screen.getByText('Save')
    await user.click(submitButton)

    await waitFor(() => {
      expect(onSuccess).toHaveBeenCalled()
    })
  })

  // Regression (money-campaign W1b MTP-DOC-20): the form used to validate
  // with `zodResolver(amountBasedSchema)` in BOTH modes. `amount` is
  // schema-required there but the `#amount` input is not rendered in
  // Line-Based mode, so the schema was permanently unsatisfiable and Save
  // silently no-opped — no mutation, no network request, no error. The
  // resolver now switches with `creditMode`.
  it('submits a LINE-BASED credit note (no amount typed) with the selected lines', async () => {
    const user = userEvent.setup()
    const invoice = {
      ...mockInvoice,
      lines: [
        {
          id: 'line-1',
          product_id: 'product-1',
          product_code: 'SKU-1',
          description: 'Widget',
          quantity: 2,
          unit_price: '40.000',
          tax_rate: '19.00',
          total: '95.200',
        },
        {
          id: 'line-2',
          product_id: 'product-2',
          product_code: 'SKU-2',
          description: 'Gadget',
          quantity: 3,
          unit_price: '20.000',
          tax_rate: '19.00',
          total: '71.400',
        },
      ],
    }

    renderWithProviders(<CreateCreditNoteForm invoice={invoice} />)

    await user.click(screen.getByRole('button', { name: 'sales:creditNotes.form.lineBased' }))
    // Select the FIRST line only (checkbox defaults its quantity to the full 2).
    await user.click(screen.getAllByRole('checkbox')[0])
    await user.selectOptions(screen.getByLabelText('Reason'), 'return')

    const submitButton = screen.getByRole('button', { name: 'Save' })
    expect(submitButton).toBeEnabled()
    await user.click(submitButton)

    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalledTimes(1)
    })
    expect(mockMutate.mock.calls[0][0]).toMatchObject({
      source_invoice_id: 'invoice-1',
      reason: 'return',
      lines: [{ line_id: 'line-1', quantity: 2 }],
    })
    // Line-based payloads must NOT carry an `amount` — the backend picks the
    // line-based branch off the presence of `lines`.
    expect(mockMutate.mock.calls[0][0]).not.toHaveProperty('amount')
  })

  it('still enforces the amount rules in AMOUNT mode after switching back from lines', async () => {
    const user = userEvent.setup()
    const invoice = {
      ...mockInvoice,
      lines: [{
        id: 'line-1',
        product_id: 'product-1',
        product_code: 'SKU-1',
        description: 'Widget',
        quantity: 2,
        unit_price: '40.000',
        tax_rate: '19.00',
        total: '95.200',
      }],
    }

    renderWithProviders(<CreateCreditNoteForm invoice={invoice} />)

    await user.click(screen.getByRole('button', { name: 'sales:creditNotes.form.lineBased' }))
    await user.click(screen.getByRole('button', { name: 'sales:creditNotes.form.amountBased' }))
    await user.selectOptions(screen.getByLabelText('Reason'), 'return')

    // Amount is empty -> the amount schema must still block the submit.
    await user.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => {
      expect(screen.getByText('Amount is required')).toBeInTheDocument()
    })
    expect(mockMutate).not.toHaveBeenCalled()
  })

  it('calls onCancel callback when cancel is clicked', async () => {
    const onCancel = vi.fn()
    const user = userEvent.setup()

    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} onCancel={onCancel} />)

    const cancelButton = screen.getByText('Cancel')
    await user.click(cancelButton)

    expect(onCancel).toHaveBeenCalled()
  })

  it('displays loading state when submitting', () => {
    // Flip the hoisted mutation state so `isSubmitting` is true on mount.
    mutationState.isPending = true

    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} />)

    // Submit button should show loading state and be disabled.
    expect(screen.getByText('Saving...')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Saving...' })).toBeDisabled()
  })

  it('pre-fills amount with remaining creditable when "Full Refund" button is clicked', async () => {
    const user = userEvent.setup()
    renderWithProviders(<CreateCreditNoteForm invoice={mockInvoice} />)

    const fullRefundButton = screen.getByText('Full Refund')
    await user.click(fullRefundButton)

    const amountInput = screen.getByLabelText('Amount') as HTMLInputElement
    // TND currency → 3-decimal canonical value.
    expect(amountInput.value).toBe('1190.000')
  })
})
