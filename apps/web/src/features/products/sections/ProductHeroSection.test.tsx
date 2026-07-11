import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { LookupState, SuggestedProduct } from '../productLookupTypes'
import { makeProductSectionViewData } from './__fixtures__/productSectionProduct'
import { ProductHeroSection } from './ProductHeroSection'

interface LookupOptionsCapture {
  onProductData: (data: SuggestedProduct) => void
  onLookupStateChange: (state: LookupState) => void
  onScan: (value: string) => void
}

const lookupCapture = vi.hoisted(() => ({ current: null as LookupOptionsCapture | null }))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/features/inventory/hooks/useCatalogBarcodeLookup', () => ({
  useCatalogBarcodeLookup: (options: LookupOptionsCapture) => {
    lookupCapture.current = options
    return { isSearching: false }
  },
}))

vi.mock('@/features/products/components', () => ({
  CreateModeImageBuffer: () => <div data-testid="hero-create-buffer" />,
  ProductImageUpload: ({ productId }: { productId: string }) => (
    <div data-testid="hero-image-upload" data-product-id={productId} />
  ),
}))

function makeEditAdapter(productId: string | null = null) {
  const onBarcodeChange = vi.fn()
  const onNameChange = vi.fn()
  const onProductData = vi.fn()
  const onLookupStateChange = vi.fn()
  const onManualRefresh = vi.fn()

  return {
    adapter: {
      mode: 'edit' as const,
      name: 'Brake Pad',
      barcode: '6194000123456',
      productId,
      primaryImageUrl: null,
      hero: {
        enrichmentState: 'ready-for-review' as const,
        chips: [{ id: 'brand', label: 'Bosch', enriched: true }],
        onBarcodeChange,
        onNameChange,
        onProductData,
        onLookupStateChange,
        onManualRefresh,
      },
      media: {
        bufferedImages: [],
        onBufferedImagesChange: vi.fn(),
      },
    },
    callbacks: {
      onBarcodeChange,
      onNameChange,
      onProductData,
      onLookupStateChange,
      onManualRefresh,
    },
  }
}

describe('ProductHeroSection', () => {
  it('renders a pure read-only identity/enrichment/image hero with no commercial or stock facts', () => {
    const { product } = makeProductSectionViewData()
    render(
      <ProductHeroSection
        adapter={{
          mode: 'view',
          product: {
            ...product,
            primary_image_url: '/media/product/serve?signature=abc',
            brand: {
              id: 'brand-1',
              name: 'Bosch',
              slug: 'bosch',
              country_of_origin: 'DE',
              website_url: null,
              is_active: true,
            },
            brand_source: 'enriched',
          },
        }}
      />,
    )

    expect(screen.getByRole('img', { name: 'Brake Pad' })).toHaveAttribute(
      'src',
      '/media/product/serve?signature=abc&variant=md',
    )
    expect(screen.getByRole('heading', { name: 'Brake Pad' })).toBeInTheDocument()
    expect(screen.getByText('Bosch ✦')).toBeInTheDocument()
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(screen.queryByText('50.000')).not.toBeInTheDocument()
    expect(screen.queryByText('12.0000')).not.toBeInTheDocument()
  })

  it('preserves edit lookup, scanner, field, refresh, enrichment, and create-buffer flows', async () => {
    const user = userEvent.setup()
    const { adapter, callbacks } = makeEditAdapter()
    render(<ProductHeroSection adapter={adapter} />)

    const nameInput = screen.getByLabelText('editor.hero.namePlaceholder')
    const barcodeInput = screen.getByLabelText('editor.hero.barcodePlaceholder')
    fireEvent.change(nameInput, { target: { value: 'Brake Pad Ceramic' } })
    fireEvent.change(barcodeInput, { target: { value: '12345678' } })
    expect(callbacks.onNameChange).toHaveBeenCalledWith('Brake Pad Ceramic')
    expect(callbacks.onBarcodeChange).toHaveBeenCalledWith('12345678')

    expect(lookupCapture.current?.onProductData).toBe(callbacks.onProductData)
    expect(lookupCapture.current?.onLookupStateChange).toBe(callbacks.onLookupStateChange)
    lookupCapture.current?.onScan('87654321')
    expect(callbacks.onBarcodeChange).toHaveBeenCalledWith('87654321')

    await user.click(screen.getByRole('button', { name: 'editor.hero.refreshLabel' }))
    expect(callbacks.onManualRefresh).toHaveBeenCalledTimes(1)
    expect(screen.getByText('catalog:editor.hero.statusReview')).toBeInTheDocument()
    expect(screen.getByText('Bosch ✦')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'catalog:editor.hero.addPhoto' }))
    expect(screen.getByTestId('hero-create-buffer')).toBeInTheDocument()
    expect(screen.queryByText('50.000')).not.toBeInTheDocument()
  })

  it('preserves the persisted image-upload toggle', async () => {
    const user = userEvent.setup()
    const { adapter } = makeEditAdapter('product-1')
    render(<ProductHeroSection adapter={adapter} />)

    await user.click(screen.getByRole('button', { name: 'catalog:editor.hero.addPhoto' }))
    expect(screen.getByTestId('hero-image-upload')).toHaveAttribute('data-product-id', 'product-1')
  })
})
