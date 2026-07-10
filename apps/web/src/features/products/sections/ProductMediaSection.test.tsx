import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { ProductMediaSection } from './ProductMediaSection'
import { makeProductSectionViewData } from './__fixtures__/productSectionProduct'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('../components/ProductImageSection', () => ({
  ProductImageSection: ({ productId, readOnly, embedded }: {
    productId: string
    readOnly?: boolean
    embedded?: boolean
  }) => (
    <div
      data-testid="product-image-section"
      data-product-id={productId}
      data-read-only={String(readOnly ?? false)}
      data-embedded={String(embedded ?? false)}
    />
  ),
}))

vi.mock('../components/CreateModeImageBuffer', () => ({
  CreateModeImageBuffer: ({ bufferedFiles }: { bufferedFiles: File[] }) => (
    <div data-testid="create-image-buffer" data-count={String(bufferedFiles.length)} />
  ),
}))

describe('ProductMediaSection', () => {
  it('renders the persisted view gallery read-only in the shared media anchor', () => {
    const { product } = makeProductSectionViewData()
    const { container } = render(
      <ProductMediaSection adapter={{ mode: 'view', product }} />,
    )

    expect(container.querySelectorAll('#section-media')).toHaveLength(1)
    expect(screen.getByTestId('product-image-section')).toHaveAttribute('data-read-only', 'true')
    expect(screen.getByTestId('product-image-section')).toHaveAttribute('data-embedded', 'true')
    expect(screen.queryByTestId('create-image-buffer')).not.toBeInTheDocument()
  })

  it('keeps persisted edit gallery management enabled', () => {
    render(
      <ProductMediaSection
        adapter={{
          mode: 'edit',
          isEditing: true,
          productId: 'product-1',
          media: { bufferedImages: [], onBufferedImagesChange: vi.fn() },
        }}
      />,
    )

    expect(screen.getByTestId('product-image-section')).toHaveAttribute('data-read-only', 'false')
    expect(screen.getByTestId('product-image-section')).toHaveAttribute('data-embedded', 'true')
  })

  it('uses the buffered file workflow before the product exists', () => {
    const file = new File(['image'], 'product.jpg', { type: 'image/jpeg' })
    render(
      <ProductMediaSection
        adapter={{
          mode: 'edit',
          isEditing: false,
          productId: null,
          media: { bufferedImages: [file], onBufferedImagesChange: vi.fn() },
        }}
      />,
    )

    expect(screen.getByTestId('create-image-buffer')).toHaveAttribute('data-count', '1')
    expect(screen.queryByTestId('product-image-section')).not.toBeInTheDocument()
  })
})
