import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QuickStockAdjustmentModal } from '../components/QuickStockAdjustmentModal'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => (typeof second === 'string' ? second : key),
  }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ to, children }: { to: string; children: React.ReactNode }) => <a href={to}>{children}</a>,
}))

const grantedPermissions = new Set<string>(['inventory.adjustments.post', 'products.update'])
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: (p: string) => grantedPermissions.has(p) }),
}))

vi.mock('../components/LotSelect', () => ({
  LotSelect: ({ value, onChange }: { value: string; onChange: (v: string) => void }) => (
    <select
      aria-label="line.lot"
      value={value}
      onChange={(event) => {
        onChange(event.target.value)
      }}
    >
      <option value="">—</option>
      <option value="lot-1">LOT-1</option>
    </select>
  ),
}))

const freshLevel = {
  quantity: '50.0000',
  // The PRODUCT UNIT's precision, deliberately NOT the storage scale of 4.
  quantity_decimals: 3,
  requires_batch_tracking: false,
  has_lots_at_location: false,
}

const levelState: {
  data: typeof freshLevel | undefined
  isPending: boolean
  isError: boolean
} = { data: freshLevel, isPending: false, isError: false }

const createMutate = vi.fn()

vi.mock('../api/queries', () => ({
  useFreshStockLevel: () => ({ ...levelState, refetch: vi.fn() }),
  useCreateStockAdjustment: () => ({
    mutateAsync: createMutate,
    isPending: false,
  }),
}))

beforeEach(() => {
  createMutate.mockReset()
  createMutate.mockResolvedValue({ id: 'adj-1' })
  levelState.data = freshLevel
  levelState.isPending = false
  levelState.isError = false
  levelState.data = { ...freshLevel }
})

function renderModal(overrides: Partial<Parameters<typeof QuickStockAdjustmentModal>[0]> = {}) {
  const onClose = vi.fn()
  render(
    <QuickStockAdjustmentModal
      open
      productId="prod-1"
      productName="Widget"
      locationId="loc-1"
      onClose={onClose}
      {...overrides}
    />,
  )
  return { onClose }
}

describe('QuickStockAdjustmentModal — the D15b preflight', () => {
  it('FAILS CLOSED while the fresh read is in flight', () => {
    levelState.isPending = true
    levelState.data = undefined
    renderModal()

    // No form, and above all no submit: a post inside the fetch window would
    // have authored a FABRICATED anchor of '0'.
    expect(screen.getByText('quickModal.loading')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'quickModal.submit' })).not.toBeInTheDocument()
  })

  it('FAILS CLOSED when the fresh read errors', () => {
    levelState.isError = true
    levelState.data = undefined
    renderModal()

    // /stock-levels/{p}/{l} is a firstOrFail(), so a product with no stock row
    // at this location 404s — which used to leave a fully usable form authoring
    // against '0'.
    expect(screen.getByText('quickModal.loadFailed')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'quickModal.submit' })).not.toBeInTheDocument()
  })
})

describe('QuickStockAdjustmentModal — unit precision', () => {
  /**
   * The guard CARRIED FORWARD from the deleted hand-rolled modal on
   * StockLevelsPage. It comes from the UoM display-precision lane, whose
   * baselines must stay empty: quantities render at the PRODUCT UNIT's
   * precision (3 dp here), never at the storage scale of 4.
   */
  it('renders the observed quantity at the product unit precision, not the storage scale', () => {
    renderModal()

    // Both the current quantity and the (as yet unchanged) resulting quantity
    // render it, and BOTH must use the unit's precision.
    expect(screen.getAllByText('50.000').length).toBeGreaterThan(0)
    expect(screen.queryByText('50.0000')).not.toBeInTheDocument()
  })

  it('renders the resulting quantity at the product unit precision too', async () => {
    const user = userEvent.setup()
    renderModal()

    await user.type(screen.getByLabelText(/line.quantity/i), '2.5')

    await waitFor(() => {
      expect(screen.getByText('52.500')).toBeInTheDocument()
    })
    expect(screen.queryByText('52.5000')).not.toBeInTheDocument()
  })
})

describe('QuickStockAdjustmentModal — payload', () => {
  it('sends STRINGS, a signed delta and the fresh anchor', async () => {
    const user = userEvent.setup()
    renderModal()

    await user.selectOptions(screen.getByLabelText('line.reason'), 'adjustment_negative')
    await user.type(screen.getByLabelText(/line.quantity/i), '3')
    await user.click(screen.getByRole('button', { name: 'quickModal.submit' }))

    await waitFor(() => {
      expect(createMutate).toHaveBeenCalledTimes(1)
    })

    const payload = createMutate.mock.calls[0]?.[0] as {
      post_immediately: boolean
      acknowledge_stale?: boolean
      ignore_reservations?: boolean
      lines: { delta_quantity: string; observed_before: string; batch_uuid: string | null }[]
    }

    expect(payload.post_immediately).toBe(true)
    // Negated at STRING level, never `-Number(x)`.
    expect(payload.lines[0]?.delta_quantity).toBe('-3')
    expect(typeof payload.lines[0]?.delta_quantity).toBe('string')
    // The FRESH anchor, not a cached list value.
    expect(payload.lines[0]?.observed_before).toBe('50.0000')
    // No override flags on a first, unrefused submit.
    expect(payload.acknowledge_stale).toBeUndefined()
    expect(payload.ignore_reservations).toBeUndefined()
  })

  it('offers the lot picker and sends batch_uuid for lot-tracked stock', async () => {
    const user = userEvent.setup()
    levelState.data = { ...freshLevel, requires_batch_tracking: true, has_lots_at_location: true }
    renderModal()

    // Without this field the backend's BATCH_REQUIRED_FOR_LINE message — which
    // instructs the operator to name the lot — was unfollowable, and lot-tracked
    // stock could not be decremented from this screen at all.
    const lot = screen.getByLabelText('line.lot')
    await user.selectOptions(screen.getByLabelText('line.reason'), 'adjustment_negative')
    await user.selectOptions(lot, 'lot-1')
    await user.type(screen.getByLabelText(/line.quantity/i), '2')
    await user.click(screen.getByRole('button', { name: 'quickModal.submit' }))

    await waitFor(() => {
      expect(createMutate).toHaveBeenCalledTimes(1)
    })
    const payload = createMutate.mock.calls[0]?.[0] as {
      lines: { batch_uuid: string | null }[]
    }
    expect(payload.lines[0]?.batch_uuid).toBe('lot-1')
  })

  it('blocks submit until a required lot is chosen', async () => {
    const user = userEvent.setup()
    levelState.data = { ...freshLevel, requires_batch_tracking: true, has_lots_at_location: true }
    renderModal()

    await user.selectOptions(screen.getByLabelText('line.reason'), 'adjustment_negative')
    await user.type(screen.getByLabelText(/line.quantity/i), '2')

    expect(screen.getByRole('button', { name: 'quickModal.submit' })).toBeDisabled()
  })

  it('hides damage and write_off for a lot-tracked product', () => {
    levelState.data = { ...freshLevel, requires_batch_tracking: true, has_lots_at_location: true }
    renderModal()

    const options = Array.from(
      screen.getByLabelText('line.reason').querySelectorAll('option'),
    ).map((option) => option.getAttribute('value'))

    // They are REFUSED server-side (USE_BATCH_WRITE_OFF), so offering them would
    // be a surprise the reason filter exists to prevent.
    expect(options).toEqual(['adjustment_positive', 'adjustment_negative'])
  })
})
