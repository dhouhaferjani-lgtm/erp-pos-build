import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { OwnerDashboardPage } from '../OwnerDashboardPage'

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

vi.mock('../hooks/useOwnerReports', () => ({
  useSalesSummary: () => ({
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
        salesCountAbs: 1,
        salesCountPct: '50.00',
      },
    },
    isLoading: false,
    isError: false,
  }),
  useSalesByLocation: () => ({
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
  }),
  useTopSkus: () => ({
    data: [{ product_id: 'prod-1', product_name: 'Brake Pads', sku: 'BRAKE', revenue: '200', quantity: '2' }],
    isLoading: false,
    isError: false,
  }),
  useRevenueByCategory: () => ({
    data: [{ category_id: 1, category_name: 'Parts', revenue: '200', percentage: '100.00', quantity: '2' }],
    isLoading: false,
    isError: false,
  }),
  usePaymentMethodBreakdown: () => ({
    data: [{ payment_type: 'cash', payment_method_name: 'Cash', amount: '120', percentage: '100.00', transaction_count: 1 }],
    isLoading: false,
    isError: false,
  }),
  useLowStockAlerts: () => ({
    data: [{ product_id: 'prod-2', product_name: 'No Stock', location_id: 'loc-1', location_name: 'Downtown', quantity: '0', min_quantity: '10', threshold_pct: 100, severity: 'out_of_stock' }],
    isLoading: false,
    isError: false,
  }),
  useCashRegisterReconciliation: () => ({
    data: [{ date: '2026-05-06', location_id: 'loc-1', location_name: 'Downtown', terminal_id: 'term-1', terminal_name: 'POS 1', shift_id: 'shift-1', expected_cash: '200', counted_cash: '195', variance: '-5', variance_severity: 'warning' }],
    isLoading: false,
    isError: false,
  }),
}))

describe('OwnerDashboardPage', () => {
  it('renders all six owner reporting widgets', () => {
    render(<OwnerDashboardPage />)

    expect(screen.getByText('reports:ownerDashboard.salesByLocation.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.topSkus.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.lowStock.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.revenueByCategory.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.paymentMethods.title')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.cashReconciliation.title')).toBeInTheDocument()
  })

  it('renders chart-backed widgets through the shared owner chart wrapper', () => {
    render(<OwnerDashboardPage />)

    expect(screen.getAllByTestId('owner-chart')).toHaveLength(4)
  })

  it('renders the KPI summary row', () => {
    render(<OwnerDashboardPage />)
    expect(screen.getByText('reports:ownerDashboard.kpi.totalSales')).toBeInTheDocument()
  })
})
