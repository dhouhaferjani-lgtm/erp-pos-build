import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { CountingDetailPage } from '../pages/CountingDetailPage'
import type { InventoryCounting } from '../types'

/**
 * N-1 / A-8 — the counting MODE was invisible after creation.
 *
 * `GET /inventory/countings/{id}` has always returned `block_sales` and
 * `ambiguity_window_minutes` (InventoryCountingController::transformCounting),
 * but the FE type omitted them and the configuration sidebar showed only
 * scope / mode / counts / allow-unexpected. An operator opening a live count
 * could not tell whether sales were blocked at the counted location or running
 * live under an ambiguity window — the single most consequential choice made in
 * the wizard.
 *
 * Gate r1 FE IMPORTANT-1 + MINOR-2: "Blocked" is a guarantee, and the engine
 * enforces it for three scopes only — CountingBlockService::activeBlockFor
 * queries [location, full_inventory, product_location] and scopeCoversLocation()
 * returns false by default. For every other scope the row must show the live
 * window instead. When blocking IS in force the ±window still governs
 * finalisation replay, so it is shown in both branches.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: Record<string, unknown>) =>
      options ? `${key}:${JSON.stringify(options)}` : key,
  }),
}))

const mockUseCountingDetail = vi.hoisted(() => vi.fn())
vi.mock('../api/queries', () => ({
  useCountingDetail: mockUseCountingDetail,
  useActivateCounting: () => ({ mutate: vi.fn(), isPending: false }),
  useCancelCounting: () => ({ mutate: vi.fn(), isPending: false }),
}))

function counting(overrides: Partial<InventoryCounting> = {}): InventoryCounting {
  return {
    id: 'c0000000-0000-4000-8000-000000000007',
    company_id: 1,
    scope_type: 'location',
    scope_filters: {},
    execution_mode: 'sequential',
    status: 'count_1_in_progress',
    scheduled_start: null,
    scheduled_end: null,
    requires_count_2: false,
    requires_count_3: false,
    allow_unexpected_items: true,
    instructions: null,
    block_sales: true,
    ambiguity_window_minutes: 15,
    created_on_mobile: false,
    title: null,
    last_modified_at: null,
    last_modified_by: null,
    count_1_user: null,
    count_2_user: null,
    count_3_user: null,
    created_by: { id: 1, name: 'Alice', email: 'alice@example.test' },
    progress: {
      count_1: { counted: 0, total: 2, percentage: 0 },
      count_2: null,
      count_3: null,
      overall: 0,
    },
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    activated_at: null,
    finalized_at: null,
    cancelled_at: null,
    cancellation_reason: null,
    ...overrides,
  }
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <CountingDetailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CountingDetailPage - sales mode', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup()
  })

  it('shows the count as sales-blocked when block_sales is true', () => {
    mockUseCountingDetail.mockReturnValue({
      data: counting({ block_sales: true }),
      isLoading: false,
      error: null,
    })

    renderPage()

    expect(screen.getByText('counting.detail.salesMode')).toBeInTheDocument()
    expect(screen.getByTestId('counting-sales-mode')).toHaveTextContent(
      'counting.detail.salesModeBlockedWithWindow:{"minutes":15}',
    )
  })

  it('keeps showing the replay window while sales are blocked', () => {
    mockUseCountingDetail.mockReturnValue({
      data: counting({ block_sales: true, ambiguity_window_minutes: 5 }),
      isLoading: false,
      error: null,
    })

    renderPage()

    expect(screen.getByTestId('counting-sales-mode')).toHaveTextContent(
      'counting.detail.salesModeBlockedWithWindow:{"minutes":5}',
    )
  })

  /**
   * Gate r1 FE IMPORTANT-1: the backend now refuses block_sales:true for
   * product/category, but rows persisted before that rule (and any future
   * scope the engine does not cover) must never be labelled "Blocked" —
   * the till keeps selling and no late sale is even flagged.
   */
  it.each(['category', 'product', 'zone'] as const)(
    'never claims Blocked for the unenforced %s scope',
    (scopeType) => {
      mockUseCountingDetail.mockReturnValue({
        data: counting({ scope_type: scopeType, block_sales: true, ambiguity_window_minutes: 15 }),
        isLoading: false,
        error: null,
      })

      renderPage()

      const row = screen.getByTestId('counting-sales-mode')
      expect(row).toHaveTextContent('counting.detail.salesModeLive:{"minutes":15}')
      // Substring guard, not a live key: `counting.detail.salesModeBlocked` was
      // deleted from the bundles (gate r2 NEW-3) and `salesModeBlockedWithWindow`
      // starts with it, so this one assertion refuses BOTH blocked variants.
      expect(row.textContent).not.toContain('counting.detail.salesModeBlocked')
    },
  )

  it.each(['location', 'full_inventory', 'product_location'] as const)(
    'claims Blocked for the enforced %s scope',
    (scopeType) => {
      mockUseCountingDetail.mockReturnValue({
        data: counting({ scope_type: scopeType, block_sales: true, ambiguity_window_minutes: 15 }),
        isLoading: false,
        error: null,
      })

      renderPage()

      expect(screen.getByTestId('counting-sales-mode')).toHaveTextContent(
        'counting.detail.salesModeBlockedWithWindow:{"minutes":15}',
      )
    },
  )

  it('shows the live ambiguity window when block_sales is false', () => {
    mockUseCountingDetail.mockReturnValue({
      data: counting({ block_sales: false, ambiguity_window_minutes: 20 }),
      isLoading: false,
      error: null,
    })

    renderPage()

    expect(screen.getByTestId('counting-sales-mode')).toHaveTextContent(
      'counting.detail.salesModeLive:{"minutes":20}',
    )
  })
})
