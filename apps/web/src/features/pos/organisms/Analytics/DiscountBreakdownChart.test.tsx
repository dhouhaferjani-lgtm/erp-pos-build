import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { DiscountBreakdownChart } from './DiscountBreakdownChart'
import type { DiscountAnalysis } from '../../api/analyticsApi'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('DiscountBreakdownChart', () => {
  it('renders each discounted product quantity at its unit precision', () => {
    const data: DiscountAnalysis = {
      total_discount_amount: '1.000',
      discount_count: 1,
      by_reason: [],
      top_discounted_products: [
        {
          product_id: 'product-fractional',
          product_name: 'Bulk spice',
          discount_amount: '1.000',
          quantity: '1.2',
          quantity_decimals: 3,
        },
      ],
    }

    render(<DiscountBreakdownChart data={data} />)

    expect(screen.getByText('1.200')).toBeInTheDocument()
  })
})
