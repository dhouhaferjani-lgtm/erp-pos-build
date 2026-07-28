import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { OrderLineItem } from './OrderLineItem'
import type { OrderLineData } from '../../api/orderApi'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const line: OrderLineData = {
  id: 'line-1', order_id: 'order-1', line_number: 1, product_id: 'product-1',
  product_name: 'Olive oil', variant_name: null, barcode: null, quantity_decimals: 2,
  quantity: '1.5', unit_price: '12.000', discount_amount: '0.000', tax_rate: '0',
  tax_amount: '0.000', line_total: '18.000', modifiers: null, special_instructions: null,
  status: 'pending', sent_at: null, prepared_at: null, created_at: '2026-07-27T10:00:00Z',
}

describe('OrderLineItem', () => {
  it('uses line quantity_decimals instead of the scale-four fallback for order quantities', () => {
    render(<OrderLineItem line={line} editable={false} />)

    expect(screen.getByText('orders.quantity:')).toBeInTheDocument()
    expect(screen.getByText('1.50')).toBeInTheDocument()
  })
})
