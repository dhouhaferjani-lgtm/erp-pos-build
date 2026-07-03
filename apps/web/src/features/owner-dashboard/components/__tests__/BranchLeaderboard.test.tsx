import { render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BranchLeaderboard } from '../BranchLeaderboard'
import type { SalesByLocationReport } from '../../api/ownerReportsApi'

const useSalesByLocationMock = vi.hoisted(() => vi.fn())
const useLiveSalesMock = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('../../hooks/useOwnerReports', () => ({
  useSalesByLocation: useSalesByLocationMock,
  useLiveSales: useLiveSalesMock,
}))

const todayRows: SalesByLocationReport[] = [
  { period: '2026-07-03', company_id: 'c', company_name: 'C', location_id: 'loc-1', location_name: 'Branche Lac 2', gross_sales: '120.000', receipt_count: 2 },
  { period: '2026-07-03', company_id: 'c', company_name: 'C', location_id: 'loc-1', location_name: 'Branche Lac 2', gross_sales: '80.000', receipt_count: 1 },
  { period: '2026-07-03', company_id: 'c', company_name: 'C', location_id: 'loc-2', location_name: 'Branche Marsa', gross_sales: '100.000', receipt_count: 1 },
]

const baselineRows: SalesByLocationReport[] = [
  { period: '2026-06-26', company_id: 'c', company_name: 'C', location_id: 'loc-1', location_name: 'Branche Lac 2', gross_sales: '100.000', receipt_count: 1 },
  { period: '2026-06-26', company_id: 'c', company_name: 'C', location_id: 'loc-2', location_name: 'Branche Marsa', gross_sales: '200.000', receipt_count: 2 },
]

describe('BranchLeaderboard', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-03T10:15:00Z'))
    useSalesByLocationMock.mockImplementation((params: { from: string }) => ({
      data: params.from === '2026-06-26' ? baselineRows : todayRows,
      isLoading: false,
      isError: false,
    }))
    useLiveSalesMock.mockReturnValue({
      data: {
        recent_receipts: [],
        open_shifts_by_location: { 'loc-1': 2 },
        generated_at: '2026-07-03T10:15:00Z',
      },
      isLoading: false,
      isError: false,
    })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  it('ranks branches by today sales and renders delta direction plus activity state', () => {
    render(<BranchLeaderboard />)

    const rows = screen.getAllByTestId('branch-leaderboard-row')
    expect(within(rows[0]).getByText('#1')).toBeInTheDocument()
    expect(within(rows[0]).getByText('Branche Lac 2')).toBeInTheDocument()
    expect(within(rows[0]).getByText('200,000 TND')).toBeInTheDocument()
    expect(within(rows[0]).getByText('▲ 100.0%')).toBeInTheDocument()
    expect(within(rows[0]).getByLabelText('reports:ownerDashboard.branchLeaderboard.active')).toBeInTheDocument()

    expect(within(rows[1]).getByText('#2')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Branche Marsa')).toBeInTheDocument()
    expect(within(rows[1]).getByText('▼ 50.0%')).toBeInTheDocument()
    expect(within(rows[1]).getByLabelText('reports:ownerDashboard.branchLeaderboard.inactive')).toBeInTheDocument()
  })
})
