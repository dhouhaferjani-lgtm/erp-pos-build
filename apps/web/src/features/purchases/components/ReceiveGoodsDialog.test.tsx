import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { ReceiveGoodsDialog } from './ReceiveGoodsDialog'

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

describe('ReceiveGoodsDialog', () => {
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
            },
          ],
        }}
      />,
    )

    expect(screen.getByRole('img', { name: 'Serum Retinol' })).toHaveAttribute('src', '/retinol.png')
    expect(screen.getByText('SKU-RET')).toBeInTheDocument()
    expect(screen.getByText('619100000001')).toBeInTheDocument()
  })
})
