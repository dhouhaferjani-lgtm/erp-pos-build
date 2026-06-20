import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SalesSummaryCards } from '../SalesSummaryCards'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))

const data = {
  currencyCode: 'EUR', grossSales: '300.00', returnsAmount: '50.00', netSales: '250.00',
  salesCount: 2, returnsCount: 1, itemsSold: '5.0000', averageBasket: '150.00',
  delta: { grossSalesAbs: '150.00', grossSalesPct: '100.00', salesCountAbs: 1, salesCountPct: '50.00' },
}

describe('SalesSummaryCards', () => {
  it('renders five KPI cards with formatted values', () => {
    render(<SalesSummaryCards data={data} isLoading={false} isError={false} />)
    expect(screen.getByText('reports:ownerDashboard.kpi.totalSales')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.kpi.transactions')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.kpi.returns')).toBeInTheDocument()
    expect(screen.getByText('5.0000')).toBeInTheDocument()  // itemsSold via formatQuantity
  })

  it('renders an error card on error', () => {
    render(<SalesSummaryCards data={undefined} isLoading={false} isError />)
    expect(screen.getByText('reports:ownerDashboard.kpi.error')).toBeInTheDocument()
  })

  it('renders a skeleton while loading', () => {
    render(<SalesSummaryCards data={undefined} isLoading isError={false} />)
    expect(screen.getByTestId('kpi-skeleton')).toBeInTheDocument()
  })
})
