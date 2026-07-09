import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { PriceInputWithMargin } from './PriceInputWithMargin'

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({
    data: {
      data: {
        cost_price: '800.000',
        sell_price: '1000.000',
        margin_level: {
          level: 'green',
          message: 'OK',
          percentage: 25,
        },
        can_sell: true,
        suggested_price: '1234.000',
        margins: {
          target_margin: '30.00',
          minimum_margin: '15.00',
          source: 'product',
        },
      },
    },
    isLoading: false,
  }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'TND', decimals: 3 }),
  getDecimals: () => 3,
}))

vi.mock('@/stores/authStore', () => ({
  useAuthStore: Object.assign(
    (selector: (state: { user: { tenant_id: string } }) => unknown) =>
      selector({ user: { tenant_id: 'tenant-1' } }),
    { getState: () => ({ user: { tenant_id: 'tenant-1' } }) },
  ),
}))

vi.mock('@/stores/companyStore', () => ({
  useCompanyStore: Object.assign(
    (selector: (state: { currentCompanyId: string }) => unknown) =>
      selector({ currentCompanyId: 'company-1' }),
    { getState: () => ({ currentCompanyId: 'company-1' }) },
  ),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('PriceInputWithMargin', () => {
  it('applies suggested prices as ungrouped editable decimal strings', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()

    render(
      <PriceInputWithMargin
        productId="product-1"
        value="1000.000"
        onChange={onChange}
      />,
    )

    await user.click(screen.getByRole('button', { name: /pricing\.suggestedPriceButton/i }))

    expect(screen.getByRole('spinbutton')).toHaveValue(1234)
    expect((screen.getByRole('spinbutton') as HTMLInputElement).value).toBe('1234.000')
    expect(onChange).toHaveBeenCalledWith('1234.000')
  })
})
