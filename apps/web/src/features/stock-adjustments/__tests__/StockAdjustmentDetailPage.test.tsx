import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { StockAdjustmentDetailPage } from '../pages/StockAdjustmentDetailPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => (typeof second === 'string' ? second : key),
  }),
}))

const navigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useParams: () => ({ id: 'adj-1' }),
  useNavigate: () => navigate,
}))

const grantedPermissions = new Set<string>()
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: (p: string) => grantedPermissions.has(p) }),
}))

const postMutate = vi.fn()
const cancelMutate = vi.fn()
const correctMutate = vi.fn()
const updateMutate = vi.fn()

const detailState: { data: unknown; isLoading: boolean; isError: boolean } = {
  data: undefined,
  isLoading: false,
  isError: false,
}

vi.mock('../api/queries', () => ({
  useStockAdjustment: () => detailState,
  usePostStockAdjustment: () => ({ mutateAsync: postMutate, isPending: false }),
  useCancelStockAdjustment: () => ({ mutateAsync: cancelMutate, isPending: false }),
  useCorrectStockAdjustment: () => ({ mutateAsync: correctMutate, isPending: false }),
  useUpdateStockAdjustment: () => ({ mutateAsync: updateMutate, isPending: false }),
}))

function makeAdjustment(overrides: Record<string, unknown> = {}) {
  return {
    id: 'adj-1',
    adjustment_number: null,
    status: 'draft',
    note: null,
    location_id: 'loc-1',
    location_name: 'Main',
    occurred_at: '2026-08-08T10:00:00+00:00',
    created_by_name: 'Ada',
    posted_by_name: null,
    cancellation_reason: null,
    corrects_adjustment_id: null,
    correction_id: null,
    stale_acknowledged_at: null,
    reservations_ignored_at: null,
    lines: [
      {
        id: 'line-1',
        product_id: 'prod-1',
        product_name: 'Widget',
        variant_id: null,
        batch_uuid: null,
        batch_number: null,
        reason_code: 'adjustment_positive',
        delta_quantity: '2.0000',
        observed_before: '10.0000',
        quantity_before: null,
        quantity_after: null,
        movement_id: null,
        line_note: null,
        quantity_decimals: 3,
      },
    ],
    ...overrides,
  }
}

beforeEach(() => {
  postMutate.mockReset()
  cancelMutate.mockReset()
  correctMutate.mockReset()
  updateMutate.mockReset()
  navigate.mockReset()
  grantedPermissions.clear()
  detailState.data = makeAdjustment()
  detailState.isLoading = false
  detailState.isError = false
})

describe('StockAdjustmentDetailPage — affordances by status AND permission', () => {
  it('shows nothing actionable without permissions, whatever the status', () => {
    render(<StockAdjustmentDetailPage />)

    expect(screen.queryByRole('button', { name: 'detail.post' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'detail.cancel' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'detail.correct' })).not.toBeInTheDocument()
  })

  it('shows post and cancel on a DRAFT with their own permissions', () => {
    grantedPermissions.add('inventory.adjustments.post')
    grantedPermissions.add('inventory.adjustments.cancel')
    render(<StockAdjustmentDetailPage />)

    expect(screen.getByRole('button', { name: 'detail.post' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'detail.cancel' })).toBeInTheDocument()
    // Correct is a POSTED-only action.
    expect(screen.queryByRole('button', { name: 'detail.correct' })).not.toBeInTheDocument()
  })

  it('shows correct only on a POSTED, not-yet-corrected document', () => {
    grantedPermissions.add('inventory.adjustments.create')
    detailState.data = makeAdjustment({ status: 'posted', adjustment_number: 'ADJ-2026-0001' })
    render(<StockAdjustmentDetailPage />)

    expect(screen.getByRole('button', { name: 'detail.correct' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'detail.post' })).not.toBeInTheDocument()
  })

  it('hides correct once a correction exists, and on a correction itself', () => {
    grantedPermissions.add('inventory.adjustments.create')

    detailState.data = makeAdjustment({ status: 'posted', correction_id: 'adj-2' })
    const { unmount } = render(<StockAdjustmentDetailPage />)
    expect(screen.queryByRole('button', { name: 'detail.correct' })).not.toBeInTheDocument()
    unmount()

    detailState.data = makeAdjustment({ status: 'posted', corrects_adjustment_id: 'adj-0' })
    render(<StockAdjustmentDetailPage />)
    expect(screen.queryByRole('button', { name: 'detail.correct' })).not.toBeInTheDocument()
  })
})

describe('StockAdjustmentDetailPage — failures reach the user', () => {
  it('renders an error instead of spinning forever when the fetch fails', () => {
    detailState.isError = true
    detailState.data = undefined
    render(<StockAdjustmentDetailPage />)

    expect(screen.getByText('detail.loadFailed')).toBeInTheDocument()
    expect(screen.queryByText('common:status.loading')).not.toBeInTheDocument()
  })

  it('surfaces a correct() refusal instead of swallowing it', async () => {
    const user = userEvent.setup()
    grantedPermissions.add('inventory.adjustments.create')
    detailState.data = makeAdjustment({ status: 'posted' })
    correctMutate.mockRejectedValue({
      response: { data: { error: { code: 'ADJUSTMENT_ALREADY_CORRECTED', message: 'x' } } },
    })

    render(<StockAdjustmentDetailPage />)
    await user.click(screen.getByRole('button', { name: 'detail.correct' }))

    await waitFor(() => {
      expect(screen.getByText('refusal.ADJUSTMENT_ALREADY_CORRECTED')).toBeInTheDocument()
    })
    expect(navigate).not.toHaveBeenCalled()
  })

  it('navigates to the contra draft that correct() creates', async () => {
    const user = userEvent.setup()
    grantedPermissions.add('inventory.adjustments.create')
    detailState.data = makeAdjustment({ status: 'posted' })
    correctMutate.mockResolvedValue({ id: 'adj-contra' })

    render(<StockAdjustmentDetailPage />)
    await user.click(screen.getByRole('button', { name: 'detail.correct' }))

    await waitFor(() => {
      expect(navigate).toHaveBeenCalledWith('/inventory/stock-adjustments/adj-contra')
    })
  })

  it('surfaces a cancel() refusal', async () => {
    const user = userEvent.setup()
    grantedPermissions.add('inventory.adjustments.cancel')
    cancelMutate.mockRejectedValue({
      response: { data: { error: { code: 'INVALID_ADJUSTMENT_STATE', message: 'x' } } },
    })

    render(<StockAdjustmentDetailPage />)
    await user.click(screen.getByRole('button', { name: 'detail.cancel' }))
    await user.click(screen.getByRole('button', { name: 'detail.cancelConfirm' }))

    await waitFor(() => {
      expect(screen.getByText('refusal.INVALID_ADJUSTMENT_STATE')).toBeInTheDocument()
    })
  })
})

/**
 * Posting is deliberately behind a ConfirmDialog, so both the header action and
 * the dialog's confirm carry the same label.
 */
async function clickPostThenConfirm(user: ReturnType<typeof userEvent.setup>): Promise<void> {
  const openDialog = screen.getAllByRole('button', { name: 'detail.post' })[0]
  expect(openDialog).toBeDefined()
  await user.click(openDialog as HTMLElement)

  await waitFor(() => {
    expect(screen.getAllByRole('button', { name: 'detail.post' }).length).toBeGreaterThan(1)
  })

  const buttons = screen.getAllByRole('button', { name: 'detail.post' })
  const confirm = buttons[buttons.length - 1]
  expect(confirm).toBeDefined()
  await user.click(confirm as HTMLElement)
}

describe('StockAdjustmentDetailPage — the override flag and the PATCH re-anchor', () => {
  function refuseWithStaleness() {
    postMutate.mockRejectedValueOnce({
      response: {
        data: {
          error: {
            code: 'STOCK_MOVED_SINCE_AUTHORING',
            message: 'moved',
            details: {
              lines: [
                {
                  line_id: 'line-1',
                  product_id: 'prod-1',
                  variant_id: null,
                  batch_uuid: null,
                  observed_before: '10.0000',
                  quantity_before: '15.0000',
                  quantity_decimals: 3,
                },
              ],
            },
          },
        },
      },
    })
  }

  it('sends ONLY acknowledge_stale for a staleness refusal', async () => {
    const user = userEvent.setup()
    grantedPermissions.add('inventory.adjustments.post')
    refuseWithStaleness()

    render(<StockAdjustmentDetailPage />)
    await clickPostThenConfirm(user)

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'refusal.applyAnyway' })).toBeInTheDocument()
    })
    await user.click(screen.getByRole('button', { name: 'refusal.applyAnyway' }))

    await waitFor(() => {
      expect(postMutate).toHaveBeenCalledTimes(2)
    })
    const options = (postMutate.mock.calls[1]?.[0] as { options: Record<string, unknown> }).options
    expect(options).toEqual({ acknowledge_stale: true })
    // Sending ignore_reservations too would disable a second guard AND forge a
    // permanent header claim that the operator overrode it.
    expect(options).not.toHaveProperty('ignore_reservations')
  })

  it('REBASES the delta in the PATCH so the operator lands on the quantity they authored', async () => {
    const user = userEvent.setup()
    grantedPermissions.add('inventory.adjustments.post')
    refuseWithStaleness()
    updateMutate.mockResolvedValue(makeAdjustment())

    render(<StockAdjustmentDetailPage />)
    await clickPostThenConfirm(user)

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'refusal.reAnchor' })).toBeInTheDocument()
    })
    await user.click(screen.getByRole('button', { name: 'refusal.reAnchor' }))

    await waitFor(() => {
      expect(updateMutate).toHaveBeenCalledTimes(1)
    })

    const body = updateMutate.mock.calls[0]?.[0] as {
      input: { lines: { delta_quantity: string; observed_before: string }[] }
    }
    // Authored "10 + 2 = 12". The row now holds 15, so reaching 12 needs −3.
    // Keeping the old +2 would land on 17 — a quantity nobody authored, which is
    // exactly the silent-wrong-number the staleness guard exists to prevent.
    expect(body.input.lines[0]?.observed_before).toBe('15.0000')
    expect(body.input.lines[0]?.delta_quantity).toBe('-3.000')
  })
})
