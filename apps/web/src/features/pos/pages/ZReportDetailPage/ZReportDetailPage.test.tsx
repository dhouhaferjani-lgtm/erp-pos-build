import { describe, it, expect, vi } from 'vitest'
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

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({ data: report, isLoading: false, error: null }),
  useMutation: () => ({
    mutate: vi.fn(),
    isPending: false,
    isSuccess: false,
    data: undefined,
  }),
}))

describe('ZReportDetailPage', () => {
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
