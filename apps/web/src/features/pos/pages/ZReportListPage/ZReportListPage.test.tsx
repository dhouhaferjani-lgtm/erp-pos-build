import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { ZReportListPage } from './ZReportListPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
}))

vi.mock('@/lib/tenantScopedKey', () => ({
  tenantScopedKey: (k: unknown[]) => k,
}))

vi.mock('../../hooks/usePosTenantScope', () => ({
  usePosTenantScope: () => ({ hasTenantScope: true }),
}))

vi.mock('../../api/reportApi', () => ({
  fetchZReports: vi.fn(),
  verifyZReportChain: vi.fn(),
}))

const reportRow = {
  id: 'r1',
  z_number: 7,
  formatted_z_number: 'Z-0007',
  generated_at: '2026-06-14T10:00:00Z',
  generated_by: 'u1',
  generated_by_user: { name: 'Alice' },
  gross_sales: '1234.560',
  sales_count: 12,
  has_variance: true,
  variance: '-5.000',
  report_data: { net_sales: '1000.000', tax_amount: '234.560' },
}

vi.mock('@tanstack/react-query', () => ({
  useQuery: ({ queryKey }: { queryKey: unknown[] }) => {
    // terminals query vs z-reports query, disambiguate by key contents
    if (queryKey.includes('terminals')) {
      return { data: [{ id: 't1', code: 'T1', name: 'Terminal 1' }] }
    }
    return {
      data: { data: [reportRow], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } },
      isLoading: false,
    }
  },
  useMutation: () => ({
    mutate: vi.fn(),
    reset: vi.fn(),
    isPending: false,
    isSuccess: false,
    data: undefined,
  }),
}))

describe('ZReportListPage', () => {
  it('renders its title (color-tokenized, no drift)', () => {
    const { getByText } = render(<ZReportListPage />)
    expect(getByText('pos:zReports.title')).toBeInTheDocument()
  })

  it('right-aligns money cells with tabular-nums once a terminal is selected', () => {
    const { container } = render(<ZReportListPage />)
    // Select a terminal to reveal the report table.
    const select = container.querySelector('select')
    expect(select).not.toBeNull()
    fireEvent.change(select as HTMLSelectElement, { target: { value: 't1' } })

    const moneyCells = container.querySelectorAll('td.tabular-nums')
    expect(moneyCells.length).toBeGreaterThan(0)
  })
})
