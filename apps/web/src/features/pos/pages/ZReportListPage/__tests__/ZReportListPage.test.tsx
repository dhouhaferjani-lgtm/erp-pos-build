import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ZReportListPage } from '../ZReportListPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('react-router-dom', () => ({ useNavigate: () => vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))
vi.mock('@/lib/api', () => ({ apiGet: vi.fn() }))
vi.mock('../../../../lib/locationScopedKey', () => ({ locationScopedKey: (key: unknown[]) => key }))
vi.mock('../../hooks/usePosTenantScope', () => ({ usePosTenantScope: () => ({ hasTenantScope: true }) }))
vi.mock('../../api/reportApi', () => ({ fetchZReports: vi.fn(), verifyZReportChain: vi.fn() }))
vi.mock('@/features/locations/hooks/useViewScope', () => ({
  useViewScope: () => ({ scope: 'all', effectiveLocationIds: ['l1', 'l2'], isAll: true, setScope: vi.fn() }),
}))

vi.mock('@tanstack/react-query', () => ({
  useQuery: ({ queryKey }: { queryKey: unknown[] }) => {
    if (queryKey.includes('terminals')) {
      return { data: [], isLoading: false }
    }

    return {
      data: {
        data: [
          { id: 'r1', z_number: 1, formatted_z_number: 'Z-0001', generated_at: '2026-07-01T10:00:00Z', generated_by: 'u1', generated_by_user: { name: 'Alice' }, gross_sales: '10.000', sales_count: 1, has_variance: false, variance: '0.000', location_name: 'L1', terminal_name: 'Terminal 1', terminal_id: 't1' },
          { id: 'r2', z_number: 2, formatted_z_number: 'Z-0002', generated_at: '2026-07-02T10:00:00Z', generated_by: 'u2', generated_by_user: { name: 'Bob' }, gross_sales: '20.000', sales_count: 2, has_variance: false, variance: '0.000', location_name: 'L2', terminal_name: 'Terminal 2', terminal_id: 't2' },
        ],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 2 },
      },
      isLoading: false,
    }
  },
  useMutation: () => ({ mutate: vi.fn(), reset: vi.fn(), isPending: false, isSuccess: false, data: undefined }),
}))

describe('ZReportListPage cross-terminal rollup', () => {
  it('renders all reports with location and terminal columns without a terminal filter', () => {
    render(<ZReportListPage />)

    expect(screen.getByText('pos:zReports.location')).toBeInTheDocument()
    expect(screen.getByText('pos:zReports.terminalColumn')).toBeInTheDocument()
    expect(screen.getByText('L1')).toBeInTheDocument()
    expect(screen.getByText('L2')).toBeInTheDocument()
    expect(screen.queryByText('select a terminal')).not.toBeInTheDocument()
  })
})
