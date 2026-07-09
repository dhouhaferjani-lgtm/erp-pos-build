import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { PricingModeCalculator } from './PricingModeCalculator'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('PricingModeCalculator', () => {
  it('emits HT sale price strings when margin mode changes', () => {
    const onSalePriceHtChange = vi.fn()

    render(
      <PricingModeCalculator
        costPrice="80.000"
        salePriceHt="96.000"
        moneyScale={3}
        onSalePriceHtChange={onSalePriceHtChange}
      />,
    )

    fireEvent.change(screen.getByLabelText('pricing.marginPercent'), {
      target: { value: '25.00' },
    })

    expect(onSalePriceHtChange).toHaveBeenCalledWith('100.000')
  })
})
