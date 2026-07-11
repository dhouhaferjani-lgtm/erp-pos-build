import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { ProductImageGallery } from './ProductImageGallery'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

function renderGallery(readOnly: boolean) {
  const queryClient = new QueryClient({ defaultOptions: { mutations: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <ProductImageGallery
        productId="product-1"
        readOnly={readOnly}
        images={[{
          id: 'image-1',
          asset_id: 'asset-1',
          is_primary: false,
          sort_order: 0,
          role: 'GALLERY',
          url: '/image-1.jpg',
          alt: 'Front view',
          caption: null,
        }]}
      />
    </QueryClientProvider>,
  )
}

describe('ProductImageGallery', () => {
  it('renders images without delete or set-primary controls in read-only mode', () => {
    renderGallery(true)

    expect(screen.getByRole('img', { name: 'Front view' })).toBeInTheDocument()
    expect(screen.queryByTitle('products:images.setPrimary')).not.toBeInTheDocument()
    expect(screen.queryByTitle('common:delete')).not.toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
