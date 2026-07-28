import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { LandedCostBreakdown } from './LandedCostBreakdown'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 2 }),
}))

describe('LandedCostBreakdown', () => {
  it('formats line quantities with the unit precision supplied by the payload', () => {
    render(
      <LandedCostBreakdown
        lines={[
          {
            id: 'line-1',
            description: 'Precision item',
            quantity: 1,
            quantity_decimals: 3,
            unit_price: 10,
            total: 10,
          },
        ]}
        totalAdditionalCosts={0}
      />,
    )

    expect(screen.getByText('1.000')).toBeInTheDocument()
  })
})
