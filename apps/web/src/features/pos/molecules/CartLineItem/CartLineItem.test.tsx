import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { CartLineItem } from './CartLineItem'

describe('CartLineItem', () => {
  const mockItem = {
    id: '1',
    product: {
      id: 'prod-1',
      name: 'Oil Filter',
      sku: 'OF-1234',
      price: '15.500',
    },
    quantity: 2,
    unit_price: '15.500',
    line_total: '31.000',
    tax_amount: '5.890',
  }

  it('renders product name', () => {
    const { getByText } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )
    expect(getByText('Oil Filter')).toBeInTheDocument()
  })

  it('renders product SKU', () => {
    const { getByText } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )
    expect(getByText(/OF-1234/)).toBeInTheDocument()
  })

  it('displays current quantity', () => {
    const { getByText } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )
    expect(getByText('2.0000')).toBeInTheDocument()
  })

  it('uses product quantity_decimals instead of the scale-four fallback for cart quantities', () => {
    const { getByText } = render(
      <CartLineItem
        item={{
          ...mockItem,
          quantity: 1.5,
          product: { ...mockItem.product, quantity_decimals: 2 },
        }}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    expect(getByText('15.500 EUR × 1.50')).toBeInTheDocument()
    expect(getByText('1.50')).toBeInTheDocument()
  })

  it('displays unit price', () => {
    const { getByText } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )
    expect(getByText(/15\.500/)).toBeInTheDocument()
  })

  it('displays line total', () => {
    const { getByText } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )
    expect(getByText(/31\.000/)).toBeInTheDocument()
  })

  it('calls onUpdateQuantity when increment button clicked', () => {
    const onUpdateQuantity = vi.fn()
    const { getByRole } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={onUpdateQuantity}
        onRemove={vi.fn()}
      />
    )

    const incrementButton = getByRole('button', { name: /increment/i })
    fireEvent.click(incrementButton)
    expect(onUpdateQuantity).toHaveBeenCalledWith('prod-1', 3)
  })

  it('calls onUpdateQuantity when decrement button clicked', () => {
    const onUpdateQuantity = vi.fn()
    const { getByRole } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={onUpdateQuantity}
        onRemove={vi.fn()}
      />
    )

    const decrementButton = getByRole('button', { name: /decrement/i })
    fireEvent.click(decrementButton)
    expect(onUpdateQuantity).toHaveBeenCalledWith('prod-1', 1)
  })

  it('calls onRemove when quantity is 1 and decrement clicked', () => {
    const itemWithQty1 = { ...mockItem, quantity: 1 }
    const onRemove = vi.fn()
    const { getByRole } = render(
      <CartLineItem
        item={itemWithQty1}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
      />
    )

    const decrementButton = getByRole('button', { name: /decrement/i })
    fireEvent.click(decrementButton)
    expect(onRemove).toHaveBeenCalledWith('prod-1')
  })

  it('calls onRemove when remove button clicked', () => {
    const onRemove = vi.fn()
    const { getByRole } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
      />
    )

    const removeButton = getByRole('button', { name: /remove/i })
    fireEvent.click(removeButton)
    expect(onRemove).toHaveBeenCalledWith('prod-1')
  })

  it('applies touch-optimized styles when touchOptimized is true', () => {
    const { container } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
        touchOptimized={true}
      />
    )
    const lineItem = container.firstChild as HTMLElement
    expect(lineItem.className).toContain('p-4')
  })

  it('displays tax amount when showTax is true', () => {
    const { getByText } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
        showTax={true}
      />
    )
    expect(getByText(/5\.890/)).toBeInTheDocument()
  })

  it('does not display tax amount when showTax is false', () => {
    const { queryByText } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
        showTax={false}
      />
    )
    expect(queryByText(/Tax/)).not.toBeInTheDocument()
  })

  it('handles swipe left gesture on touch devices', () => {
    const onRemove = vi.fn()
    const { container } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
        touchOptimized={true}
      />
    )

    const lineItem = container.firstChild as HTMLElement
    fireEvent.touchStart(lineItem, { touches: [{ clientX: 100 }] })
    fireEvent.touchEnd(lineItem, { changedTouches: [{ clientX: 20 }] })

    // Should show delete button after swipe
    expect(onRemove).not.toHaveBeenCalled() // Only shows button, doesn't auto-delete
  })

  it('disables quantity controls when disabled prop is true', () => {
    const { getByRole } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
        disabled={true}
      />
    )

    const incrementButton = getByRole('button', { name: /increment/i })
    const decrementButton = getByRole('button', { name: /decrement/i })

    expect(incrementButton).toBeDisabled()
    expect(decrementButton).toBeDisabled()
  })

  it('applies custom className', () => {
    const { container } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
        className="custom-class"
      />
    )
    const lineItem = container.firstChild as HTMLElement
    expect(lineItem.className).toContain('custom-class')
  })

  it('handles products with long names correctly', () => {
    const itemWithLongName = {
      ...mockItem,
      product: {
        ...mockItem.product,
        name: 'This is a very long product name that should be truncated'
      }
    }
    const { getByText } = render(
      <CartLineItem
        item={itemWithLongName}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )
    expect(getByText(/This is a very long/)).toBeInTheDocument()
  })

  it('displays currency symbol', () => {
    const { getAllByText } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )
    const currencyElements = getAllByText(/EUR/)
    expect(currencyElements.length).toBeGreaterThan(0)
  })

  it('prevents negative quantities', () => {
    const onUpdateQuantity = vi.fn()
    const itemWithQty1 = { ...mockItem, quantity: 1 }
    const { getByRole } = render(
      <CartLineItem
        item={itemWithQty1}
        onUpdateQuantity={onUpdateQuantity}
        onRemove={vi.fn()}
      />
    )

    const decrementButton = getByRole('button', { name: /decrement/i })
    fireEvent.click(decrementButton)

    // Should remove, not set to 0 or negative
    expect(onUpdateQuantity).not.toHaveBeenCalledWith('prod-1', 0)
  })
})
