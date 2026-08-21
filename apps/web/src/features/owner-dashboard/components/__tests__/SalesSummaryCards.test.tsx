import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SalesSummaryCards } from '../SalesSummaryCards'
import { formatCurrency } from '@/lib/decimal'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))

const data = {
  currencyCode: 'EUR', grossSales: '300.00', returnsAmount: '50.00', netSales: '250.00',
  salesCount: 2, returnsCount: 1, itemsSold: '5.0000', averageBasket: '150.00',
  delta: {
    grossSalesAbs: '150.00', grossSalesPct: '100.00',
    netSalesPct: '66.67',
    salesCountAbs: 1, salesCountPct: '50.00',
  },
}

describe('SalesSummaryCards', () => {
  it('renders five KPI cards with formatted values', () => {
    render(<SalesSummaryCards data={data} isLoading={false} isError={false} />)
    expect(screen.getByText('reports:ownerDashboard.kpi.netSales')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.kpi.transactions')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.kpi.returns')).toBeInTheDocument()
    expect(screen.getByText('5.0000')).toBeInTheDocument()  // itemsSold via formatQuantity
  })

  // O-28 (owner ruling 2026-08-21, LEDGER row O-28): the headline figure is NET,
  // EXCLUDING REFUNDS, and must be labelled accordingly. The tile previously bound
  // `grossSales` under a generic "Total sales" label while the backend's already-correct
  // `netSales` went unused, so the number an owner reads for today ignored the day's
  // refunds entirely.
  it('renders the headline from netSales, not grossSales, with the net-of-refunds trend', () => {
    render(<SalesSummaryCards data={data} isLoading={false} isError={false} />)

    expect(screen.getByText(formatCurrency('250.00', true, 'EUR'))).toBeInTheDocument()
    expect(screen.queryByText(formatCurrency('300.00', true, 'EUR'))).not.toBeInTheDocument()
    // Trend badge must come from the same definition as the figure it sits under:
    // netSalesPct 66.67, never grossSalesPct 100.
    expect(screen.getByText('+66.67%')).toBeInTheDocument()
    expect(screen.queryByText('+100%')).not.toBeInTheDocument()
  })

  it('renders a live badge on total sales when the selected range includes today', () => {
    render(<SalesSummaryCards data={data} isLoading={false} isError={false} isLive />)
    expect(screen.getByText('reports:ownerDashboard.kpi.live')).toBeInTheDocument()
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
