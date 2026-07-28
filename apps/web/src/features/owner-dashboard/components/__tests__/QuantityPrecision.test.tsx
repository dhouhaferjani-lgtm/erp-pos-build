import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { LowStockAlertsList } from '../LowStockAlertsList'
import { TopSkusWidget } from '../TopSkusWidget'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('owner dashboard quantity precision', () => {
  it('formats both low-stock quantities at the product unit precision', () => {
    render(
      <MemoryRouter>
        <LowStockAlertsList
          data={[
            {
              product_id: 'product-1',
              product_name: 'Bulk item',
              location_id: 'location-1',
              location_name: 'Main',
              quantity: '1.5',
              min_quantity: '2',
              quantity_decimals: 3,
              threshold_pct: 100,
              severity: 'critical',
            },
          ]}
        />
      </MemoryRouter>,
    )

    expect(screen.getByText('1.500/2.000')).toBeInTheDocument()
  })

  it('formats top-SKU quantity at the product unit precision', () => {
    render(
      <MemoryRouter>
        <TopSkusWidget
          data={[
            {
              product_id: 'product-1',
              product_name: 'Bulk item',
              sku: 'BULK',
              quantity: '1.5',
              quantity_decimals: 3,
              revenue: '10.000',
            },
          ]}
          sortBy="quantity"
          onSortByChange={() => undefined}
        />
      </MemoryRouter>,
    )

    expect(screen.getByText('1.500')).toBeInTheDocument()
  })
})
