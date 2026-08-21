import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { OwnerDashboardPage } from '../OwnerDashboardPage'
import type { OwnerDateRangeParams, SalesByLocationParams, StockAlertsParams, TopSkusParams } from '../api/ownerReportsApi'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('echarts-for-react', () => ({
  __esModule: true,
  default: () => <div data-testid="owner-chart" />,
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: () => true,
  }),
}))

vi.mock('@/features/locations/hooks/useViewScope', () => ({
  useViewScope: () => ({
    scope: 'all',
    effectiveLocationIds: [],
    isAll: true,
    setScope: vi.fn(),
  }),
}))

vi.mock('@/features/treasury/components/CashPositionWidget', () => ({
  CashPositionWidget: () => <div data-testid="cash-position-widget" />,
}))

vi.mock('../components/CashAcrossStoresWidget', () => ({
  CashAcrossStoresWidget: () => <div data-testid="cash-across-stores-widget" />,
}))
vi.mock('../components/DueThisWeekWidget', () => ({
  DueThisWeekWidget: () => <div data-testid="due-this-week-widget" />,
}))
vi.mock('../components/RebalanceAlertsWidget', () => ({
  RebalanceAlertsWidget: () => <div data-testid="rebalance-alerts-widget" />,
}))

const ownerReportHookMocks = vi.hoisted(() => ({
  useSalesSummary: vi.fn(),
  useSalesByLocation: vi.fn(),
  useTopSkus: vi.fn(),
  useRevenueByCategory: vi.fn(),
  usePaymentMethodBreakdown: vi.fn(),
  useLowStockAlerts: vi.fn(),
  useCashRegisterReconciliation: vi.fn(),
  useLiveSales: vi.fn(),
}))

vi.mock('../hooks/useOwnerReports', () => ({
  useSalesSummary: ownerReportHookMocks.useSalesSummary,
  useSalesByLocation: ownerReportHookMocks.useSalesByLocation,
  useTopSkus: ownerReportHookMocks.useTopSkus,
  useRevenueByCategory: ownerReportHookMocks.useRevenueByCategory,
  usePaymentMethodBreakdown: ownerReportHookMocks.usePaymentMethodBreakdown,
  useLowStockAlerts: ownerReportHookMocks.useLowStockAlerts,
  useCashRegisterReconciliation: ownerReportHookMocks.useCashRegisterReconciliation,
  useLiveSales: ownerReportHookMocks.useLiveSales,
}))

describe('OwnerDashboardPage', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-03T10:15:00Z'))
    ownerReportHookMocks.useSalesSummary.mockReturnValue({
      data: {
        currencyCode: 'EUR',
        grossSales: '300.00',
        returnsAmount: '50.00',
        netSales: '250.00',
        salesCount: 2,
        returnsCount: 1,
        itemsSold: '5.0000',
        averageBasket: '150.00',
        delta: {
          grossSalesAbs: '150.00',
          grossSalesPct: '100.00',
          netSalesAbs: '100.00',
          netSalesPct: '66.67',
          salesCountAbs: 1,
          salesCountPct: '50.00',
        },
      },
      isLoading: false,
      isError: false,
    })
    ownerReportHookMocks.useSalesByLocation.mockReturnValue({
      data: [
        {
          period: '2026-05-01',
          company_id: 'company-1',
          company_name: 'Company',
          location_id: 'loc-1',
          location_name: 'Downtown',
          gross_sales: '120',
          receipt_count: 2,
        },
      ],
      isLoading: false,
      isError: false,
    })
    ownerReportHookMocks.useTopSkus.mockReturnValue({
      data: [{ product_id: 'prod-1', product_name: 'Brake Pads', sku: 'BRAKE', revenue: '200', quantity: '2', quantity_decimals: 0 }],
      isLoading: false,
      isError: false,
    })
    ownerReportHookMocks.useRevenueByCategory.mockReturnValue({
      data: [{ category_id: 1, category_name: 'Parts', revenue: '200', percentage: '100.00', quantity: '2' }],
      isLoading: false,
      isError: false,
    })
    ownerReportHookMocks.usePaymentMethodBreakdown.mockReturnValue({
      data: [{ payment_type: 'cash', payment_method_name: 'Cash', amount: '120', percentage: '100.00', transaction_count: 1 }],
      isLoading: false,
      isError: false,
    })
    ownerReportHookMocks.useLowStockAlerts.mockReturnValue({
      data: [{ product_id: 'prod-2', product_name: 'No Stock', location_id: 'loc-1', location_name: 'Downtown', quantity: '0', min_quantity: '10', quantity_decimals: 0, threshold_pct: 100, severity: 'out_of_stock' }],
      isLoading: false,
      isError: false,
    })
    ownerReportHookMocks.useCashRegisterReconciliation.mockReturnValue({
      data: [{ date: '2026-05-06', location_id: 'loc-1', location_name: 'Downtown', terminal_id: 'term-1', terminal_name: 'POS 1', shift_id: 'shift-1', expected_cash: '200', counted_cash: '195', variance: '-5', variance_severity: 'warning' }],
      isLoading: false,
      isError: false,
    })
    ownerReportHookMocks.useLiveSales.mockReturnValue({
      data: {
        recent_receipts: [],
        open_shifts_by_location: {},
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

  function renderPage() {
    return render(
      <MemoryRouter>
        <OwnerDashboardPage />
      </MemoryRouter>,
    )
  }

  it('renders the owner reporting widgets in the dashboard layout', () => {
    renderPage()

    expect(screen.getByText('reports:ownerDashboard.salesTrend.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.branchLeaderboard.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.liveSales.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.topSkus.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.lowStock.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.revenueByCategory.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.paymentMethods.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.cashReconciliation.title')).toBeInTheDocument()
    expect(screen.getByTestId('cash-across-stores-widget')).toBeInTheDocument()
    expect(screen.getByTestId('due-this-week-widget')).toBeInTheDocument()
    expect(screen.getByTestId('rebalance-alerts-widget')).toBeInTheDocument()
  })

  it('renders chart-backed widgets through the shared owner chart wrapper', () => {
    renderPage()

    expect(screen.getAllByTestId('owner-chart')).toHaveLength(4)
  })

  it('renders the KPI summary row', () => {
    renderPage()
    expect(screen.getByText('reports:ownerDashboard.kpi.netSales')).toBeInTheDocument()
  })

  it('defaults the dashboard query to today with hour granularity', () => {
    renderPage()

    expect(ownerReportHookMocks.useSalesSummary).toHaveBeenCalledWith(
      expect.objectContaining<OwnerDateRangeParams>({ from: '2026-07-03', to: '2026-07-03' }),
      true,
    )
    expect(ownerReportHookMocks.useSalesByLocation).toHaveBeenCalledWith(
      expect.objectContaining<SalesByLocationParams>({ from: '2026-07-03', to: '2026-07-03', granularity: 'hour' }),
      true,
    )
  })

  it('passes preset date ranges into the owner report hooks', () => {
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: 'reports:ownerDashboard.filters.last7Days' }))
    expect(ownerReportHookMocks.useSalesByLocation).toHaveBeenCalledWith(
      expect.objectContaining<SalesByLocationParams>({ from: '2026-06-27', to: '2026-07-03', granularity: 'day' }),
      true,
    )
    expect(ownerReportHookMocks.useTopSkus).toHaveBeenLastCalledWith(
      expect.objectContaining<TopSkusParams>({ from: '2026-06-27', to: '2026-07-03' }),
      true,
    )

    fireEvent.click(screen.getByRole('button', { name: 'reports:ownerDashboard.filters.today' }))
    expect(ownerReportHookMocks.useSalesByLocation).toHaveBeenCalledWith(
      expect.objectContaining<SalesByLocationParams>({ from: '2026-07-03', to: '2026-07-03', granularity: 'hour' }),
      true,
    )
    expect(ownerReportHookMocks.useLowStockAlerts).toHaveBeenLastCalledWith(
      expect.objectContaining<StockAlertsParams>({ threshold_pct: 100 }),
      true,
    )
  })

  it('requests a same-weekday-last-week comparison series for the hourly today trend', () => {
    renderPage()

    expect(ownerReportHookMocks.useSalesByLocation).toHaveBeenCalledWith(
      expect.objectContaining<SalesByLocationParams>({ from: '2026-06-26', to: '2026-06-26', granularity: 'hour' }),
      true,
    )
  })

  it('links product names in owner widgets to product detail pages', () => {
    renderPage()

    expect(screen.getByRole('link', { name: 'Brake Pads' })).toHaveAttribute('href', '/inventory/products/prod-1')
    expect(screen.getByRole('link', { name: 'No Stock' })).toHaveAttribute('href', '/inventory/products/prod-2')
  })
})
