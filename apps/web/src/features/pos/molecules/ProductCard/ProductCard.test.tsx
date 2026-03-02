import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { ProductCard } from './ProductCard'

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    locale: 'fr-FR',
    decimals: 2,
    format: (value: string | number) => {
      const num = typeof value === 'string' ? parseFloat(value) : value
      return `${num.toFixed(2)} EUR`
    },
    toFixed: (value: number) => value.toFixed(2),
  }),
  getDecimals: (currency: string) => currency === 'TND' || currency === 'LYD' ? 3 : 2,
  getLocale: (_currency: string) => 'fr-FR',
  formatAmount: (value: string | number, currency: string) => {
    const num = typeof value === 'string' ? parseFloat(value) : value
    const decimals = currency === 'TND' || currency === 'LYD' ? 3 : 2
    return `${num.toFixed(decimals)} ${currency}`
  },
}))

describe('ProductCard', () => {
  const mockProduct = {
    id: '1',
    name: 'Oil Filter',
    sku: 'OF-1234',
    sale_price: '15.500',
    stock_quantity: 50,
    image_url: 'https://example.com/image.jpg',
    category: 'Filters',
  }

  it('renders product name', () => {
    const { getByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )
    expect(getByText('Oil Filter')).toBeInTheDocument()
  })

  it('renders product SKU', () => {
    const { getByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )
    expect(getByText(/OF-1234/)).toBeInTheDocument()
  })

  it('renders product price', () => {
    const { getByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )
    expect(getByText(/15\.500/)).toBeInTheDocument()
  })

  it('renders stock badge', () => {
    const { getByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )
    expect(getByText('In Stock')).toBeInTheDocument()
  })

  it('renders product image when URL is provided', () => {
    const { getByAltText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )
    const image = getByAltText('Oil Filter') as HTMLImageElement
    expect(image).toBeInTheDocument()
    expect(image.src).toContain('image.jpg')
  })

  it('renders placeholder when image URL is not provided', () => {
    const productWithoutImage = { ...mockProduct, image_url: undefined }
    const { container } = render(
      <ProductCard
        product={productWithoutImage}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )
    expect(container.querySelector('[data-testid="image-placeholder"]')).toBeInTheDocument()
  })

  it('calls onAddToCart when card is clicked', () => {
    const onAddToCart = vi.fn()
    const { container } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={onAddToCart}
        onShowInfo={vi.fn()}
      />
    )

    const card = container.firstChild as HTMLElement
    fireEvent.click(card)
    expect(onAddToCart).toHaveBeenCalledWith(mockProduct)
  })

  it('calls onShowInfo when info button is clicked', () => {
    const onShowInfo = vi.fn()
    const { getByRole } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={onShowInfo}
      />
    )

    const infoButton = getByRole('button', { name: /info/i })
    fireEvent.click(infoButton)
    expect(onShowInfo).toHaveBeenCalledWith(mockProduct)
  })

  it('prevents card click when info button is clicked', () => {
    const onAddToCart = vi.fn()
    const onShowInfo = vi.fn()
    const { getByRole } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={onAddToCart}
        onShowInfo={onShowInfo}
      />
    )

    const infoButton = getByRole('button', { name: /info/i })
    fireEvent.click(infoButton)

    expect(onShowInfo).toHaveBeenCalledTimes(1)
    expect(onAddToCart).not.toHaveBeenCalled()
  })

  it('shows "Added" indicator when isInCart is true', () => {
    const { getByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
        isInCart={true}
      />
    )
    expect(getByText('Added')).toBeInTheDocument()
  })

  it('does not show "Added" indicator when isInCart is false', () => {
    const { queryByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
        isInCart={false}
      />
    )
    expect(queryByText('Added')).not.toBeInTheDocument()
  })

  it('applies touch-optimized styles when touchOptimized is true', () => {
    const { container } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
        touchOptimized={true}
      />
    )
    const card = container.firstChild as HTMLElement
    expect(card.className).toContain('min-h-[120px]')
  })

  it('applies hover effect on non-touch devices', () => {
    const { container } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
        touchOptimized={false}
      />
    )
    const card = container.firstChild as HTMLElement
    expect(card.className).toContain('hover:shadow-lg')
  })

  it('disables card when product is out of stock', () => {
    const outOfStockProduct = { ...mockProduct, stock_quantity: 0 }
    const onAddToCart = vi.fn()
    const { container } = render(
      <ProductCard
        product={outOfStockProduct}
        onAddToCart={onAddToCart}
        onShowInfo={vi.fn()}
      />
    )

    const card = container.firstChild as HTMLElement
    fireEvent.click(card)
    expect(onAddToCart).not.toHaveBeenCalled()
    expect(card.className).toContain('opacity-60')
  })

  it('shows category badge', () => {
    const { getByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )
    expect(getByText('Filters')).toBeInTheDocument()
  })

  it('renders without category when not provided', () => {
    const productWithoutCategory = { ...mockProduct, category: undefined }
    const { queryByText } = render(
      <ProductCard
        product={productWithoutCategory}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )
    expect(queryByText('Filters')).not.toBeInTheDocument()
  })

  it('applies custom className', () => {
    const { container } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
        className="custom-class"
      />
    )
    const card = container.firstChild as HTMLElement
    expect(card.className).toContain('custom-class')
  })

  it('handles products with long names correctly', () => {
    const productWithLongName = {
      ...mockProduct,
      name: 'This is a very long product name that should be truncated'
    }
    const { getByText } = render(
      <ProductCard
        product={productWithLongName}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )
    expect(getByText(/This is a very long/)).toBeInTheDocument()
  })

  it('displays currency symbol with price', () => {
    const { getByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )
    expect(getByText(/EUR/)).toBeInTheDocument()
  })
})
