import { describe, expect, it, vi, beforeEach } from 'vitest'
import type { EChartsOption } from 'echarts'
import { render, screen } from '@testing-library/react'
import { TreasuryOverviewPage } from './TreasuryOverviewPage'
import type { PaymentRepository } from '@/features/treasury/hooks/usePaymentRepositories'
import { formatCurrency } from '@/lib/format'
import type { ProfitLossData } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown): string =>
      typeof second === 'string' ? second : key,
  }),
}))

const {
  mockUsePaymentRepositories,
  mockUseUpcomingPayments,
  mockUseProfitLoss,
  ownerChartProps,
} = vi.hoisted(() => ({
  mockUsePaymentRepositories: vi.fn(),
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

vi.mock('@/features/treasury/hooks/usePaymentRepositories', () => ({
  usePaymentRepositories: () => mockUsePaymentRepositories() as unknown,
}))

vi.mock('../hooks/useUpcomingPayments', () => ({
  useUpcomingPayments: () => mockUseUpcomingPayments() as unknown,
}))

vi.mock('../hooks/useProfitLoss', () => ({
  useProfitLoss: () => mockUseProfitLoss() as unknown,
}))

vi.mock('@/features/finance/components/FinanceWidget', () => ({
  FinanceWidget: () => <section data-testid="finance-widget">Finance widget</section>,
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

function repository(
  id: string,
  type: PaymentRepository['type'],
  currentBalance: string | undefined,
): PaymentRepository {
  return {
    id,
    code: id,
    name: id,
    type,
    is_active: true,
    is_default: false,
    balance: currentBalance ?? '0',
  }
}

function normalizeSpaces(value: string | null): string {
  return (value ?? '').replace(/\s/g, ' ')
}

beforeEach(() => {
  ownerChartProps.length = 0
  mockUsePaymentRepositories.mockReturnValue({
    data: [
      repository('cash-1', 'cash_register', '100.125'),
      repository('cash-2', 'cash_register', '25.625'),
      repository('bank-1', 'bank_account', '50.001'),
      repository('safe-1', 'safe', undefined),
      repository('virtual-1', 'virtual', '999.999'),
    ],
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
  it('sums repository balance strings into cash-position StatCards and mounts FinanceWidget', () => {
    render(<TreasuryOverviewPage />)

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
      },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    const { container } = render(<TreasuryOverviewPage />)

    expect(screen.getByText('finance:overview.upcoming.moneyIn')).toBeInTheDocument()
    expect(screen.getByText('finance:overview.upcoming.moneyOut')).toBeInTheDocument()
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
    render(<TreasuryOverviewPage />)

    expect(screen.getByText('finance:overview.upcoming.emptyIn')).toBeInTheDocument()
    expect(screen.getByText('finance:overview.upcoming.emptyOut')).toBeInTheDocument()
  })

  it('renders an OwnerChart with an honest current-period revenue-vs-expenses option', () => {
    render(<TreasuryOverviewPage />)

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
  }
}

function listText(heading: string): string {
  const headingNode = screen.getByText(heading)
  const section = headingNode.closest('section')
  expect(section).not.toBeNull()
  return section?.textContent ?? ''
}
