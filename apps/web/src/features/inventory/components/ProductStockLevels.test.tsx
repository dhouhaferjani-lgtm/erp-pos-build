import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { ProductStockLevels } from './ProductStockLevels'

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
      locations: [],
    },
  }),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 3 }),
}))

describe('ProductStockLevels', () => {
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
})
