import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ReceiveGoodsDialog } from './ReceiveGoodsDialog'

const mockHasPermission = vi.hoisted(() => vi.fn(() => true))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@/components/organisms/Modal', () => ({
  Modal: ({ isOpen, children, title }: { isOpen: boolean; children: React.ReactNode; title: string }) =>
    isOpen ? (
      <section aria-label={title}>
        {children}
      </section>
    ) : null,
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

describe('ReceiveGoodsDialog', () => {
  beforeEach(() => {
    mockHasPermission.mockReturnValue(true)
  })

  it('renders receivable lines with ProductCell thumbnail, SKU, and barcode identity', () => {
    render(
      <ReceiveGoodsDialog
        isOpen={true}
        isLoading={false}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        purchaseOrder={{
          lines: [
            {
              id: 'line-1',
              product_name: 'Serum Retinol',
              description: 'Serum Retinol',
              product_code: 'SKU-RET',
              product_barcode: '619100000001',
              primary_image_url: '/retinol.png',
              quantity: '5.0000',
              quantity_received: '0.0000',
              requires_batch_tracking: false,
              unit_price: '5.000',
            },
          ],
        }}
      />,
    )

    expect(screen.getByRole('img', { name: 'Serum Retinol' })).toHaveAttribute('src', '/retinol.png')
    expect(screen.getByText('SKU-RET')).toBeInTheDocument()
    expect(screen.getByText('619100000001')).toBeInTheDocument()
  })

  it('submits only edited delivered unit prices as strings with an override reason', async () => {
    const onConfirm = vi.fn()
    render(
      <ReceiveGoodsDialog
        isOpen={true}
        isLoading={false}
        onClose={vi.fn()}
        onConfirm={onConfirm}
        purchaseOrder={{
          currency: 'TND',
          lines: [
            {
              id: 'line-1',
              product_name: 'Serum Retinol',
              description: 'Serum Retinol',
              quantity: '5.0000',
              quantity_received: '0.0000',
              requires_batch_tracking: false,
              unit_price: '5.000',
            },
            {
              id: 'line-2',
              product_name: 'SPF Cream',
              description: 'SPF Cream',
              quantity: '2.0000',
              quantity_received: '0.0000',
              requires_batch_tracking: false,
              unit_price: '12.000',
            },
          ],
        }}
      />,
    )

    expect(screen.queryByLabelText('purchaseOrders.receive.priceOverrideReason')).not.toBeInTheDocument()

    await userEvent.type(
      screen.getByLabelText('purchaseOrders.receive.deliveredUnitPrice Serum Retinol'),
      '5.200',
    )

    expect(screen.getByText('+4.0%')).toBeInTheDocument()
    await userEvent.type(
      screen.getByLabelText('purchaseOrders.receive.priceOverrideReason'),
      'Supplier delivery note changed the price',
    )
    await userEvent.click(screen.getByRole('button', { name: 'purchaseOrders.receive.submit' }))

    expect(onConfirm).toHaveBeenCalledWith({
      quantities: {
        'line-1': '5.0000',
        'line-2': '2.0000',
      },
      received_unit_prices: {
        'line-1': '5.2',
      },
      price_override_reason: 'Supplier delivery note changed the price',
    })
  })

  it('renders delivered unit price as read-only without edit permission', () => {
    mockHasPermission.mockReturnValue(false)

    render(
      <ReceiveGoodsDialog
        isOpen={true}
        isLoading={false}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        purchaseOrder={{
          currency: 'TND',
          lines: [
            {
              id: 'line-1',
              product_name: 'Serum Retinol',
              description: 'Serum Retinol',
              quantity: '5.0000',
              quantity_received: '0.0000',
              requires_batch_tracking: false,
              unit_price: '5.000',
            },
          ],
        }}
      />,
    )

    expect(screen.queryByLabelText('purchaseOrders.receive.deliveredUnitPrice Serum Retinol')).not.toBeInTheDocument()
    expect(screen.getAllByText('5.000')).not.toHaveLength(0)
    expect(screen.getByText('purchaseOrders.receive.priceEditReadOnly')).toBeInTheDocument()
  })
  const minimalPo = {
    lines: [
      {
        id: 'line-1',
        product_name: 'CoQ10 100mg - 60 Softgels',
        description: 'CoQ10 100mg - 60 Softgels',
        quantity: '30.0000',
        quantity_received: '0.0000',
        unit_price: '12.000',
      },
    ],
  }

  it('shows an em dash variance when the delivered price equals the PO price', () => {
    mockHasPermission.mockReturnValue(true)
    render(
      <ReceiveGoodsDialog isOpen={true} isLoading={false} onClose={vi.fn()} onConfirm={vi.fn()} purchaseOrder={minimalPo} />,
    )
    fireEvent.change(screen.getByLabelText('purchaseOrders.receive.quantity CoQ10 100mg - 60 Softgels'), { target: { value: '5' } })
    fireEvent.change(screen.getByLabelText('purchaseOrders.receive.deliveredUnitPrice CoQ10 100mg - 60 Softgels'), { target: { value: '12.000' } })
    expect(screen.getAllByText('purchaseOrders.receive.noVariance').length).toBeGreaterThan(0)
  })

  it('queries the goods-receipt.edit-price permission key for the price cell', () => {
    mockHasPermission.mockReturnValue(false)
    render(
      <ReceiveGoodsDialog isOpen={true} isLoading={false} onClose={vi.fn()} onConfirm={vi.fn()} purchaseOrder={minimalPo} />,
    )
    expect(mockHasPermission).toHaveBeenCalledWith('goods-receipt.edit-price')
  })
})
