import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { CreateReturnNoteForm } from './CreateReturnNoteForm'

// Create mock mutation function
const mockMutate = vi.fn()

// Mock hooks
vi.mock('../hooks/useReturnNotes', () => ({
  useCreateReturnNote: () => ({
    mutate: mockMutate,
    isPending: false,
    isError: false,
    error: null,
  }),
}))

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback || key,
  }),
}))

const mockSourceDocument = {
  id: 'doc-1',
  document_number: 'INV-001',
  document_date: '2024-01-15',
  partner_name: 'ACME Corp',
  total: '1500.00',
  lines: [
    {
      id: 'line-1',
      product_id: 'p1',
      product_code: 'PROD-1',
      product_name: 'Product 1',
      description: 'Test product 1',
      quantity: 10,
      unit_price: '100.00',
      tax_rate: '20.00',
      total: '1200.00',
    },
    {
      id: 'line-2',
      product_id: 'p2',
      product_code: 'PROD-2',
      product_name: 'Product 2',
      description: 'Test product 2',
      quantity: 5,
      unit_price: '60.00',
      tax_rate: '20.00',
      total: '360.00',
    },
  ],
}

describe('CreateReturnNoteForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  const renderForm = (props = {}) => {
    return renderWithProviders(
      <CreateReturnNoteForm
        sourceDocument={mockSourceDocument}
        sourceType="invoice"
        {...props}
      />
    )
  }

  describe('Basic Rendering', () => {
    it('renders form with source document information', () => {
      renderForm()

      expect(screen.getByText('INV-001')).toBeInTheDocument()
      expect(screen.getByText('ACME Corp')).toBeInTheDocument()
      expect(screen.getByText('1500.00')).toBeInTheDocument()
    })

    it('shows full return mode by default', () => {
      renderForm()

      const fullReturnButton = screen.getByText('Full Return')
      expect(fullReturnButton.closest('button')).toHaveClass('border-blue-500')
    })

    it('shows return reason field as required', () => {
      renderForm()

      expect(screen.getByText(/Reason for Return/i)).toBeInTheDocument()
      expect(screen.getAllByText('*')[0]).toBeInTheDocument()
    })

    it('shows optional condition and refund method fields', () => {
      renderForm()

      expect(screen.getByText(/Condition/i)).toBeInTheDocument()
      expect(screen.getByText(/Refund Method/i)).toBeInTheDocument()
    })

    it('shows auto-create credit note checkbox for invoices', () => {
      renderForm({ sourceType: 'invoice' })

      expect(screen.getByText(/Automatically create credit note/i)).toBeInTheDocument()
    })

    it('hides auto-create credit note checkbox for delivery notes', () => {
      renderForm({ sourceType: 'delivery_note' })

      expect(screen.queryByText(/Automatically create credit note/i)).not.toBeInTheDocument()
    })
  })

  describe('Return Mode Toggle', () => {
    it('switches to partial return mode', async () => {
      const user = userEvent.setup()
      renderForm()

      const partialButton = screen.getByText('Partial Return')
      await user.click(partialButton)

      expect(partialButton.closest('button')).toHaveClass('border-blue-500')
      expect(screen.getByText('Select Lines to Return')).toBeInTheDocument()
    })

    it('hides return mode toggle when document has no lines', () => {
      renderForm({
        sourceDocument: { ...mockSourceDocument, lines: undefined },
      })

      expect(screen.queryByText('Full Return')).not.toBeInTheDocument()
      expect(screen.queryByText('Partial Return')).not.toBeInTheDocument()
    })
  })

  describe('Partial Return - Line Selection', () => {
    beforeEach(async () => {
      const user = userEvent.setup()
      renderForm()

      const partialButton = screen.getByText('Partial Return')
      await user.click(partialButton)
    })

    it('displays all document lines in table', () => {
      expect(screen.getByText('PROD-1')).toBeInTheDocument()
      expect(screen.getByText('PROD-2')).toBeInTheDocument()
      expect(screen.getByText('Test product 1')).toBeInTheDocument()
      expect(screen.getByText('Test product 2')).toBeInTheDocument()
    })

    it('allows selecting lines with checkboxes', async () => {
      const user = userEvent.setup()

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0])

      // Line should be highlighted
      const row = checkboxes[0].closest('tr')
      expect(row).toHaveClass('bg-blue-50')
    })

    it('shows quantity input when line is selected', async () => {
      const user = userEvent.setup()

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0])

      const quantityInputs = screen.getAllByRole('spinbutton')
      expect(quantityInputs.length).toBeGreaterThan(0)
    })

    it('initializes return quantity to max quantity', async () => {
      const user = userEvent.setup()

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0])

      const quantityInput = screen.getAllByRole('spinbutton')[0] as HTMLInputElement
      expect(quantityInput.value).toBe('10')
    })

    it('allows changing return quantity', async () => {
      const user = userEvent.setup()

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0])

      const quantityInput = screen.getAllByRole('spinbutton')[0]
      await user.clear(quantityInput)
      await user.type(quantityInput, '5')

      expect(quantityInput).toHaveValue(5)
    })

    it('prevents quantity exceeding max', async () => {
      const user = userEvent.setup()

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0])

      const quantityInput = screen.getAllByRole('spinbutton')[0] as HTMLInputElement
      expect(quantityInput.max).toBe('10')
    })

    it('calculates partial return total correctly', async () => {
      const user = userEvent.setup()

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0]) // Select line 1: 10 * 100 * 1.2 = 1200

      expect(screen.getByText('1200.00')).toBeInTheDocument()
    })

    it('updates total when quantity changes', async () => {
      const user = userEvent.setup()

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0])

      const quantityInput = screen.getAllByRole('spinbutton')[0]
      await user.clear(quantityInput)
      await user.type(quantityInput, '5')

      // 5 * 100 * 1.2 = 600
      await waitFor(() => {
        expect(screen.getByText('600.00')).toBeInTheDocument()
      })
    })

    it('shows error when no lines selected', () => {
      expect(screen.getByText('Please select at least one line to return')).toBeInTheDocument()
    })

    it('deselects line when unchecked', async () => {
      const user = userEvent.setup()

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0])
      await user.click(checkboxes[0])

      const row = checkboxes[0].closest('tr')
      expect(row).not.toHaveClass('bg-blue-50')
    })
  })

  describe('Form Validation', () => {
    it('disables submit when return reason not selected', () => {
      renderForm()

      const submitButton = screen.getByRole('button', { name: /save/i })
      expect(submitButton).toBeDisabled()
    })

    it('enables submit when return reason is selected (full return)', async () => {
      const user = userEvent.setup()
      renderForm()

      const reasonSelect = screen.getAllByRole('combobox')[0]
      await user.selectOptions(reasonSelect, 'defective')

      const submitButton = screen.getByRole('button', { name: /save/i })
      expect(submitButton).not.toBeDisabled()
    })

    it('disables submit when partial return has no lines selected', async () => {
      const user = userEvent.setup()
      renderForm()

      const partialButton = screen.getByText('Partial Return')
      await user.click(partialButton)

      const reasonSelect = screen.getAllByRole('combobox')[0]
      await user.selectOptions(reasonSelect, 'defective')

      const submitButton = screen.getByRole('button', { name: /save/i })
      expect(submitButton).toBeDisabled()
    })
  })

  describe('Form Submission', () => {
    beforeEach(() => {
      mockMutate.mockClear()
    })

    it('sends correct payload for full return', async () => {
      const user = userEvent.setup()

      renderForm({ sourceType: 'invoice' })

      const reasonSelect = screen.getAllByRole('combobox')[0]
      await user.selectOptions(reasonSelect, 'defective')

      const submitButton = screen.getByRole('button', { name: /save/i })
      await user.click(submitButton)

      await waitFor(() => {
        expect(mockMutate).toHaveBeenCalledWith(
          expect.objectContaining({
            return_reason: 'defective',
            source_invoice_id: 'doc-1',
            auto_create_credit_note: false,
          }),
          expect.any(Object)
        )
      })
    })

    it('sends lines for partial return', async () => {
      const user = userEvent.setup()

      renderForm()

      const partialButton = screen.getByText('Partial Return')
      await user.click(partialButton)

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0])

      const reasonSelect = screen.getAllByRole('combobox')[0]
      await user.selectOptions(reasonSelect, 'defective')

      const submitButton = screen.getByRole('button', { name: /save/i })
      await user.click(submitButton)

      await waitFor(() => {
        expect(mockMutate).toHaveBeenCalledWith(
          expect.objectContaining({
            lines: [{ line_id: 'line-1', quantity: 10 }],
          }),
          expect.any(Object)
        )
      })
    })

    it('includes auto_create_credit_note when checked', async () => {
      const user = userEvent.setup()

      renderForm({ sourceType: 'invoice' })

      const autoCreateCheckbox = screen.getByRole('checkbox', { name: /automatically create credit note/i })
      await user.click(autoCreateCheckbox)

      const reasonSelect = screen.getAllByRole('combobox')[0]
      await user.selectOptions(reasonSelect, 'defective')

      const submitButton = screen.getByRole('button', { name: /save/i })
      await user.click(submitButton)

      await waitFor(() => {
        expect(mockMutate).toHaveBeenCalledWith(
          expect.objectContaining({
            auto_create_credit_note: true,
          }),
          expect.any(Object)
        )
      })
    })
  })

  describe('Cancel Button', () => {
    it('calls onCancel when cancel button clicked', async () => {
      const user = userEvent.setup()
      const onCancel = vi.fn()

      renderForm({ onCancel })

      const cancelButton = screen.getByRole('button', { name: /cancel/i })
      await user.click(cancelButton)

      expect(onCancel).toHaveBeenCalled()
    })
  })
})
