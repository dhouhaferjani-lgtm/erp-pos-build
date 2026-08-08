import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { CancelInvoiceModal } from '../CancelInvoiceModal'
import type { CanCancelResponse } from '../../hooks/useCancelInvoice'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: Record<string, unknown>) =>
      options && Object.keys(options).length > 0
        ? `${key}:${JSON.stringify(options)}`
        : key,
  }),
}))

const onSubmit = vi.fn()
const onClose = vi.fn()

function canCancel(overrides: Partial<CanCancelResponse> = {}): CanCancelResponse {
  return {
    can_cancel: true,
    reason_code: null,
    status: 'posted',
    requires_return_decision: true,
    goods_issued: true,
    delivered_quantities: [],
    return_decision: null,
    ...overrides,
  }
}

function renderModal(overrides: Partial<Parameters<typeof CancelInvoiceModal>[0]> = {}) {
  return render(
    <CancelInvoiceModal
      isOpen
      onClose={onClose}
      invoiceNumber="INV-2026-0001"
      canCancel={canCancel()}
      canCancelResolved
      isSubmitting={false}
      onSubmit={onSubmit}
      {...overrides}
    />,
  )
}

/**
 * T10 (plan CF §3) — the guided cancel modal.
 *
 * The single most important group here is the OPTION → MODE table, asserted PER BRANCH
 * rather than per option: the radio the user clicks is NOT always what gets posted.
 * Option 3 posts `no_return` when goods were issued and `no_goods_issued` when they were
 * not, because "the goods stayed out" and "the goods never left" are different facts —
 * and recording the first when the second is true would tell an auditor the customer
 * kept units that never shipped.
 */
describe('CancelInvoiceModal', () => {
  beforeEach(() => {
    onSubmit.mockClear()
    onClose.mockClear()
  })

  describe('the modal always renders', () => {
    it('renders the reason field even for a services-only invoice', () => {
      renderModal({ canCancel: canCancel({ requires_return_decision: false, goods_issued: false }) })

      expect(screen.getByText('sales:invoices.cancelFlow.reasonLabel')).toBeInTheDocument()
      // Only the OPTION GROUP is conditional — `reason` is required|max:500 on the
      // server and has no other UI.
      expect(screen.queryByText('sales:invoices.cancelFlow.goodsQuestion')).not.toBeInTheDocument()
    })

    it('renders the option group when the invoice has physical lines', () => {
      renderModal()

      expect(screen.getByText('sales:invoices.cancelFlow.goodsQuestion')).toBeInTheDocument()
      expect(screen.getByText('sales:invoices.cancelFlow.option1.label')).toBeInTheDocument()
      expect(screen.getByText('sales:invoices.cancelFlow.option2.label')).toBeInTheDocument()
      expect(screen.getByText('sales:invoices.cancelFlow.option3.label')).toBeInTheDocument()
    })
  })

  describe('option → mode, per branch', () => {
    it('a services-only invoice posts not_applicable with no click required', async () => {
      const user = userEvent.setup()
      renderModal({ canCancel: canCancel({ requires_return_decision: false, goods_issued: false }) })

      await user.type(screen.getByRole('textbox'), 'Duplicate')
      await user.click(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' }))

      await waitFor(() => {
        expect(onSubmit).toHaveBeenCalledWith({ reason: 'Duplicate', mode: 'not_applicable' })
      })
    })

    it('option 3 posts no_goods_issued when nothing was delivered', async () => {
      const user = userEvent.setup()
      renderModal({ canCancel: canCancel({ goods_issued: false }) })

      await user.type(screen.getByRole('textbox'), 'Never shipped')
      await user.click(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' }))

      await waitFor(() => {
        // NOT `no_return` — the radio's own semantic name is not what gets posted here.
        expect(onSubmit).toHaveBeenCalledWith({ reason: 'Never shipped', mode: 'no_goods_issued' })
      })
    })

    it('option 3 posts no_return when goods were issued', async () => {
      const user = userEvent.setup()
      renderModal()

      await user.type(screen.getByRole('textbox'), 'Customer kept them')
      await user.click(screen.getByRole('radio', { name: /option3/ }))
      await user.click(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' }))

      await waitFor(() => {
        expect(onSubmit).toHaveBeenCalledWith({ reason: 'Customer kept them', mode: 'no_return' })
      })
    })

    it('option 1 posts will_return with no date', async () => {
      const user = userEvent.setup()
      renderModal()

      await user.type(screen.getByRole('textbox'), 'Coming back')
      await user.click(screen.getByRole('radio', { name: /option1/ }))
      await user.click(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' }))

      await waitFor(() => {
        // `returned_on` is PROHIBITED by the server for every mode but already_returned.
        expect(onSubmit).toHaveBeenCalledWith({ reason: 'Coming back', mode: 'will_return' })
      })
    })

    it('option 2 posts already_returned with the date, defaulted to today', async () => {
      const user = userEvent.setup()
      renderModal()

      await user.type(screen.getByRole('textbox'), 'Already back')
      await user.click(screen.getByRole('radio', { name: /option2/ }))
      await user.click(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' }))

      await waitFor(() => {
        expect(onSubmit).toHaveBeenCalledWith({
          reason: 'Already back',
          mode: 'already_returned',
          returnedOn: new Date().toISOString().slice(0, 10),
        })
      })
    })
  })

  describe('the no-delivery branch', () => {
    it('disables options 1 and 2 and explains why', () => {
      renderModal({ canCancel: canCancel({ goods_issued: false }) })

      const radios = screen.getAllByRole('radio')
      expect(radios[0]).toBeDisabled()
      expect(radios[1]).toBeDisabled()
      expect(radios[2]).not.toBeDisabled()
      // Disabled WITH A REASON rather than hidden — the user learns why the choice is
      // unavailable instead of wondering where it went.
      expect(screen.getByText('sales:invoices.cancelFlow.optionsDisabledReason')).toBeInTheDocument()
    })

    it('pre-selects option 3, the only live choice', () => {
      renderModal({ canCancel: canCancel({ goods_issued: false }) })

      expect(screen.getAllByRole('radio')[2]).toBeChecked()
    })
  })

  describe('before /can-cancel resolves (the state the live app starts in)', () => {
    /**
     * Gate CF round 1, Blocker B3. `requires_return_decision` used to default to FALSE —
     * failing OPEN, the direction that skips the goods question entirely and posts
     * `not_applicable`. The modal mounts while `/can-cancel` is still in flight, and
     * permanently if it errors, so this was reachable in one click and wrote a false
     * statement about physical reality onto a fiscal document.
     */
    it('still asks the goods question when can-cancel is unresolved', () => {
      renderModal({ canCancel: undefined, canCancelResolved: false })

      expect(screen.getByText('sales:invoices.cancelFlow.goodsQuestion')).toBeInTheDocument()
    })

    it('blocks submit until can-cancel has answered', () => {
      renderModal({ canCancel: undefined, canCancelResolved: false })

      expect(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' })).toBeDisabled()
      expect(screen.getByRole('status')).toHaveTextContent('sales:invoices.cancelFlow.loading')
    })

    /**
     * Gate CF round 1, MAJOR M1 + fiscal IMPORTANT. RHF captures `defaultValues` once at
     * mount, so the plan-mandated "option 3 pre-selected" for the `!goods_issued` branch
     * never happened in the app — the previous test only passed because it injected
     * `canCancel` AT MOUNT, the one state the live app never starts in.
     */
    it('applies the branch defaults once can-cancel resolves after mount', async () => {
      const { rerender } = render(
        <CancelInvoiceModal
          isOpen
          onClose={onClose}
          invoiceNumber="INV-2026-0001"
          canCancel={undefined}
          canCancelResolved={false}
          isSubmitting={false}
          onSubmit={onSubmit}
        />,
      )

      rerender(
        <CancelInvoiceModal
          isOpen
          onClose={onClose}
          invoiceNumber="INV-2026-0001"
          canCancel={canCancel({ goods_issued: false })}
          canCancelResolved
          isSubmitting={false}
          onSubmit={onSubmit}
        />,
      )

      await waitFor(() => {
        expect(screen.getAllByRole('radio')[2]).toBeChecked()
      })
    })
  })

  it('pre-selects nothing when all three options are live', () => {
    renderModal()

    for (const radio of screen.getAllByRole('radio')) {
      expect(radio).not.toBeChecked()
    }
  })

  it('shows the date field only for option 2', async () => {
    const user = userEvent.setup()
    renderModal()

    expect(screen.queryByText('sales:invoices.cancelFlow.option2.dateLabel')).not.toBeInTheDocument()

    await user.click(screen.getByRole('radio', { name: /option2/ }))

    expect(screen.getByText('sales:invoices.cancelFlow.option2.dateLabel')).toBeInTheDocument()
  })

  /**
   * Post-CF-D7 option 2 is a FULL-quantity, one-click, SEALED action. A user who got 3
   * of 5 units back has no in-modal escape, so the copy has to say so AND point at the
   * standalone partial surface.
   */
  it('states option 2 scope and points at the partial flow', () => {
    renderModal()

    expect(screen.getByText('sales:invoices.cancelFlow.option2.scope')).toBeInTheDocument()
    expect(screen.getByText('sales:invoices.cancelFlow.option2.partialPointer')).toBeInTheDocument()
  })

  it('uses the branch-specific option 3 hint', () => {
    const { unmount } = renderModal()
    expect(screen.getByText('sales:invoices.cancelFlow.option3.hint.goodsIssued')).toBeInTheDocument()
    unmount()

    renderModal({ canCancel: canCancel({ goods_issued: false }) })
    // Claiming "the goods stay out" when nothing shipped would be a false statement
    // about physical reality, recorded on a fiscal document.
    expect(screen.getByText('sales:invoices.cancelFlow.option3.hint.noGoodsIssued')).toBeInTheDocument()
  })

  describe('error rendering', () => {
    it('renders a period refusal inline on the date field, not as a banner', async () => {
      const user = userEvent.setup()
      renderModal({ errorCode: 'RETURN_PERIOD_FILED' })

      await user.click(screen.getByRole('radio', { name: /option2/ }))

      expect(screen.getByText('sales:invoices.cancelFlow.errors.RETURN_PERIOD_FILED')).toBeInTheDocument()
      expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    })

    it.each([
      'DOCUMENT_HAS_PAYMENTS',
      'RETURN_NOTHING_DELIVERED',
      'RETURN_LOCATION_UNRESOLVED',
      'RETURN_LOCATION_AMBIGUOUS',
      'RETURN_DECISION_ALREADY_RECORDED',
      'RETURN_DECISION_FORBIDDEN',
    ])('renders %s as a banner', (code) => {
      renderModal({ errorCode: code })

      expect(screen.getByRole('alert')).toHaveTextContent(`sales:invoices.cancelFlow.errors.${code}`)
    })

    it('names the product and remaining quantity on a quantity refusal', () => {
      renderModal({
        errorCode: 'RETURN_EXCEEDS_DELIVERED_QUANTITY',
        errorDetails: { product_id: 'prod-1', remaining_returnable: '2.0000' },
      })

      // After CF-D7 there is no line UI at all, so an unnamed refusal is unactionable.
      expect(screen.getByRole('alert')).toHaveTextContent('prod-1')
      expect(screen.getByRole('alert')).toHaveTextContent('2.0000')
    })

    it('falls back to a translated message for a present but unrecognised code', () => {
      renderModal({ errorCode: 'SOMETHING_NOBODY_TYPED', submitFailed: true })

      expect(screen.getByRole('alert')).toHaveTextContent('sales:invoices.cancelFlow.errors.unknown')
    })

    /**
     * Gate CF round 1, Blocker B2 — the case the previous test only appeared to cover.
     * It passed a PRESENT-but-unknown code; the live silent paths carry NO code at all
     * (a Laravel `ValidationException` `{message, errors}`, and the surviving generic
     * flat envelope `{error: <string>, code: <string>}` T6 left in place). Both make
     * `extractErrorCode` return undefined, and the banner was gated on the code being
     * truthy — so the user clicked Cancel and nothing whatsoever happened.
     */
    it('falls back to a translated message when the failed submit carried NO code', () => {
      renderModal({ errorCode: undefined, submitFailed: true })

      expect(screen.getByRole('alert')).toHaveTextContent('sales:invoices.cancelFlow.errors.unknown')
    })

    it('shows no banner before any submit has failed', () => {
      renderModal({ errorCode: undefined, submitFailed: false })

      expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    })

    it('stays open after an error so the user can fix and retry', () => {
      renderModal({ errorCode: 'RETURN_NOTHING_DELIVERED' })

      expect(screen.getByText('sales:invoices.cancelFlow.reasonLabel')).toBeInTheDocument()
      expect(onClose).not.toHaveBeenCalled()
    })
  })

  it('uses canonical atoms — no raw form controls', () => {
    const { container } = renderModal()

    // Every control comes from the atom layer; a raw control here would also be a new
    // design-system baseline entry, which CF-D10 forbids.
    expect(container.querySelector('select')).toBeNull()
    for (const input of Array.from(container.querySelectorAll('input'))) {
      expect(['radio', 'date']).toContain(input.getAttribute('type'))
    }
  })
})
