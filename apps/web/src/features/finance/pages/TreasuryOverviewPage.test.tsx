import { describe, expect, it, vi, beforeEach } from 'vitest'
import type { EChartsOption } from 'echarts'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { TreasuryOverviewPage } from './TreasuryOverviewPage'
import type { CashPosition } from '@/features/treasury/hooks/useCashPosition'
import { formatCurrency } from '@/lib/format'
import type { ProfitLossData } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown): string =>
      typeof second === 'string' ? second : key,
  }),
}))

const {
  mockUseCashPosition,
  mockUseUpcomingPayments,
  mockUseProfitLoss,
  ownerChartProps,
} = vi.hoisted(() => ({
  mockUseCashPosition: vi.fn(),
  mockUseUpcomingPayments: vi.fn(),
  mockUseProfitLoss: vi.fn(),
  ownerChartProps: [] as {
    title: string
    option: EChartsOption
    isLoading?: boolean
    isError?: boolean
    isEmpty?: boolean
  }[],
}))

vi.mock('@/features/treasury/hooks/useCashPosition', async () => {
  const actual = await vi.importActual<typeof import('@/features/treasury/hooks/useCashPosition')>(
    '@/features/treasury/hooks/useCashPosition',
  )
  return {
    ...actual,
    useCashPosition: () => mockUseCashPosition() as unknown,
  }
})

vi.mock('../hooks/useUpcomingPayments', () => ({
  useUpcomingPayments: () => mockUseUpcomingPayments() as unknown,
}))

vi.mock('../hooks/useProfitLoss', () => ({
  useProfitLoss: () => mockUseProfitLoss() as unknown,
}))

vi.mock('@/features/finance/components/FinanceWidget', () => ({
  FinanceWidget: () => <section data-testid="finance-widget">Finance widget</section>,
}))

vi.mock('@/features/finance/components/EcheancierPanel', () => ({
  EcheancierPanel: () => <section data-testid="echeancier-panel">Échéancier</section>,
}))

vi.mock('@/features/owner-dashboard/components/OwnerChart', () => ({
  OwnerChart: (props: {
    title: string
    option: EChartsOption
    isLoading?: boolean
    isError?: boolean
    isEmpty?: boolean
  }) => {
    ownerChartProps.push(props)
    return <section data-testid="owner-chart">{props.title}</section>
  },
}))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({
    currentCompany: { currency: 'TND', locale: 'fr_TN' },
  }),
}))

function normalizeSpaces(value: string | null): string {
  return (value ?? '').replace(/\s/g, ' ')
}

function renderOverview() {
  return render(
    <MemoryRouter>
      <TreasuryOverviewPage />
    </MemoryRouter>,
  )
}

const cashPositionFixture: CashPosition = {
  as_of: '2026-07-03T00:00:00Z',
  currency: 'TND',
  groups: [
    {
      type: 'cash_register',
      total: '125.750',
      repositories: [
        { id: 'cash-1', code: 'cash-1', name: 'cash-1', balance: '100.125' },
        { id: 'cash-2', code: 'cash-2', name: 'cash-2', balance: '25.625' },
      ],
    },
    {
      type: 'bank_account',
      total: '50.001',
      repositories: [{ id: 'bank-1', code: 'bank-1', name: 'bank-1', balance: '50.001' }],
    },
    {
      type: 'safe',
      total: '0.000',
      repositories: [{ id: 'safe-1', code: 'safe-1', name: 'safe-1', balance: '0' }],
    },
  ],
  // 999.999 is deliberately absent — 'virtual' repositories are excluded from
  // the cash position server-side (CashPositionController::CASH_TYPES), so
  // this fixture never includes them, matching the "not.toContain('999.999')"
  // assertion below.
  grand_total: '175.751',
}

beforeEach(() => {
  ownerChartProps.length = 0
  mockUseCashPosition.mockReturnValue({
    data: cashPositionFixture,
    isLoading: false,
    error: null,
    refetch: vi.fn(),
  })
  mockUseUpcomingPayments.mockReturnValue({
    data: {
      in: [],
      out: [],
      total_in: '0.000',
      total_out: '0.000',
      net: '0.000',
      days: 30,
      as_of_date: '2026-07-03',
      buckets_by_location: [{ location_id: 'loc-a', location_name: 'Store A', total_in: '0.000', total_out: '0.000', net: '0.000' }],
    },
    isLoading: false,
    error: null,
    refetch: vi.fn(),
  })
  mockUseProfitLoss.mockReturnValue({
    data: profitLossFixture,
    isLoading: false,
    error: null,
    refetch: vi.fn(),
  })
})

const profitLossFixture: ProfitLossData = {
  revenue: [{ account_code: '700', account_name: 'Sales', amount: '1200.000' }],
  expenses: [{ account_code: '600', account_name: 'Purchases', amount: '800.000' }],
  total_revenue: '1200.000',
  total_expenses: '800.000',
  net_income: '400.000',
}

describe('TreasuryOverviewPage', () => {
  it('links the page header to the unified cash movements report', () => {
    renderOverview()

    expect(screen.getByRole('link', {
      name: 'finance:cashMovements.navTitle',
    })).toHaveAttribute('href', '/finance/cash-movements')
  })

  it('sums repository balance strings into cash-position StatCards and mounts FinanceWidget', () => {
    renderOverview()

    expect(screen.getByText('finance:overview.cash.totalCash')).toBeInTheDocument()
    expect(screen.getByText('finance:overview.cash.cashRegisters')).toBeInTheDocument()
    expect(screen.getByText('finance:overview.cash.bankAccounts')).toBeInTheDocument()
    expect(screen.getByText('finance:overview.cash.safes')).toBeInTheDocument()

    const content = normalizeSpaces(document.body.textContent)
    expect(content).toContain(normalizeSpaces(formatCurrency('175.751', { currency: 'TND', locale: 'fr-TN' })))
    expect(content).toContain(normalizeSpaces(formatCurrency('125.750', { currency: 'TND', locale: 'fr-TN' })))
    expect(content).toContain(normalizeSpaces(formatCurrency('50.001', { currency: 'TND', locale: 'fr-TN' })))
    expect(content).toContain(normalizeSpaces(formatCurrency('0.000', { currency: 'TND', locale: 'fr-TN' })))
    expect(content).not.toContain('999.999')

    expect(screen.getByTestId('finance-widget')).toBeInTheDocument()
  })

  it('renders upcoming money IN and OUT lists sorted by due date with aging buckets and overdue highlighting', () => {
    mockUseUpcomingPayments.mockReturnValue({
      data: {
        in: [
          upcomingLine('Garage Tunis', 'INV-200', 'invoice', '2026-07-20', '150.000', 17, false),
          upcomingLine('Comptoir Nord', 'INV-100', 'invoice', '2026-07-01', '90.000', -2, true),
        ],
        out: [
          upcomingLine('Supplier B', 'BILL-200', 'supplier_invoice', '2026-08-20', '500.000', 48, false),
          upcomingLine('Supplier A', 'EXP-100', 'expense', '2026-07-05', '70.500', 2, false),
        ],
        total_in: '240.000',
        total_out: '570.500',
        net: '-330.500',
        days: 60,
        as_of_date: '2026-07-03',
        buckets_by_location: [{ location_id: 'loc-a', location_name: 'Store A', total_in: '90.000', total_out: '70.500', net: '19.500' }],
      },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    const { container } = renderOverview()

    expect(screen.getByText('finance:overview.upcoming.moneyIn')).toBeInTheDocument()
    expect(screen.getByText('finance:overview.upcoming.moneyOut')).toBeInTheDocument()
    expect(screen.getByText('finance:reports.defaultAttributedCaveat')).toBeInTheDocument()
    expect(screen.getByText('Store A')).toBeInTheDocument()
    expect(document.body.textContent).toContain('finance:overview.upcoming.buckets.overdue')
    expect(document.body.textContent).toContain('finance:overview.upcoming.buckets.current')
    expect(document.body.textContent).toContain('finance:overview.upcoming.buckets.days31To60')

    expect(listText('finance:overview.upcoming.moneyIn')).toMatch(/Comptoir Nord[\s\S]*Garage Tunis/)
    expect(listText('finance:overview.upcoming.moneyOut')).toMatch(/Supplier A[\s\S]*Supplier B/)
    expect(normalizeSpaces(document.body.textContent)).toContain(
      normalizeSpaces(formatCurrency('570.500', { currency: 'TND', locale: 'fr-TN' }))
    )

    const overdueRow = container.querySelector('[data-overdue="true"]')
    expect(overdueRow).toHaveTextContent('Comptoir Nord')
  })

  it('renders empty upcoming payment states when no lines are due', () => {
    renderOverview()

    expect(screen.getByText('finance:overview.upcoming.emptyIn')).toBeInTheDocument()
    expect(screen.getByText('finance:overview.upcoming.emptyOut')).toBeInTheDocument()
  })

  it('renders an OwnerChart with an honest current-period revenue-vs-expenses option', () => {
    renderOverview()

    expect(screen.getByTestId('owner-chart')).toHaveTextContent(
      'finance:overview.trend.revenueVsExpenses'
    )
    expect(ownerChartProps).toHaveLength(1)
    expect(ownerChartProps[0].option.series).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          type: 'bar',
          data: ['1200.000', '800.000'],
        }),
      ])
    )
  })
})

function upcomingLine(
  partnerName: string,
  documentNumber: string,
  type: string,
  dueDate: string,
  balanceDue: string,
  daysUntilDue: number,
  overdue: boolean,
): App.Modules.Accounting.Application.DTOs.Reports.UpcomingPaymentLineData {
  return {
    partner_name: partnerName,
    document_number: documentNumber,
    type,
    due_date: dueDate,
    balance_due: balanceDue,
    days_until_due: daysUntilDue,
    overdue,
    source: 'document',
    certainty: null,
    location_id: null,
  }
}

function listText(heading: string): string {
  const headingNode = screen.getByText(heading)
  const section = headingNode.closest('section')
  expect(section).not.toBeNull()
  return section?.textContent ?? ''
}
