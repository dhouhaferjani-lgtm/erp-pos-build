import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ProductStockLevels } from './ProductStockLevels'

const queryData = vi.hoisted(() => ({ locations: [] as Record<string, unknown>[] }))
const permissionState = vi.hoisted(() => ({ canTransfer: true }))

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({
    isLoading: false,
    data: {
      totals: {
        quantity: '5.0000',
        reserved: '1.0000',
        available: '4.0000',
        incoming: '2.0000',
        projected_available: '6.0000',
      },
      locations: queryData.locations,
    },
  }),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))
vi.mock('@/components/auth', () => ({ RequirePermission: ({ children }: { children: React.ReactNode }) => permissionState.canTransfer ? <>{children}</> : null }))
vi.mock('react-router-dom', () => ({ Link: ({ to, children }: { to: string; children: React.ReactNode }) => <a href={to}>{children}</a> }))
vi.mock('./ThresholdEditCell', () => ({ ThresholdEditCell: () => null }))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 3 }),
}))

describe('ProductStockLevels', () => {
  beforeEach(() => {
    queryData.locations = []
    permissionState.canTransfer = true
  })
  it('keeps quantities visible but hides stock value and WAC without cost permission', () => {
    render(
      <ProductStockLevels
        productId="product-1"
        costPrice="12.500"
        canViewCostPrices={false}
        embedded
      />,
    )

    expect(screen.getByText('stock.onHand').parentElement).toHaveTextContent('5')
    expect(screen.getByText('stock.available').parentElement).toHaveTextContent('4')
    expect(screen.queryByText('stock.stockValue')).not.toBeInTheDocument()
    expect(screen.queryByText(/WAC/)).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'stock.title' })).not.toBeInTheDocument()
  })

  it('shows stock value and WAC only to cost-permission holders', () => {
    render(
      <ProductStockLevels
        productId="product-1"
        costPrice="12.500"
        canViewCostPrices
        currency="TND"
        locale="en-US"
        embedded
      />,
    )

    expect(screen.getByText('stock.stockValue')).toBeInTheDocument()
    expect(screen.getByText(/WAC/)).toBeInTheDocument()
  })

  it('renders reserved stock and a transfer-from CTA per location', () => {
    queryData.locations = [{ id: 'row-a', location_id: 'loc-a', location_name: 'Main', quantity: '5.0000', reserved: '1.0000', available: '4.0000', incoming: '2.0000', projected_available: '6.0000', min_quantity: '2.0000', max_quantity: '8.0000', is_below_minimum: false }]
    render(<ProductStockLevels productId="product-1" costPrice={null} canViewCostPrices={false} embedded />)
    expect(screen.getByText('stock.reserved').parentElement).toHaveTextContent('1')
    expect(screen.getByRole('link', { name: /stock.transferFromHere/ })).toHaveAttribute('href', '/inventory/stock-transfers/new?source_location_id=loc-a&product_id=product-1')
  })

  it('hides transfer CTA without transfer permission', () => {
    permissionState.canTransfer = false
    queryData.locations = [{ id: 'row-a', location_id: 'loc-a', location_name: 'Main', quantity: '5.0000', reserved: '1.0000', available: '4.0000', incoming: '2.0000', projected_available: '6.0000', min_quantity: null, max_quantity: null, is_below_minimum: false }]
    render(<ProductStockLevels productId="product-1" costPrice={null} canViewCostPrices={false} embedded />)
    expect(screen.queryByRole('link', { name: /stock.transferFromHere/ })).not.toBeInTheDocument()
    permissionState.canTransfer = true
  })
})
