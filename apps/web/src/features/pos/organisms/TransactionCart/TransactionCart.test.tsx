import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { TransactionCart } from './TransactionCart'
import type { CartItem } from '../../molecules'

describe('TransactionCart', () => {
  const mockItems: CartItem[] = [
    {
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
    },
    {
      id: '2',
      product: {
        id: 'prod-2',
        name: 'Air Filter',
        sku: 'AF-5678',
        price: '12.000',
      },
      quantity: 1,
      unit_price: '12.000',
      line_total: '12.000',
      tax_amount: '2.280',
    },
  ]

  it('renders all cart items', () => {
    const { getByText } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
      />
    )

    expect(getByText('Oil Filter')).toBeInTheDocument()
    expect(getByText('Air Filter')).toBeInTheDocument()
  })

  it('displays empty cart message when no items', () => {
    const { getByText } = render(
      <TransactionCart
        items={[]}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
      />
    )

    expect(getByText(/cart is empty/i)).toBeInTheDocument()
  })

  // NOTE: Totals display tests removed - functionality moved to PaymentPanel
  // See PaymentPanel.test.tsx for comprehensive totals calculation tests

  it('displays item count', () => {
    const { getByText } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
      />
    )

    // 2 + 1 = 3 items
    expect(getByText(/3 items/i)).toBeInTheDocument()
  })

  it('calls onUpdateQuantity when quantity is changed', () => {
    const onUpdateQuantity = vi.fn()
    const { getAllByRole } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={onUpdateQuantity}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
      />
    )

    const incrementButtons = getAllByRole('button', { name: /increment/i })
    fireEvent.click(incrementButtons[0])

    expect(onUpdateQuantity).toHaveBeenCalledWith('prod-1', 3)
  })

  it('calls onRemoveItem when item is removed', () => {
    const onRemoveItem = vi.fn()
    const { getAllByRole } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={onRemoveItem}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
      />
    )

    const removeButtons = getAllByRole('button', { name: /remove/i })
    fireEvent.click(removeButtons[0])

    expect(onRemoveItem).toHaveBeenCalledWith('prod-1')
  })

  // NOTE: Button interaction tests removed - functionality moved to PaymentPanel
  // See PaymentPanel.test.tsx for comprehensive button tests (Quick Checkout, Advanced Payments, Calculator)

  it('displays selected customer when provided', () => {
    const { getByText } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        selectedCustomer={{
          id: 'cust-1',
          name: 'John Doe',
          phone: '+216 12 345 678',
        }}
      />
    )

    expect(getByText('John Doe')).toBeInTheDocument()
    expect(getByText(/\+216 12 345 678/)).toBeInTheDocument()
  })

  it('displays walk-in customer indicator when no customer selected', () => {
    const { getByText } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        selectedCustomer={null}
      />
    )

    expect(getByText(/walk-in/i)).toBeInTheDocument()
  })

  it('calls onChangeCustomer when change customer button clicked', () => {
    const onChangeCustomer = vi.fn()
    const { getByRole } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        selectedCustomer={null}
        onChangeCustomer={onChangeCustomer}
      />
    )

    const changeButton = getByRole('button', { name: /change customer/i })
    fireEvent.click(changeButton)

    expect(onChangeCustomer).toHaveBeenCalled()
  })

  it('applies touch-optimized styles', () => {
    const { container } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        touchOptimized={true}
      />
    )

    const cart = container.firstChild as HTMLElement
    expect(cart.className).toContain('p-6')
  })

  it('displays clear cart button', () => {
    const { getByRole } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onClearCart={vi.fn()}
      />
    )

    expect(getByRole('button', { name: /clear cart/i })).toBeInTheDocument()
  })

  it('calls onClearCart when clear button clicked', () => {
    const onClearCart = vi.fn()
    const { getByRole } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onClearCart={onClearCart}
      />
    )

    const clearButton = getByRole('button', { name: /clear cart/i })
    fireEvent.click(clearButton)

    expect(onClearCart).toHaveBeenCalled()
  })

  it('applies custom className', () => {
    const { container } = render(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        className="custom-class"
      />
    )

    expect(container.firstChild).toHaveClass('custom-class')
  })

  it('handles items without tax amounts', () => {
    const itemsWithoutTax = mockItems.map((item) => ({
      ...item,
      tax_amount: undefined,
    }))

    const { getByText } = render(
      <TransactionCart
        items={itemsWithoutTax}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
      />
    )

    // Should show 0.000 for tax
    expect(getByText(/0\.000/)).toBeInTheDocument()
  })
})
