import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { TransactionCart } from './TransactionCart'
import type { CartItem } from '../../molecules'

function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })
}

function renderWithClient(ui: React.ReactElement) {
  const queryClient = createTestQueryClient()
  return render(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>)
}

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
    const { getByText } = renderWithClient(
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
    const { getByText } = renderWithClient(
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
    const { getByText } = renderWithClient(
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
    const { getAllByRole } = renderWithClient(
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
    const { getAllByRole } = renderWithClient(
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
    const { getByText } = renderWithClient(
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
    const { getByText } = renderWithClient(
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
    const { getByRole } = renderWithClient(
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

    const changeButton = getByRole('button', { name: /change/i })
    fireEvent.click(changeButton)

    expect(onChangeCustomer).toHaveBeenCalled()
  })

  it('applies touch-optimized styles', () => {
    const { container } = renderWithClient(
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
    const { getByRole } = renderWithClient(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onClearCart={vi.fn()}
      />
    )

    expect(getByRole('button', { name: /clear/i })).toBeInTheDocument()
  })

  it('calls onClearCart when clear button clicked', () => {
    const onClearCart = vi.fn()
    const { getByRole } = renderWithClient(
      <TransactionCart
        items={mockItems}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onClearCart={onClearCart}
      />
    )

    const clearButton = getByRole('button', { name: /clear/i })
    fireEvent.click(clearButton)

    expect(onClearCart).toHaveBeenCalled()
  })

  it('applies custom className', () => {
    const { container } = renderWithClient(
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
    const itemsWithoutTax = mockItems.map((item) => {
      const { tax_amount: _tax, ...rest } = item
      void _tax
      return rest
    })

    const { getByText } = renderWithClient(
      <TransactionCart
        items={itemsWithoutTax}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
      />
    )

    // Should show 0.00 for tax (EUR default uses 2 decimal places)
    expect(getByText(/0\.00/)).toBeInTheDocument()
  })
})
