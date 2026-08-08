import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AcknowledgeableRefusalDialog } from '../components/AcknowledgeableRefusalDialog'
import type { ApiErrorEnvelope } from '../api/refusals'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => (typeof second === 'string' ? second : key),
  }),
}))

const staleRefusal: ApiErrorEnvelope = {
  code: 'STOCK_MOVED_SINCE_AUTHORING',
  message: 'moved',
  details: {
    lines: [
      {
        // NULL because an immediate-post refusal rolls its draft back with the
        // transaction — the dialog must key on (product, variant, lot) instead.
        line_id: null,
        product_id: 'prod-1',
        variant_id: null,
        batch_uuid: 'lot-abc',
        observed_before: '10.0000',
        quantity_before: '15.0000',
        // NOT 4: the product unit's precision, carried on the payload precisely
        // so this component never falls back to a literal scale.
        quantity_decimals: 3,
      },
    ],
  },
}

const availabilityRefusal: ApiErrorEnvelope = {
  code: 'ADJUSTMENT_EXCEEDS_AVAILABLE',
  message: 'exceeds',
  details: {
    product_id: 'prod-1',
    location_id: 'loc-1',
    quantity_before: '5.0000',
    reserved: '3.0000',
    available: '2.0000',
    delta_quantity: '-4.0000',
    quantity_decimals: 2,
    overridable: true,
  },
}

function renderDialog(overrides: Partial<Parameters<typeof AcknowledgeableRefusalDialog>[0]> = {}) {
  const onApplyAnyway = vi.fn()
  const onReAnchor = vi.fn()
  const onDismiss = vi.fn()

  render(
    <AcknowledgeableRefusalDialog
      open
      code="STOCK_MOVED_SINCE_AUTHORING"
      refusal={staleRefusal}
      origin="unsaved-form"
      canOverride
      onDismiss={onDismiss}
      onApplyAnyway={onApplyAnyway}
      onReAnchor={onReAnchor}
      {...overrides}
    />,
  )

  return { onApplyAnyway, onReAnchor, onDismiss }
}

describe('AcknowledgeableRefusalDialog', () => {
  it('renders the staleness code with quantities at the payload precision', () => {
    renderDialog()

    expect(screen.getByText('refusal.STOCK_MOVED_SINCE_AUTHORING')).toBeInTheDocument()
    // 3 dp from the payload, NOT the storage scale of 4 — a literal 4 here is
    // exactly what the quantity-display ratchet forbids.
    expect(screen.getByText('10.000')).toBeInTheDocument()
    expect(screen.getByText('15.000')).toBeInTheDocument()
    expect(screen.queryByText('10.0000')).not.toBeInTheDocument()
  })

  it('renders a refused line whose line_id is null', () => {
    renderDialog()

    // The lot identifies the row; nothing depends on a persisted line id.
    expect(screen.getByText('lot-abc')).toBeInTheDocument()
    expect(screen.getByText('prod-1')).toBeInTheDocument()
  })

  it('renders the availability code with its own payload precision', () => {
    renderDialog({ code: 'ADJUSTMENT_EXCEEDS_AVAILABLE', refusal: availabilityRefusal })

    expect(screen.getByText('refusal.ADJUSTMENT_EXCEEDS_AVAILABLE')).toBeInTheDocument()
    expect(screen.getByText('5.00')).toBeInTheDocument()
    expect(screen.getByText('2.00')).toBeInTheDocument()
    expect(screen.getByText('-4.00')).toBeInTheDocument()
  })

  it('labels the recovery action by PERSISTENCE STATE, not by page', async () => {
    const user = userEvent.setup()

    const unsaved = renderDialog({ origin: 'unsaved-form' })
    await user.click(screen.getByRole('button', { name: 'refusal.recompute' }))
    expect(unsaved.onReAnchor).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('button', { name: 'refusal.reAnchor' })).not.toBeInTheDocument()
  })

  it('offers the server-side re-anchor only when a draft is persisted', async () => {
    const user = userEvent.setup()

    const persisted = renderDialog({ origin: 'persisted-draft' })
    await user.click(screen.getByRole('button', { name: 'refusal.reAnchor' }))
    expect(persisted.onReAnchor).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('button', { name: 'refusal.recompute' })).not.toBeInTheDocument()
  })

  it('never auto-retries: applying requires an explicit click', async () => {
    const user = userEvent.setup()
    const { onApplyAnyway } = renderDialog()

    expect(onApplyAnyway).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'refusal.applyAnyway' }))
    expect(onApplyAnyway).toHaveBeenCalledTimes(1)
  })

  it('hides "apply anyway" without the posting permission', () => {
    renderDialog({ canOverride: false })

    // Overriding an integrity guard is a POSTING act; a create-only author must
    // not be offered it at all.
    expect(screen.queryByRole('button', { name: 'refusal.applyAnyway' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'refusal.recompute' })).toBeInTheDocument()
  })

  it('falls back to the generic message when details are malformed', () => {
    renderDialog({
      refusal: { code: 'STOCK_MOVED_SINCE_AUTHORING', message: 'x', details: { lines: 'nope' } },
    })

    // The narrowing returned null, so no table is rendered — and the dialog
    // still stands rather than crashing.
    expect(screen.getByText('refusal.STOCK_MOVED_SINCE_AUTHORING')).toBeInTheDocument()
    expect(screen.queryByText('lot-abc')).not.toBeInTheDocument()
  })
})
