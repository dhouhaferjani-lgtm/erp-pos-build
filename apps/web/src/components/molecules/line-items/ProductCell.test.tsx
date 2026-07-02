import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ProductCell } from './ProductCell'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => ({
      'sales:lineItems.productImageAlt': 'Product image',
      'sales:lineItems.productImagePlaceholder': 'No product image',
      'sales:lineItems.stockBadge': 'Stock',
    }[key] ?? key),
  }),
}))

describe('ProductCell', () => {
  it('renders product identity with image, SKU, and barcode', () => {
    render(
      <ProductCell
        product={{
          name: 'Crème solaire SPF50',
          sku: 'CS-050',
          barcode: '6194000123456',
          primary_image_url: '/media/product.webp',
        }}
      />,
    )

    expect(screen.getByText('Crème solaire SPF50')).toBeInTheDocument()
    expect(screen.getByText('CS-050')).toBeInTheDocument()
    expect(screen.getByText('6194000123456')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Crème solaire SPF50' })).toHaveAttribute('src', '/media/product.webp')
  })

  it('uses an accessible placeholder when the product has no image', () => {
    render(
      <ProductCell
        product={{
          name: 'Bandage',
          sku: 'BDG-001',
          barcode: null,
          primary_image_url: null,
        }}
      />,
    )

    expect(screen.getByLabelText('No product image')).toBeInTheDocument()
    expect(screen.queryByText('null')).not.toBeInTheDocument()
  })

  it('can render a stock badge when stock text is provided', () => {
    render(
      <ProductCell
        product={{
          name: 'Paracetamol',
          sku: 'PARA',
          barcode: null,
          primary_image_url: null,
        }}
        stockLabel="12 on hand"
      />,
    )

    expect(screen.getByText('12 on hand')).toBeInTheDocument()
  })
})
