import { describe, it, expect, vi, beforeEach } from 'vitest'
import type { EChartsOption } from 'echarts'
import { render, screen } from '@testing-library/react'
import { ProfitLossPage } from './ProfitLossPage'
import { formatCurrency } from '../../../lib/format'
import type { ProfitLossData } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown): string =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('@/components/QueryError', () => ({
  QueryError: ({ title }: { title: string }) => <div>{title}</div>,
}))

const { mockUseProfitLoss, ownerChartProps } = vi.hoisted(() => ({
  mockUseProfitLoss: vi.fn(),
  ownerChartProps: [] as {
    title: string
    option: EChartsOption
    isEmpty?: boolean
  }[],
}))

vi.mock('../hooks/useProfitLoss', () => ({
  useProfitLoss: () => mockUseProfitLoss() as unknown,
}))

vi.mock('../../../hooks/useCompany', () => ({
  useCompany: () => ({
    currentCompany: { currency: 'TND', locale: 'fr_TN' },
  }),
}))

vi.mock('@/features/owner-dashboard/components/OwnerChart', () => ({
  OwnerChart: (props: {
    title: string
    option: EChartsOption
    isEmpty?: boolean
  }) => {
    ownerChartProps.push(props)
    return <div data-testid="owner-chart">{props.title}</div>
  },
}))

const fixture: ProfitLossData = {
  revenue: [
    { account_code: '700', account_name: 'Sales', amount: '1000.00' },
  ],
  expenses: [
    { account_code: '600', account_name: 'Purchases', amount: '400.00' },
  ],
  total_revenue: '1000.00',
  total_expenses: '400.00',
  net_income: '600.00',
}

function normalizeSpaces(value: string): string {
  return value.replace(/\s/g, ' ')
}

beforeEach(() => {
  ownerChartProps.length = 0
  mockUseProfitLoss.mockReturnValue({
    data: fixture,
    isLoading: false,
    error: null,
    refetch: vi.fn(),
  })
})

describe('ProfitLossPage canonicalization', () => {
  it('renders exactly one canonical <h1> page title', () => {
    const { container } = render(<ProfitLossPage />)
    const h1s = container.querySelectorAll('h1')
    expect(h1s.length).toBe(1)
    expect(h1s[0].textContent).toContain('reports.profitLossReport.title')
  })

  it('renders the export control as a <button>', () => {
    const { container } = render(<ProfitLossPage />)
    const buttons = container.querySelectorAll('button')
    expect(buttons.length).toBeGreaterThan(0)
  })

  it('right-aligns numeric cells with tabular-nums', () => {
    const { container } = render(<ProfitLossPage />)
    const cells = Array.from(container.querySelectorAll('td'))
    const numericCell = cells.find(
      (c) =>
        c.className.includes('tabular-nums') && c.className.includes('text-end'),
    )
    expect(numericCell).toBeDefined()
  })

  it('formats money with the selected company currency without US formatting', () => {
    render(<ProfitLossPage />)

    const formattedRevenue = formatCurrency('1000.00', {
      currency: 'TND',
      locale: 'fr-TN',
    })
    expect(normalizeSpaces(document.body.textContent)).toContain(
      normalizeSpaces(formattedRevenue)
    )
    expect(screen.queryByText('1,000.00')).not.toBeInTheDocument()
  })

  it('renders KPI cards and an OwnerChart revenue-vs-expenses bar chart', () => {
    render(<ProfitLossPage />)

    expect(screen.getAllByText('finance:reports.profitLossReport.totalRevenue').length).toBeGreaterThan(1)
    expect(screen.getAllByText('finance:reports.profitLossReport.totalExpenses').length).toBeGreaterThan(1)
    expect(screen.getAllByText('finance:reports.profitLossReport.netIncome').length).toBeGreaterThan(1)
    expect(screen.getByTestId('owner-chart')).toHaveTextContent(
      'finance:reports.profitLossReport.revenueVsExpenses'
    )
    expect(ownerChartProps).toHaveLength(1)
    expect(ownerChartProps[0].option.series).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ type: 'bar' }),
      ])
    )
  })

  it('defaults the date range to the current month', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-15T12:00:00.000Z'))
    try {
      render(<ProfitLossPage />)

      expect(screen.getByLabelText('finance:reports.common.from')).toHaveValue('2026-07-01')
      expect(screen.getByLabelText('finance:reports.common.to')).toHaveValue('2026-07-15')
    } finally {
      vi.useRealTimers()
    }
  })
})
