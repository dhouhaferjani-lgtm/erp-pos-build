import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render } from '@testing-library/react'
import { ZReportDetailPage } from './ZReportDetailPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

vi.mock('react-router-dom', () => ({
  useParams: () => ({ zNumber: '7' }),
  useSearchParams: () => [new URLSearchParams('terminal_id=t1')],
  useNavigate: () => vi.fn(),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('@/lib/tenantScopedKey', () => ({
  tenantScopedKey: (k: unknown[]) => k,
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 3 }),
}))

vi.mock('../../hooks/usePosTenantScope', () => ({
  usePosTenantScope: () => ({ hasTenantScope: true }),
}))

vi.mock('../../api/reportApi', () => ({
  fetchZReport: vi.fn(),
  verifyZReportChain: vi.fn(),
  downloadZReportPdf: vi.fn(),
}))

const report = {
  id: 'r1',
  formatted_z_number: 'Z-0007',
  generated_at: '2026-06-14T10:00:00Z',
  generated_by_user: { name: 'Alice' },
  gross_sales: '1234.560',
  sales_count: 12,
  opening_cash: '100.000',
  expected_cash: '500.000',
  actual_cash: '495.000',
  variance: '-5.000',
  fiscal_hash: 'abc123',
  previous_z_hash: 'prev999',
  is_first_z_report: false,
  report_data: {
    net_sales: '1000.000',
    tax_amount: '234.560',
    refunds_count: 0,
    refunds_amount: '0.000',
    voided_count: 0,
    vat_breakdown: [{ rate: 20, net: '800.000', vat: '160.000', gross: '960.000' }],
    payment_methods: [{ type: 'cash', count: 8, amount: '900.000' }],
  },
}

// Mutable holder so a test can swap in a refund-bearing Z without re-hoisting
// the whole module mock. `report` above stays the default for every existing
// assertion.
const queryState = vi.hoisted(() => ({ data: null as unknown }))

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({ data: queryState.data, isLoading: false, error: null }),
  useMutation: () => ({
    mutate: vi.fn(),
    isPending: false,
    isSuccess: false,
    data: undefined,
  }),
}))

describe('ZReportDetailPage', () => {
  beforeEach(() => {
    queryState.data = report
  })

  it('renders its detail title (color-tokenized, no drift)', () => {
    const { getByText } = render(<ZReportDetailPage />)
    expect(getByText('pos:zReports.detailTitle')).toBeInTheDocument()
  })

  it('renders money values right-aligned with tabular-nums', () => {
    const { container } = render(<ZReportDetailPage />)
    const moneyCells = container.querySelectorAll('.tabular-nums')
    expect(moneyCells.length).toBeGreaterThan(0)
  })
})

/**
 * B-6(ii) / Option A1+A2 — the detail page used to show the SALE-ONLY
 * `tax_amount` beside a per-rate table that is NET of refunds. The derived
 * disclosure (server-side, from the same source the VAT declaration reads)
 * replaces that single line with the three-line bridge.
 */
describe('ZReportDetailPage — refund VAT disclosure', () => {
  const refundBearing = {
    ...report,
    report_data: {
      ...report.report_data,
      tax_amount: '234.560',
      refunds_count: 1,
      refunds_amount: '120.000',
      vat_breakdown: [{ rate: 20, net: '780.000', vat: '156.000', gross: '936.000' }],
    },
    refund_vat_disclosure: {
      rows: [
        { tax_rate: '20.00', net_amount: '20.000', vat_amount: '78.560', gross_amount: '98.560' },
      ],
      sales_vat: '234.560',
      refund_vat: '78.560',
      net_vat: '156.000',
      has_refund_vat: true,
      is_reconciled: true,
    },
  }

  it('renders the three-line bridge instead of the bare sale-only VAT row', () => {
    queryState.data = refundBearing
    const { getByText, getAllByText, queryByText } = render(<ZReportDetailPage />)

    expect(getByText('pos:zReports.detail.vatOnSales')).toBeInTheDocument()
    expect(getByText('pos:zReports.detail.netVat')).toBeInTheDocument()
    // Two occurrences by design: the summary bridge line and the per-rate
    // refund row in the VAT table below (single rate in this fixture).
    expect(getAllByText('-78.560').length).toBe(2)
    expect(getAllByText('156.000').length).toBeGreaterThan(0)
    // The sale-only row no longer stands alone.
    expect(queryByText('pos:zReports.detail.taxAmount')).not.toBeInTheDocument()
  })

  it('adds the per-rate refund VAT row to the VAT table', () => {
    queryState.data = refundBearing
    const { getByText } = render(<ZReportDetailPage />)

    expect(getByText('-98.560')).toBeInTheDocument()
  })

  it('keeps the single sale-only VAT row on a refund-free Z', () => {
    queryState.data = report
    const { getByText, queryByText } = render(<ZReportDetailPage />)

    expect(getByText('pos:zReports.detail.taxAmount')).toBeInTheDocument()
    expect(queryByText('pos:zReports.detail.vatOnSales')).not.toBeInTheDocument()
  })
})
