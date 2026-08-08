import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { CreateReturnNoteForm } from './CreateReturnNoteForm'
import { makeSourceDocument } from '../__fixtures__/returnNote'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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

/**
 * Translation mock.
 *
 * Maps the small subset of keys used by the return-note form and its
 * child selects to short English strings. Assertions in this file
 * reference those strings directly.
 *
 * Keys not in this map fall through to the key itself, which is good
 * enough for selector/ARIA-based queries that don't read text.
 */
const i18nMap: Record<string, string> = {
  'sales:returnNotes.reason.label': 'Reason for Return',
  'sales:returnNotes.reason.placeholder': 'Select a reason',
  'sales:returnNotes.reason.defective': 'Defective',
  'sales:returnNotes.reason.wrongItem': 'Wrong Item',
  'sales:returnNotes.reason.customerRegret': 'Customer Regret',
  'sales:returnNotes.reason.damagedInTransit': 'Damaged in Transit',
  'sales:returnNotes.reason.warranty': 'Warranty',
  'sales:returnNotes.reason.exchange': 'Exchange',
  'sales:returnNotes.reason.other': 'Other',
  'sales:returnNotes.condition.label': 'Condition',
  'sales:returnNotes.refundMethod.label': 'Refund Method',
  'sales:returnNotes.form.noLinesSelected':
    'Please select at least one line to return',
  'sales:returnNotes.form.autoCreateCreditNote':
    'Automatically create credit note',
  'sales:returnNotes.form.fullReturn': 'Full Return',
  'sales:returnNotes.form.partialReturn': 'Partial Return',
  'sales:returnNotes.form.selectLines': 'Select Lines to Return',
  'common:actions.save': 'Save',
  'common:actions.cancel': 'Cancel',
}

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallbackOrParams?: string | Record<string, unknown>) => {
      // Prefer the fallback when provided explicitly (old form behaviour),
      // otherwise use the translation map, otherwise the key.
      if (typeof fallbackOrParams === 'string') {
        return i18nMap[key] ?? fallbackOrParams
      }
      return i18nMap[key] ?? key
    },
  }),
}))

const mockSourceDocument = makeSourceDocument()

describe('CreateReturnNoteForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  const renderForm = (props: Record<string, unknown> = {}) => {
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

    it('displays source line quantities at the product unit precision', async () => {
      const user = userEvent.setup()
      renderForm({
        sourceDocument: makeSourceDocument({
          lines: [{
            ...makeSourceDocument().lines![0],
            quantity: '1',
            quantity_decimals: 3,
          }],
        }),
      })

      await user.click(screen.getByRole('button', { name: 'Partial Return' }))

      expect(screen.getByText('1.000')).toBeInTheDocument()
    })

    it('shows full return mode by default', () => {
      renderForm()

      const fullReturnButton = screen.getByText('Full Return')
      expect(fullReturnButton.closest('button')).toHaveClass(colorTokens.intent.primary.borderFocus)
    })

    it('shows return reason field as required', () => {
      renderForm()

      expect(screen.getByText(/Reason for Return/i)).toBeInTheDocument()
      expect(screen.getAllByText('*')[0]).toBeInTheDocument()
    })

    it('shows optional condition and refund method fields', () => {
      renderForm()

      // "Condition" is used by both ReturnConditionSelect and its inline
      // help text, so assert at least one label renders.
      expect(screen.getAllByText(/Condition/i).length).toBeGreaterThanOrEqual(1)
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

      expect(partialButton.closest('button')).toHaveClass(colorTokens.intent.primary.borderFocus)
      expect(screen.getByText('Select Lines to Return')).toBeInTheDocument()
    })

    it('hides return mode toggle when document has no lines', () => {
      renderForm({
        sourceDocument: makeSourceDocument({ lines: undefined }),
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
      expect(row).toHaveClass(colorTokens.intent.primary.bgSubtle)
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

      // `clear()` triggers `parseInt('') || 0` -> deletes the line and
      // removes the input. Use `{selectall}` + type to replace atomically.
      const quantityInput = screen.getAllByRole('spinbutton')[0]
      await user.click(quantityInput)
      await user.keyboard('{Control>}a{/Control}5')

      await waitFor(() => {
        expect(quantityInput).toHaveValue(5)
      })
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

      // The total appears both inside the selected table row (line total) and
      // in the "Return Total" summary — at minimum one of each renders.
      await waitFor(() => {
        expect(screen.getAllByText('1200.00').length).toBeGreaterThanOrEqual(1)
      })
    })

    it('updates total when quantity changes', async () => {
      const user = userEvent.setup()

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0])

      const quantityInput = screen.getAllByRole('spinbutton')[0]
      // Replace value atomically to avoid the clear-triggered deselect.
      await user.click(quantityInput)
      await user.keyboard('{Control>}a{/Control}5')

      // 5 * 100 * 1.2 = 600 — appears in the row total and/or summary.
      await waitFor(() => {
        expect(screen.getAllByText(/600\.00/).length).toBeGreaterThanOrEqual(1)
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
      expect(row).not.toHaveClass(colorTokens.intent.primary.bgSubtle)
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

    /**
     * Plan CF T8 / CF-D9. THE test the lane exists for: assert the POSTED BODY against
     * the backend contract. Before T8 nothing on either side did this — the backend
     * tests posted the real shape by hand while the client posted an invented one, so
     * both sides were green and the feature had never once worked.
     */
    it('posts the canonical document-create body for a full return', async () => {
      const user = userEvent.setup()

      renderForm({ sourceType: 'invoice' })

      const reasonSelect = screen.getAllByRole('combobox')[0]
      await user.selectOptions(reasonSelect, 'defective')

      const submitButton = screen.getByRole('button', { name: /save/i })
      await user.click(submitButton)

      await waitFor(() => {
        expect(mockMutate).toHaveBeenCalledWith(
          expect.objectContaining({
            // The three keys `CreateDocumentRequest` requires and the old payload never
            // sent — each one of them a 422 on its own.
            partner_id: 'partner-1',
            document_date: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
            currency: 'TND',
            // `source_document_id`, not the invented `source_invoice_id` (which is an
            // index-endpoint query FILTER, accepted by no create route).
            source_document_id: 'doc-1',
            return_reason: 'defective',
            lines: [
              expect.objectContaining({
                product_id: 'p1',
                description: 'Test product 1',
                quantity: '10',
                unit_price: '100.00',
              }),
              expect.objectContaining({ product_id: 'p2' }),
            ],
          }),
          expect.any(Object)
        )
      })

      // `auto_create_credit_note` is accepted by NOTHING in apps/api/app — zero
      // occurrences. Sending it was pure noise; it must not reappear.
      const payload = mockMutate.mock.calls[0][0] as Record<string, unknown>
      expect(payload).not.toHaveProperty('auto_create_credit_note')
      expect(payload).not.toHaveProperty('source_invoice_id')
      expect(payload).not.toHaveProperty('refund_method')
    })

    it('posts only the selected lines, at their own quantities, for a partial return', async () => {
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
            lines: [
              // A real line, not the `{line_id, quantity}` shape — which the server
              // rejects for missing `description` and `unit_price`, and whose
              // `line_id` is consumed by a different endpoint entirely.
              expect.objectContaining({
                product_id: 'p1',
                description: 'Test product 1',
                quantity: '10',
                unit_price: '100.00',
              }),
            ],
          }),
          expect.any(Object)
        )
      })

      const payload = mockMutate.mock.calls[0][0] as { lines: unknown[] }
      expect(payload.lines).toHaveLength(1)
    })

    /**
     * Quantities are decimal STRINGS (frontend gate I-4). `parseInt(…) || 0` and a
     * `min="1"` floor made a fractional return impossible for any unit with
     * `decimal_places > 0` — 0.5 kg silently became 1 kg on a stock document.
     */
    it('posts a fractional quantity as a decimal string', async () => {
      const user = userEvent.setup()

      renderForm({
        sourceDocument: makeSourceDocument({
          lines: [{
            ...makeSourceDocument().lines![0],
            quantity: '10',
            quantity_decimals: 3,
          }],
        }),
      })

      const partialButton = screen.getByText('Partial Return')
      await user.click(partialButton)

      const checkboxes = screen.getAllByRole('checkbox', { name: '' })
      await user.click(checkboxes[0])

      const quantityInput = screen.getByRole('spinbutton')
      await user.clear(quantityInput)
      await user.type(quantityInput, '0.5')

      const reasonSelect = screen.getAllByRole('combobox')[0]
      await user.selectOptions(reasonSelect, 'defective')

      await user.click(screen.getByRole('button', { name: /save/i }))

      await waitFor(() => {
        expect(mockMutate).toHaveBeenCalledWith(
          expect.objectContaining({
            lines: [expect.objectContaining({ quantity: '0.5' })],
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
