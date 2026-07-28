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
    product: { id: 1, name: 'Widget', sku: 'SKU-1', barcode: null, image_url: null, quantity_decimals: 4 },
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
    replay_preview: null,
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
    terminal_sync_health: {
      requires_acknowledgement: false,
      acknowledgement_signature: null,
      stale_after_seconds: 300,
      terminals: [],
    },
  }
}

beforeEach(() => {
  h.setOpeningCostMutate.mockReset()
  h.finalizeMutate.mockReset()
  // Real detail payload shape: the API emits `id` only (no `uuid` alias).
  h.countingDetail = {
    id: '77777777-7777-4777-8777-777777777777',
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
  it('renders pre-finalize movements, expected-now, and adjustment preview columns', () => {
    h.reconciliation = makeReconciliation([
      makeItem({
        expected_qty_at_apply: null,
        replay_audit: null,
        replay_preview: {
          mode: 'timestamp_replay',
          movements_since_count: '-3.0000',
          expected_now: '17.0000',
          adjustment: '5.0000',
          will_auto_post: true,
          blocked_reason: null,
        },
      }),
    ])
    render(<ReconciliationTable countingId="77777777-7777-4777-8777-777777777777" />)

    expect(screen.getByText('counting.reconciliation.expectedNow')).toBeInTheDocument()
    expect(screen.getByText('counting.reconciliation.movementsSinceCount')).toBeInTheDocument()
    expect(screen.getByText('counting.reconciliation.adjustmentToPost')).toBeInTheDocument()
    expect(screen.getByText('-3.0000')).toBeInTheDocument()
    expect(screen.getByText('17.0000')).toBeInTheDocument()
    expect(screen.getByText('+5.0000')).toBeInTheDocument()
  })

  it('renders the preview state when a replay guard will prevent auto-posting', () => {
    h.reconciliation = makeReconciliation([
      makeItem({
        expected_qty_at_apply: null,
        replay_audit: null,
        replay_preview: {
          mode: 'timestamp_replay',
          movements_since_count: '-3.0000',
          expected_now: '17.0000',
          adjustment: '5.0000',
          will_auto_post: false,
          blocked_reason: 'basket_window',
        },
      }),
    ])
    render(<ReconciliationTable countingId="77777777-7777-4777-8777-777777777777" />)

    expect(screen.getByText(/counting\.reconciliation\.willNotAutoPost/)).toHaveTextContent(
      'counting.reconciliation.willNotAutoPost'
    )
  })

  it('renders the legacy-delta expected quantity and adjustment', () => {
    h.reconciliation = makeReconciliation([
      makeItem({
        product: { id: 1, name: 'Widget', sku: 'SKU-1', barcode: null, image_url: null, quantity_decimals: 0 },
        expected_qty_at_apply: null,
        replay_audit: null,
        replay_preview: {
          mode: 'legacy_delta',
          movements_since_count: null,
          expected_now: '17.0000',
          adjustment: '5.0000',
          will_auto_post: true,
          blocked_reason: null,
        },
      }),
    ])
    render(<ReconciliationTable countingId="77777777-7777-4777-8777-777777777777" />)

    expect(screen.getByText('17')).toBeInTheDocument()
    expect(screen.getByText('+5')).toBeInTheDocument()
    expect(screen.getByText('counting.reconciliation.legacyDelta')).toBeInTheDocument()
  })

  it('renders finalized replay audit values and suppresses skipped adjustments', () => {
    h.reconciliation = makeReconciliation([
      makeItem({ replay_preview: null }),
      makeItem({
        id: '22222222-2222-4222-8222-222222222222',
        replay_preview: null,
        flag_reasons: ['negative_at_apply'],
      }),
    ])
    render(<ReconciliationTable countingId="77777777-7777-4777-8777-777777777777" />)

    expect(screen.getAllByText('-1.0000')).toHaveLength(2)
    expect(screen.getByText('0.0000')).toBeInTheDocument()
    expect(screen.getByText(/counting\.reconciliation\.wasNotAutoPosted/)).toBeInTheDocument()
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

  it('requires an explicit terminal-sync acknowledgement before finalize enables', async () => {
    const user = userEvent.setup()
    const recon = makeReconciliation([makeItem()])
    recon.terminal_sync_health = {
      requires_acknowledgement: true,
      acknowledgement_signature: 'signature-1',
      stale_after_seconds: 300,
      terminals: [{
        id: 'terminal-1',
        code: 'POS01',
        name: 'Front Till',
        location_id: 'location-1',
        state: 'pending',
        pending_receipt_count: 3,
        last_sync_at: '2026-07-28T12:00:00Z',
        reported_at: '2026-07-28T12:00:00Z',
      }],
    }
    h.reconciliation = recon
    renderReviewPage()

    const finalizeButton = screen.getByRole('button', { name: /counting\.actions\.finalize/ })
    expect(finalizeButton).toBeDisabled()
    expect(screen.getByText('counting.review.terminalSync.title')).toBeInTheDocument()
    expect(screen.getByText('POS01 — Front Till')).toBeInTheDocument()

    await user.click(screen.getByRole('checkbox', {
      name: 'counting.review.terminalSync.acknowledge',
    }))
    expect(finalizeButton).not.toBeDisabled()

    await user.click(finalizeButton)
    const confirmButtons = screen.getAllByRole('button', {
      name: /counting\.actions\.finalize/,
    })
    const confirmButton = confirmButtons.at(-1)
    if (!confirmButton) {
      throw new Error('Expected a finalize confirmation button.')
    }
    await user.click(confirmButton)

    expect(h.finalizeMutate).toHaveBeenCalledWith(
      {
        id: '77777777-7777-4777-8777-777777777777',
        acknowledgeTerminalSyncRisk: true,
        terminalSyncHealthSignature: 'signature-1',
      },
      expect.objectContaining({ onSuccess: expect.any(Function) }),
    )
  })

  it('requires a new acknowledgement when refreshed terminal risk changes', async () => {
    const user = userEvent.setup()
    const recon = makeReconciliation([makeItem()])
    recon.terminal_sync_health = {
      requires_acknowledgement: true,
      acknowledgement_signature: 'signature-1',
      stale_after_seconds: 300,
      terminals: [{
        id: 'terminal-1',
        code: 'POS01',
        name: 'Front Till',
        location_id: 'location-1',
        state: 'pending',
        pending_receipt_count: 3,
        last_sync_at: '2026-07-28T12:00:00Z',
        reported_at: '2026-07-28T12:00:00Z',
      }],
    }
    h.reconciliation = recon
    const view = renderReviewPage()

    const acknowledgement = screen.getByRole('checkbox', {
      name: 'counting.review.terminalSync.acknowledge',
    })
    const finalizeButton = screen.getByRole('button', { name: /counting\.actions\.finalize/ })

    await user.click(acknowledgement)
    expect(finalizeButton).not.toBeDisabled()

    const reportedTerminal = recon.terminal_sync_health.terminals[0]
    if (!reportedTerminal) {
      throw new Error('Expected terminal sync-health fixture.')
    }

    h.reconciliation = {
      ...recon,
      terminal_sync_health: {
        ...recon.terminal_sync_health,
        acknowledgement_signature: 'signature-2',
        terminals: [{
          ...reportedTerminal,
          pending_receipt_count: 4,
          reported_at: '2026-07-28T12:01:00Z',
        }],
      },
    }
    view.rerender(
      <MemoryRouter
        initialEntries={['/inventory/counting/77777777-7777-4777-8777-777777777777/review']}
      >
        <Routes>
          <Route path="/inventory/counting/:id/review" element={<CountingReviewPage />} />
        </Routes>
      </MemoryRouter>
    )

    expect(acknowledgement).not.toBeChecked()
    expect(finalizeButton).toBeDisabled()
  })
})
