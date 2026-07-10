import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ProductHero } from './ProductHero'
import { makeProductSectionProduct } from '../../sections/__fixtures__/productSectionProduct'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => ({
      'inventory:products.sku': 'SKU',
      'common:status.active': 'Active',
      'common:status.inactive': 'Inactive',
    }[key] ?? key),
  }),
}))

describe('ProductHero', () => {
  it('renders identity and primary image without pricing or cost facts', () => {
    const product = makeProductSectionProduct({
      id: 'product-1',
      name: 'Crème solaire SPF50',
      sku: 'CS-050',
      barcode: '6194000123456',
      is_active: true,
      primary_image_url: '/media/product/serve?signature=abc',
      brand: {
        id: 'brand-1',
        name: 'Avène',
        slug: 'avene',
        country_of_origin: 'FR',
        website_url: null,
        is_active: true,
      },
      brand_source: 'enriched',
    })

    render(
      <ProductHero product={product} />,
    )

    expect(screen.getByRole('img', { name: 'Crème solaire SPF50' })).toHaveAttribute(
      'src',
      '/media/product/serve?signature=abc&variant=md',
    )
    expect(screen.getByRole('heading', { name: 'Crème solaire SPF50' })).toBeInTheDocument()
    expect(screen.getByText('6194000123456')).toBeInTheDocument()
    expect(screen.getByText('Avène ✦')).toBeInTheDocument()
    expect(screen.getByText('Braking')).toBeInTheDocument()
    expect(screen.queryByText('24')).not.toBeInTheDocument()
    expect(screen.queryByText('68.0%')).not.toBeInTheDocument()
    expect(screen.queryByText('14,280 TND')).not.toBeInTheDocument()
    expect(screen.queryByText('16,993 TND')).not.toBeInTheDocument()
    expect(screen.queryByText('inventory:products.costWac')).not.toBeInTheDocument()
  })
})
