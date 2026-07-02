import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { ReceiveGoodsDialog, type ReceivablePurchaseOrder } from './ReceiveGoodsDialog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const map: Record<string, string> = {
        'purchaseOrders.receiveGoodsTitle': 'Receive goods',
        'purchaseOrders.receive.remaining': 'Remaining paid',
        'purchaseOrders.receive.remainingFree': 'Remaining free',
        'purchaseOrders.receive.quantity': 'Paid received',
        'purchaseOrders.receive.freeQuantity': 'Free received',
        'purchaseOrders.receive.freeOrderedSummary': 'Free ordered: {{ordered}} · received: {{received}}',
        'purchaseOrders.receive.batchNumber': 'Batch number',
        'purchaseOrders.receive.expiryDate': 'Expiry date',
        'purchaseOrders.receive.submit': 'Receive',
        'common:cancel': 'Cancel',
        'common:status.loading': 'Loading',
      }
      let result = map[key] ?? key
      if (params) {
        Object.entries(params).forEach(([paramKey, value]) => {
          result = result.replace(`{{${paramKey}}}`, String(value))
        })
      }
      return result
    },
  }),
}))

const purchaseOrderWithBonus: ReceivablePurchaseOrder = {
  lines: [
    {
      id: 'line-1',
      description: 'Doliprane 1000mg',
      product_name: 'Doliprane',
      quantity: '20.0000',
      quantity_received: '5.0000',
      free_quantity: '1.0000',
      free_quantity_received: '0.0000',
      quantity_decimals: 4,
    },
  ],
}

describe('ReceiveGoodsDialog', () => {
  it('submits paid and free receipt quantities as separate string maps', async () => {
    const user = userEvent.setup()
    const onConfirm = vi.fn()

    render(
      <ReceiveGoodsDialog
        isOpen={true}
        purchaseOrder={purchaseOrderWithBonus}
        isLoading={false}
        onClose={vi.fn()}
        onConfirm={onConfirm}
      />,
    )

    expect(screen.getByText('Free ordered: 1.0000 · received: 0.0000')).toBeInTheDocument()

    const paidInput = screen.getByRole('spinbutton', { name: 'Paid received Doliprane' })
    await user.clear(paidInput)
    await user.type(paidInput, '2.5000')

    const freeInput = screen.getByRole('spinbutton', { name: 'Free received Doliprane' })
    await user.clear(freeInput)
    await user.type(freeInput, '1.0000')

    await user.click(screen.getByRole('button', { name: 'Receive' }))

    expect(onConfirm).toHaveBeenCalledWith({
      quantities: {
        'line-1': '2.5',
      },
      free_quantities: {
        'line-1': '1',
      },
    })
  })

  it('allows a free-only receipt when no paid quantity remains', async () => {
    const user = userEvent.setup()
    const onConfirm = vi.fn()

    render(
      <ReceiveGoodsDialog
        isOpen={true}
        purchaseOrder={{
          lines: [
            {
              ...purchaseOrderWithBonus.lines![0],
              quantity_received: '20.0000',
              free_quantity_received: '0.0000',
            },
          ],
        }}
        isLoading={false}
        onClose={vi.fn()}
        onConfirm={onConfirm}
      />,
    )

    expect(screen.queryByRole('spinbutton', { name: 'Paid received Doliprane' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Receive' }))

    expect(onConfirm).toHaveBeenCalledWith({
      quantities: {},
      free_quantities: {
        'line-1': '1.0000',
      },
    })
  })
})
