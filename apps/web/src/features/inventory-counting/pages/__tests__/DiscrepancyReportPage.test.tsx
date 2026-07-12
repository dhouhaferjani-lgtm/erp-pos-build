import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { DiscrepancyReportPage } from '../DiscrepancyReportPage'

// Track translation keys used
const usedKeys: string[] = []

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      usedKeys.push(key)
      return key
    },
  }),
}))

const mockUseDiscrepancyReport = vi.fn()
const mockUseExportReport = vi.fn()

vi.mock('../../api/queries', () => ({
  useDiscrepancyReport: (...args: unknown[]) => mockUseDiscrepancyReport(...args),
  useExportReport: (...args: unknown[]) => mockUseExportReport(...args),
}))

vi.mock('../../components/CountingStatusBadge', () => ({
  CountingStatusBadge: ({ status }: { status: string }) => (
    <span data-testid="status-badge">{status}</span>
  ),
}))

function renderPage() {
  return render(
    <MemoryRouter>
      <DiscrepancyReportPage />
    </MemoryRouter>,
  )
}

// Only the fields the page actually reads (hooks are mocked, so the full
// DiscrepancyReport contract is exercised in api/__tests__/countingApi.test.ts).
const mockReport = {
  report_id: '0f8fad5b-d9cb-469f-a165-70867728950e',
  generated_at: '2026-07-08T10:00:00+00:00',
  generated_by: { id: 'user-1', name: 'Report Admin' },
  counting: { status: 'finalized' },
  summary: {
    total_items_counted: 2,
    items_no_variance: 1,
    items_with_variance: 1,
    variance_breakdown: {
      auto_all_match: 1,
      auto_counters_agree: 1,
      third_count_decisive: 0,
      manual_override: 0,
    },
    total_variance_value: {
      positive: '5.000',
      negative: '0.000',
      net: '5.000',
      currency: 'TND',
    },
    late_sales_corrections: 0,
    opening_value: '0.000',
  },
  flagged_items: [],
  counter_performance: [],
}

describe('DiscrepancyReportPage', () => {
  beforeEach(() => {
    usedKeys.length = 0
    mockUseDiscrepancyReport.mockReturnValue({
      data: mockReport,
      isLoading: false,
      error: null,
    })
    mockUseExportReport.mockReturnValue({
      mutate: vi.fn(),
      isPending: false,
    })
  })

  it('hides the export buttons while /report/export is unimplemented', () => {
    renderPage()

    // REPORT_EXPORT_ENABLED is false: the PDF/Excel export buttons must not
    // render (the backend /report/export endpoint does not exist yet — a
    // click would 404). Flip this assertion when the follow-up lands.
    expect(screen.queryByRole('button')).toBeNull()
    expect(usedKeys).not.toContain('counting.report.exportPdf')
    expect(usedKeys).not.toContain('counting.report.exportExcel')
  })

  it('renders the report title and summary tiles', () => {
    renderPage()

    expect(usedKeys).toContain('counting.report.title')
    expect(usedKeys).toContain('counting.report.totalItemsCounted')
    expect(usedKeys).toContain('counting.report.lateSalesCorrections')
    expect(usedKeys).toContain('counting.report.openingValue')
  })

  it('renders the current-cost-basis caption near the variance figures', () => {
    renderPage()

    expect(usedKeys).toContain('counting.report.currentCostBasisNote')
  })
})
