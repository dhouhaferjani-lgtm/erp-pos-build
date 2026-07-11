import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { ProductImageSection } from './ProductImageSection'

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({
    isLoading: false,
    data: [{ id: 'image-1', sort_order: 0 }],
  }),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('./ProductImageUpload', () => ({
  ProductImageUpload: () => <div data-testid="image-upload" />,
}))

vi.mock('./ProductImageGallery', () => ({
  ProductImageGallery: ({ readOnly }: { readOnly: boolean }) => (
    <div data-testid="image-gallery" data-read-only={String(readOnly)} />
  ),
}))

describe('ProductImageSection', () => {
  it('renders the queried gallery read-only without management controls or nested header', () => {
    render(<ProductImageSection productId="product-1" readOnly embedded />)

    expect(screen.getByTestId('image-gallery')).toHaveAttribute('data-read-only', 'true')
    expect(screen.queryByTestId('image-upload')).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'sections.images' })).not.toBeInTheDocument()
  })

  it('keeps upload and gallery management enabled for persisted edit mode', () => {
    render(<ProductImageSection productId="product-1" embedded />)

    expect(screen.getByTestId('image-upload')).toBeInTheDocument()
    expect(screen.getByTestId('image-gallery')).toHaveAttribute('data-read-only', 'false')
  })
})
