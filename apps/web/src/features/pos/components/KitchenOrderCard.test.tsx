import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { KitchenOrderCard } from './KitchenOrderCard'
import type { OrderData, OrderLineData } from '../api/orderApi'

// Translate by echoing the key so assertions can match on the key text.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

// Isolate child atoms/molecules — their internals are out of scope here.
vi.mock('../atoms/KitchenTimer', () => ({
  KitchenTimer: () => <span data-testid="kitchen-timer" />,
}))
vi.mock('../molecules/OrderStatusBadge', () => ({
  OrderStatusBadge: ({ status }: { status: string }) => (
    <span data-testid="order-status-badge">{status}</span>
  ),
}))

function makeLine(overrides: Partial<OrderLineData> = {}): OrderLineData {
  return {
    id: 'line-1',
    order_id: 'order-1',
    line_number: 1,
    product_id: 'prod-1',
    product_name: 'Espresso',
    variant_name: null,
    barcode: null,
    quantity: '2',
    unit_price: '3.500',
    discount_amount: '0.000',
    tax_rate: '0.00',
    tax_amount: '0.000',
    line_total: '7.000',
    modifiers: null,
    special_instructions: null,
    status: 'sent',
    sent_at: null,
    prepared_at: null,
    created_at: '2026-06-15 10:00:00',
    ...overrides,
  }
}

function makeOrder(overrides: Partial<OrderData> = {}): OrderData {
  return {
    id: 'order-1',
    terminal_id: 'term-1',
    shift_id: 'shift-1',
    table_id: null,
    order_number: 'A-001',
    status: 'sent_to_kitchen',
    cashier_id: 'cash-1',
    cashier_name: 'Sam',
    customer_name: null,
    customer_identifier: null,
    partner_id: null,
    subtotal: '7.000',
    tax_amount: '0.000',
    discount_amount: '0.000',
    total: '7.000',
    currency: 'TND',
    consumption_mode: null,
    notes: null,
    opened_at: '2026-06-15 10:00:00',
    sent_at: null,
    ready_at: null,
    served_at: null,
    closed_at: null,
    cancelled_at: null,
    receipt_id: null,
    lines: [makeLine()],
    ...overrides,
  }
}

describe('KitchenOrderCard', () => {
  const noop = () => {}

  it('renders the order number and each line product', () => {
    const order = makeOrder({
      lines: [makeLine({ id: 'l1', product_name: 'Espresso' })],
    })
    const { getByText } = render(
      <KitchenOrderCard order={order} onLineStatusChange={noop} onBump={noop} isBumping={false} />
    )
    expect(getByText('A-001')).toBeInTheDocument()
    expect(getByText('Espresso')).toBeInTheDocument()
    expect(getByText('2.0000x')).toBeInTheDocument()
  })

  it('formats a kitchen line quantity at its product unit precision', () => {
    const order = makeOrder({
      lines: [makeLine({ quantity: '1.5', quantity_decimals: 2 } as OrderLineData)],
    })

    const { getByText } = render(
      <KitchenOrderCard order={order} onLineStatusChange={noop} onBump={noop} isBumping={false} />
    )

    expect(getByText('1.50x')).toBeInTheDocument()
  })

  it('renders the translated line-status label for each line', () => {
    const order = makeOrder({
      lines: [makeLine({ id: 'l1', status: 'ready' })],
    })
    const { getByText } = render(
      <KitchenOrderCard order={order} onLineStatusChange={noop} onBump={noop} isBumping={false} />
    )
    expect(getByText('kitchen.lineStatus.ready')).toBeInTheDocument()
  })

  it('advances a sent line to preparing on tap', () => {
    const onLineStatusChange = vi.fn()
    const order = makeOrder({
      lines: [makeLine({ id: 'l1', status: 'sent' })],
    })
    const { getByText } = render(
      <KitchenOrderCard
        order={order}
        onLineStatusChange={onLineStatusChange}
        onBump={noop}
        isBumping={false}
      />
    )
    fireEvent.click(getByText('Espresso'))
    expect(onLineStatusChange).toHaveBeenCalledWith('order-1', 'l1', 'preparing')
  })

  it('shows the bump action when there are pending lines and fires onBump', () => {
    const onBump = vi.fn()
    const order = makeOrder({
      lines: [makeLine({ id: 'l1', status: 'preparing' })],
    })
    const { getByText } = render(
      <KitchenOrderCard order={order} onLineStatusChange={noop} onBump={onBump} isBumping={false} />
    )
    const bump = getByText('kitchen.bump')
    fireEvent.click(bump)
    expect(onBump).toHaveBeenCalledWith('order-1')
  })

  it('hides the bump action when no lines are pending', () => {
    const order = makeOrder({
      lines: [makeLine({ id: 'l1', status: 'ready' })],
    })
    const { queryByText } = render(
      <KitchenOrderCard order={order} onLineStatusChange={noop} onBump={noop} isBumping={false} />
    )
    expect(queryByText('kitchen.bump')).not.toBeInTheDocument()
  })
})
