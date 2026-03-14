import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { ProductGrid } from './ProductGrid'
import type { Product } from '../../molecules'

describe('ProductGrid', () => {
  const mockProducts: Product[] = [
    {
      id: '1',
      name: 'Oil Filter',
      sku: 'OF-1234',
      sale_price: '15.500',
      stock_quantity: 50,
      category: 'Filters',
    },
    {
      id: '2',
      name: 'Air Filter',
      sku: 'AF-5678',
      sale_price: '12.000',
      stock_quantity: 30,
      category: 'Filters',
    },
    {
      id: '3',
      name: 'Engine Oil',
      sku: 'EO-9012',
      sale_price: '25.000',
      stock_quantity: 100,
      category: 'Lubricants',
    },
  ]

  it('renders all products when no filter is applied', () => {
    const { getByText } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
      />
    )

    expect(getByText('Oil Filter')).toBeInTheDocument()
    expect(getByText('Air Filter')).toBeInTheDocument()
    expect(getByText('Engine Oil')).toBeInTheDocument()
  })

  it('filters products by category', () => {
    const { getByText, queryByText, getByRole } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        showCategoryFilter={true}
      />
    )

    // Click Filters category
    const filtersButton = getByRole('button', { name: /filters/i })
    fireEvent.click(filtersButton)

    expect(getByText('Oil Filter')).toBeInTheDocument()
    expect(getByText('Air Filter')).toBeInTheDocument()
    expect(queryByText('Engine Oil')).not.toBeInTheDocument()
  })

  it('filters products by search query', () => {
    const { getByPlaceholderText, getByText, queryByText } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        showSearch={true}
      />
    )

    const searchInput = getByPlaceholderText(/search/i)
    fireEvent.change(searchInput, { target: { value: 'engine' } })

    expect(getByText('Engine Oil')).toBeInTheDocument()
    expect(queryByText('Oil Filter')).not.toBeInTheDocument()
    expect(queryByText('Air Filter')).not.toBeInTheDocument()
  })

  it('searches by SKU', () => {
    const { getByPlaceholderText, getByText, queryByText } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        showSearch={true}
      />
    )

    const searchInput = getByPlaceholderText(/search/i)
    fireEvent.change(searchInput, { target: { value: 'AF-5678' } })

    expect(getByText('Air Filter')).toBeInTheDocument()
    expect(queryByText('Oil Filter')).not.toBeInTheDocument()
  })

  it('shows empty state when no products match filter', () => {
    const { getByPlaceholderText, getByText } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        showSearch={true}
      />
    )

    const searchInput = getByPlaceholderText(/search/i)
    fireEvent.change(searchInput, { target: { value: 'nonexistent' } })

    expect(getByText(/no products found/i)).toBeInTheDocument()
  })

  it('shows empty state when products array is empty', () => {
    const { getByText } = render(
      <ProductGrid
        products={[]}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
      />
    )

    expect(getByText(/no products/i)).toBeInTheDocument()
  })

  it('shows loading state', () => {
    const { getByText } = render(
      <ProductGrid
        products={[]}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        isLoading={true}
      />
    )

    expect(getByText(/loading/i)).toBeInTheDocument()
  })

  it('calls onAddToCart when product card is clicked', () => {
    const onAddToCart = vi.fn()
    const { getByText } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={onAddToCart}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
      />
    )

    fireEvent.click(getByText('Oil Filter'))
    expect(onAddToCart).toHaveBeenCalledWith(mockProducts[0])
  })

  it('calls onShowProductInfo when info button is clicked', () => {
    const onShowProductInfo = vi.fn()
    const { getAllByRole } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={onShowProductInfo}
        cartProductIds={[]}
      />
    )

    const infoButtons = getAllByRole('button', { name: /info/i })
    fireEvent.click(infoButtons[0])
    expect(onShowProductInfo).toHaveBeenCalledWith(mockProducts[0])
  })

  it('highlights products that are in cart', () => {
    const { getAllByText } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={['1', '2']}
      />
    )

    // ProductCard shows "Added" indicator for items in cart
    const addedIndicators = getAllByText('Added')
    expect(addedIndicators.length).toBe(2)
  })

  it('renders in grid layout', () => {
    const { container } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
      />
    )

    const grid = container.querySelector('[class*="grid"]')
    expect(grid).toBeInTheDocument()
  })

  it('applies touch-optimized layout', () => {
    const { container } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        touchOptimized={true}
      />
    )

    // Should have grid layout with touch-optimized spacing
    const grid = container.querySelector('.grid')
    expect(grid).toBeInTheDocument()
    expect(grid?.className).toContain('gap-')
  })

  it('extracts and displays all unique categories', () => {
    const { getByRole } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        showCategoryFilter={true}
      />
    )

    expect(getByRole('button', { name: /all/i })).toBeInTheDocument()
    expect(getByRole('button', { name: /^filters$/i })).toBeInTheDocument()
    expect(getByRole('button', { name: /lubricants/i })).toBeInTheDocument()
  })

  it('resets filter when "All" category is selected', () => {
    const { getByRole, getByText, queryByText } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        showCategoryFilter={true}
      />
    )

    // Select Filters category
    const filtersButton = getByRole('button', { name: /^filters$/i })
    fireEvent.click(filtersButton)

    expect(queryByText('Engine Oil')).not.toBeInTheDocument()

    // Select All
    const allButton = getByRole('button', { name: /all/i })
    fireEvent.click(allButton)

    expect(getByText('Engine Oil')).toBeInTheDocument()
  })

  it('clears search when clear button is clicked', () => {
    const { getByPlaceholderText, getByRole, queryByText, getByText } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        showSearch={true}
      />
    )

    const searchInput = getByPlaceholderText(/search/i) as HTMLInputElement
    fireEvent.change(searchInput, { target: { value: 'engine' } })

    expect(queryByText('Oil Filter')).not.toBeInTheDocument()

    const clearButton = getByRole('button', { name: /clear/i })
    fireEvent.click(clearButton)

    expect(searchInput.value).toBe('')
    expect(getByText('Oil Filter')).toBeInTheDocument()
  })

  it('shows product count', () => {
    const { getByText } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        showProductCount={true}
      />
    )

    expect(getByText(/3 products/i)).toBeInTheDocument()
  })

  it('updates product count after filtering', () => {
    const { getByText, getByRole } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        showCategoryFilter={true}
        showProductCount={true}
      />
    )

    const filtersButton = getByRole('button', { name: /^filters$/i })
    fireEvent.click(filtersButton)

    expect(getByText(/2 products/i)).toBeInTheDocument()
  })

  it('applies custom className', () => {
    const { container } = render(
      <ProductGrid
        products={mockProducts}
        onAddToCart={vi.fn()}
        onShowProductInfo={vi.fn()}
        cartProductIds={[]}
        className="custom-class"
      />
    )

    expect(container.firstChild).toHaveClass('custom-class')
  })
})
