import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ProductHero } from './ProductHero'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => ({
      'inventory:products.onHandShort': 'On hand',
      'inventory:products.costWac': 'WAC',
      'inventory:products.marginPercent': 'Margin %',
      'inventory:products.priceHt': 'Sale price (excl. tax)',
      'inventory:products.priceTtc': 'Sale price (incl. tax)',
      'inventory:products.readyToSell': 'Ready to sell',
      'inventory:products.barcode': 'Barcode',
      'inventory:products.sku': 'SKU',
      'inventory:products.status': 'Status',
      'inventory:products.brand': 'Brand',
      'inventory:products.category': 'Category',
      'common:status.active': 'Active',
      'common:status.inactive': 'Inactive',
    }[key] ?? key),
  }),
}))

describe('ProductHero', () => {
  it('renders identity and primary image without pricing or cost facts', () => {
    render(
      <ProductHero
        product={{
          id: 'product-1',
          name: 'Crème solaire SPF50',
          sku: 'CS-050',
          barcode: '6194000123456',
          is_active: true,
          sale_price: '14.280',
          cost_price: '8.500',
          tax_rate: '19.00',
          primary_image_url: '/media/product/serve?signature=abc',
          stock_quantity: '24.0000',
          brand: { id: 'brand-1', name: 'Avène', source: 'enriched' },
          category: { id: 'category-1', name: 'Soin solaire' },
        }}
      />,
    )

    expect(screen.getByRole('img', { name: 'Crème solaire SPF50' })).toHaveAttribute(
      'src',
      '/media/product/serve?signature=abc&variant=md',
    )
    expect(screen.getByRole('heading', { name: 'Crème solaire SPF50' })).toBeInTheDocument()
    expect(screen.getByText('6194000123456')).toBeInTheDocument()
    expect(screen.getByText('Avène ✦')).toBeInTheDocument()
    expect(screen.getByText('Soin solaire')).toBeInTheDocument()
    expect(screen.queryByText('24')).not.toBeInTheDocument()
    expect(screen.queryByText('68.0%')).not.toBeInTheDocument()
    expect(screen.queryByText('14,280 TND')).not.toBeInTheDocument()
    expect(screen.queryByText('16,993 TND')).not.toBeInTheDocument()
    expect(screen.queryByText('WAC')).not.toBeInTheDocument()
  })
})
