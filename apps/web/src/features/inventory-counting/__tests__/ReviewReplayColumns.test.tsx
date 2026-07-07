import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { ReconciliationTable } from '../components/ReconciliationTable'
import { CountingReviewPage } from '../pages/CountingReviewPage'
import type { ReconciliationData, ReconciliationItem } from '../types'

// Mutable holders read by the mocked hooks (vi.hoisted so they exist before the
// module factory runs).
const h = vi.hoisted(() => ({
  reconciliation: null as ReconciliationData | null,
  countingDetail: null as unknown,
  setOpeningCostMutate: vi.fn(),
  finalizeMutate: vi.fn(),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

vi.mock('../api/queries', () => ({
  useCountingDetail: () => ({ data: h.countingDetail, isLoading: false }),
  useReconciliation: () => ({ data: h.reconciliation, isLoading: false }),
  useFinalizeCounting: () => ({ mutate: h.finalizeMutate, isPending: false }),
  useTriggerThirdCount: () => ({ mutate: vi.fn(), isPending: false }),
  useManualOverride: () => ({ mutate: vi.fn(), isPending: false }),
  useSetOpeningCost: () => ({ mutate: h.setOpeningCostMutate, isPending: false }),
}))

function makeItem(overrides: Partial<ReconciliationItem> = {}): ReconciliationItem {
  return {
    id: '11111111-1111-4111-8111-111111111111',
    product: { id: 1, name: 'Widget', sku: 'SKU-1', barcode: null, image_url: null },
    variant: null,
    location: { id: 1, code: 'WH-1', name: 'Main' },
    warehouse: { id: 1, name: 'Main' },
    theoretical_qty: '10.0000',
    count_1: { qty: '10.0000', at: '2026-07-06T10:00:00Z', notes: null },
    count_2: null,
    count_3: null,
    final_qty: '10.0000',
    variance: 0,
    variance_percentage: 0,
    resolution_method: 'auto_all_match',
    resolution_notes: null,
    is_flagged: false,
    flag_reason: null,
    expected_qty_at_apply: '9.0000',
    replay_audit: {
      windowFrom: '2026-07-06T10:00:00Z',
      windowTo: '2026-07-06T11:00:00Z',
      replayedDelta: '-1.0000',
      onHandAtApply: '9.0000',
      expectedAtApply: '9.0000',
    },
    flag_reasons: null,
    opening_unit_cost: null,
    will_post_as_opening: false,
    opening_cost_missing: false,
    ...overrides,
  }
}

function makeReconciliation(items: ReconciliationItem[]): ReconciliationData {
  return {
    summary: { total: items.length, auto_resolved: 0, needs_attention: 0, manually_overridden: 0 },
    items,
    late_sales_flags: [],
  }
}

beforeEach(() => {
  h.setOpeningCostMutate.mockReset()
  h.finalizeMutate.mockReset()
  h.countingDetail = {
    id: '77777777-7777-4777-8777-777777777777',
    uuid: '77777777-7777-4777-8777-777777777777',
    status: 'pending_review',
  }
  h.reconciliation = makeReconciliation([makeItem()])
})

afterEach(() => {
  vi.clearAllMocks()
})

function renderReviewPage() {
  return render(
    <MemoryRouter
      initialEntries={['/inventory/counting/77777777-7777-4777-8777-777777777777/review']}
    >
      <Routes>
        <Route path="/inventory/counting/:id/review" element={<CountingReviewPage />} />
      </Routes>
    </MemoryRouter>
  )
}

describe('ReconciliationTable replay columns + flags', () => {
  it('renders the replay columns (expected now + movements since count)', () => {
    h.reconciliation = makeReconciliation([
      makeItem({ expected_qty_at_apply: '9.0000' }),
    ])
    render(<ReconciliationTable countingId="77777777-7777-4777-8777-777777777777" />)

    expect(screen.getByText('counting.reconciliation.expectedNow')).toBeInTheDocument()
    expect(screen.getByText('counting.reconciliation.movementsSinceCount')).toBeInTheDocument()
    // Movements-since-count value from replay_audit.replayedDelta.
    expect(screen.getByText('-1.0000')).toBeInTheDocument()
  })

  it('renders a chip per flag reason, styling blocking vs informational', () => {
    h.reconciliation = makeReconciliation([
      makeItem({
        flag_reasons: ['basket_window', 'normalized_agreement'],
        is_flagged: true,
      }),
    ])
    render(<ReconciliationTable countingId="77777777-7777-4777-8777-777777777777" />)

    const blocking = screen.getByTestId('flag-chip-basket_window')
    const informational = screen.getByTestId('flag-chip-normalized_agreement')
    expect(blocking).toHaveTextContent('counting.flags.basket_window')
    expect(informational).toHaveTextContent('counting.flags.normalized_agreement')
    // Blocking uses a warning (amber) style; informational a muted (gray) one.
    expect(blocking.className).toContain('amber')
    expect(informational.className).toContain('gray')
  })

  it('PATCHes opening cost from the backfill cell when the line will post as opening', async () => {
    const user = userEvent.setup()
    h.reconciliation = makeReconciliation([
      // Pre-finalize signal drives the editable cell — NOT the post-finalize
      // pending_opening_cost flag (which is absent during pending_review).
      makeItem({
        id: '42424242-4242-4242-8242-424242424242',
        will_post_as_opening: true,
        opening_cost_missing: true,
        opening_unit_cost: null,
      }),
    ])
    render(<ReconciliationTable countingId="77777777-7777-4777-8777-777777777777" />)

    const input = screen.getByLabelText('counting.reconciliation.openingCost')
    await user.type(input, '3.5')
    await user.click(screen.getByText('counting.reconciliation.saveCost'))

    expect(h.setOpeningCostMutate).toHaveBeenCalledWith({
      itemId: '42424242-4242-4242-8242-424242424242',
      unitCost: '3.5',
    })
  })

  it('shows a static cost cell (no input) when the line will not post as opening', () => {
    h.reconciliation = makeReconciliation([
      makeItem({ will_post_as_opening: false, opening_unit_cost: null }),
    ])
    render(<ReconciliationTable countingId="77777777-7777-4777-8777-777777777777" />)

    expect(
      screen.queryByLabelText('counting.reconciliation.openingCost')
    ).not.toBeInTheDocument()
  })

  it('shows a "not posted — recount" hint for skipped-at-apply flags', () => {
    h.reconciliation = makeReconciliation([
      makeItem({ flag_reasons: ['basket_window'], is_flagged: true }),
    ])
    render(<ReconciliationTable countingId="77777777-7777-4777-8777-777777777777" />)

    expect(screen.getByTestId('flag-hint-not-posted')).toHaveTextContent(
      'counting.flags.notPostedRecount'
    )
  })
})

describe('CountingReviewPage finalize gating', () => {
  it('enables finalize when nothing blocks', () => {
    h.reconciliation = makeReconciliation([makeItem()])
    renderReviewPage()

    const btn = screen.getByRole('button', { name: /counting\.actions\.finalize/ })
    expect(btn).not.toBeDisabled()
  })

  it('disables finalize when an opening line is missing its cost (pre-finalize signal)', () => {
    h.reconciliation = makeReconciliation([
      makeItem({
        will_post_as_opening: true,
        opening_cost_missing: true,
        opening_unit_cost: null,
      }),
    ])
    renderReviewPage()

    const btn = screen.getByRole('button', { name: /counting\.actions\.finalize/ })
    expect(btn).toBeDisabled()
  })

  it('enables finalize once an opening line has its cost (opening_cost_missing false)', () => {
    h.reconciliation = makeReconciliation([
      makeItem({
        will_post_as_opening: true,
        opening_cost_missing: false,
        opening_unit_cost: '2.500000',
      }),
    ])
    renderReviewPage()

    const btn = screen.getByRole('button', { name: /counting\.actions\.finalize/ })
    expect(btn).not.toBeDisabled()
  })

  it('disables finalize when an item carries an unresolved blocking flag', () => {
    h.reconciliation = makeReconciliation([
      makeItem({
        flag_reasons: ['basket_window'],
        is_flagged: true,
        final_qty: null,
        resolution_method: 'pending',
      }),
    ])
    renderReviewPage()

    const btn = screen.getByRole('button', { name: /counting\.actions\.finalize/ })
    expect(btn).toBeDisabled()
  })

  it('shows a banner when late sales were flagged', () => {
    const recon = makeReconciliation([makeItem()])
    recon.late_sales_flags = [
      { receipt_id: 'r1', occurred_at: '2026-07-06T10:30:00Z' },
    ]
    h.reconciliation = recon
    renderReviewPage()

    expect(screen.getByText('counting.review.lateSalesDetected')).toBeInTheDocument()
  })
})
