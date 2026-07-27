import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { LandedCostBreakdown } from './LandedCostBreakdown'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('../../../hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { currency: 'EUR' } }),
}))

describe('LandedCostBreakdown', () => {
  it('formats line quantities at their unit scales and the total at the maximum included scale', () => {
    render(
      <LandedCostBreakdown
        lines={[
          {
            lineId: 'line-1',
            productName: 'Piece item',
            quantity: 1.5,
            quantity_decimals: 2,
            unitPrice: 10,
            lineTotal: 15,
            allocatedCosts: 0,
            landedUnitCost: 10,
            proportion: 0.5,
          },
          {
            lineId: 'line-2',
            productName: 'Weighted item',
            quantity: 2,
            quantity_decimals: 3,
            unitPrice: 10,
            lineTotal: 20,
            allocatedCosts: 0,
            landedUnitCost: 10,
            proportion: 0.5,
          },
        ]}
      />,
    )

    expect(screen.getByText('1.50')).toBeInTheDocument()
    expect(screen.getByText('2.000')).toBeInTheDocument()
    expect(screen.getByText('3.500')).toBeInTheDocument()
  })
})
