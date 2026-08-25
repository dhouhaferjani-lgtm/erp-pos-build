import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { DiscrepancyReportPage } from '../pages/DiscrepancyReportPage'
import type { DiscrepancyReport, DiscrepancyReportItem } from '../types'

// Campaign W4-6 gate r1 (F-5). The backend delivers the corrected variance —
// expected as of the count instant, counted, the bcmath variance and whether it
// reached stock — and the page rendered none of it: it showed
// `theoretical_qty` as "expected" and the FLOAT `item.variance` computed on the
// old baseline. An operator reading this page therefore saw the pre-W4-6 number
// on a count that had been corrected.

const h = vi.hoisted(() => ({ report: null as DiscrepancyReport | null }))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('../api/queries', () => ({
  useDiscrepancyReport: () => ({ data: h.report, isLoading: false, error: null }),
  useExportReport: () => ({ mutate: vi.fn(), isPending: false }),
}))

function makeItem(overrides: Partial<DiscrepancyReportItem> = {}): DiscrepancyReportItem {
  return {
    id: '11111111-1111-4111-8111-111111111111',
    product: { id: 1, name: 'Widget', sku: 'SKU-1', barcode: null, image_url: null, quantity_decimals: 4 },
    variant: null,
    location: { id: 1, code: 'WH-1', name: 'Main' },
    warehouse: { id: 1, name: 'Main' },
    theoretical_qty: '70.0000',
    count_1: null,
    count_2: null,
    count_3: null,
    final_qty: '68.0000',
    variance: -2,
    variance_percentage: null,
    resolution_method: 'auto_counters_agree',
    resolution_notes: null,
    is_flagged: true,
    flag_reason: null,
    expected_qty_at_apply: '68.0000',
    replay_audit: null,
    replay_preview: null,
    flag_reasons: ['basket_window'],
    opening_unit_cost: null,
    will_post_as_opening: false,
    opening_cost_missing: false,
    expected_qty: '70.0000',
    counted_qty: '68.0000',
    variance_qty: '-2.0000',
    variance_applied: true,
    not_applied_reason: null,
    ...overrides,
  }
}

const countingStub = {
  id: 'c1',
  counting_number: 'CNT-2026-0002',
  status: 'finalized',
} as Partial<DiscrepancyReport['counting']> as DiscrepancyReport['counting']

function makeReport(items: DiscrepancyReportItem[]): DiscrepancyReport {
  return {
    report_id: 'r1',
    generated_at: '2026-08-25T10:00:00Z',
    generated_by: { id: 'u1', name: 'Amel' },
    // The page reads only `counting.status` and `counting.counting_number`;
    // the full contract is exercised in api/__tests__/countingApi.test.ts.
    counting: countingStub,
    summary: {
      total_items_counted: items.length,
      items_no_variance: 0,
      items_with_variance: items.length,
      items_agreeing: 0,
      items_applied: items.filter((i) => i.variance_applied).length,
      items_not_applied: items.filter((i) => !i.variance_applied).length,
      variance_breakdown: {
        auto_all_match: 0,
        auto_counters_agree: items.length,
        third_count_decisive: 0,
        manual_override: 0,
      },
      total_variance_value: { positive: '0.000', negative: '-20.000', net: '-20.000', currency: 'TND' },
    },
    items,
    flagged_items: items.filter((i) => i.is_flagged),
    counter_performance: [],
    late_sync_residuals: [],
  }
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/inventory/counting/c1/report']}>
      <Routes>
        <Route path="/inventory/counting/:id/report" element={<DiscrepancyReportPage />} />
      </Routes>
    </MemoryRouter>
  )
}

describe('DiscrepancyReportPage — W4-6 variance columns', () => {
  beforeEach(() => {
    h.report = null
  })

  it('renders expected / counted / variance / applied for every counted line', () => {
    h.report = makeReport([makeItem()])
    renderPage()

    const row = screen.getByTestId('report-item-11111111-1111-4111-8111-111111111111')
    expect(within(row).getByTestId('report-expected-qty')).toHaveTextContent('70')
    expect(within(row).getByTestId('report-counted-qty')).toHaveTextContent('68')
    expect(within(row).getByTestId('report-variance-qty')).toHaveTextContent('-2')
    expect(within(row).getByTestId('report-variance-applied')).toHaveTextContent(
      'counting.report.appliedYes'
    )
  })

  it('names the reason when a variance did not reach stock', () => {
    h.report = makeReport([
      makeItem({
        variance_applied: false,
        not_applied_reason: 'pending_opening_cost',
        flag_reasons: ['pending_opening_cost'],
      }),
    ])
    renderPage()

    const row = screen.getByTestId('report-item-11111111-1111-4111-8111-111111111111')
    expect(within(row).getByTestId('report-variance-applied')).toHaveTextContent(
      'counting.report.appliedNo'
    )
    expect(within(row).getByTestId('report-not-applied-reason')).toHaveTextContent(
      'counting.flags.pending_opening_cost'
    )
  })

  it('surfaces the applied / not-applied counters in the summary', () => {
    h.report = makeReport([makeItem(), makeItem({
      id: '22222222-2222-4222-8222-222222222222',
      variance_applied: false,
      not_applied_reason: 'negative_at_apply',
    })])
    renderPage()

    expect(screen.getByTestId('summary-items-applied')).toHaveTextContent('1')
    expect(screen.getByTestId('summary-items-not-applied')).toHaveTextContent('1')
  })

  it('never renders the legacy float variance', () => {
    // `item.variance` is a float on the pre-W4-6 baseline; the row must read
    // the bcmath string instead.
    h.report = makeReport([makeItem({ variance: -99, variance_qty: '-2.0000' })])
    renderPage()

    expect(screen.queryByText(/-99/)).not.toBeInTheDocument()
  })
})
