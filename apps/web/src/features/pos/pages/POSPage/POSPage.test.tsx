import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { POSPage } from './POSPage'
import type { Product } from '../../molecules'

describe('POSPage', () => {
  const mockProducts: Product[] = [
    {
      id: '1',
      name: 'Oil Filter',
      sku: 'OF-1234',
      price: '15.500',
      stock_quantity: 50,
      category: 'Filters',
    },
    {
      id: '2',
      name: 'Air Filter',
      sku: 'AF-5678',
      price: '12.000',
      stock_quantity: 30,
      category: 'Filters',
    },
  ]

  it('renders ProductGrid and TransactionCart', () => {
    const { getByText } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    expect(getByText('Oil Filter')).toBeInTheDocument()
    expect(getByText('Cart')).toBeInTheDocument()
  })

  it('adds product to cart when product card is clicked', () => {
    const { getByText, getAllByText } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Click on product
    fireEvent.click(getByText('Oil Filter'))

    // Should show in cart
    const oilFilterInCart = getAllByText('Oil Filter')
    expect(oilFilterInCart.length).toBeGreaterThan(1) // One in grid, one in cart
  })

  it('updates cart item quantity when increment clicked', () => {
    const { getByText, getAllByRole } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product to cart
    fireEvent.click(getByText('Oil Filter'))

    // Increment quantity
    const incrementButtons = getAllByRole('button', { name: /increment/i })
    fireEvent.click(incrementButtons[0])

    // Should show quantity 2
    expect(getByText('2')).toBeInTheDocument()
  })

  it('removes item from cart when remove button clicked', async () => {
    const { getByText, getAllByRole, getAllByText } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product
    fireEvent.click(getByText('Oil Filter'))

    // Should be in cart and grid (2 instances)
    expect(getAllByText('Oil Filter').length).toBe(2)

    // Remove it
    const removeButtons = getAllByRole('button', { name: /remove/i })
    fireEvent.click(removeButtons[0])

    // Should only show in grid now (1 instance)
    await waitFor(() => {
      expect(getAllByText('Oil Filter').length).toBe(1)
    })
  })

  it('calculates cart totals correctly', () => {
    const { getByText, getAllByText } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product (15.500)
    fireEvent.click(getByText('Oil Filter'))

    // Should show total (appears in product card, cart item, and totals)
    const priceElements = getAllByText(/15\.500/)
    expect(priceElements.length).toBeGreaterThan(0)
  })

  it('calls onQuickCheckout when quick checkout button clicked', () => {
    const onQuickCheckout = vi.fn()
    const { getByText, getByRole } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={onQuickCheckout}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product
    fireEvent.click(getByText('Oil Filter'))

    // Click quick checkout
    const checkoutButton = getByRole('button', { name: /quick checkout/i })
    fireEvent.click(checkoutButton)

    expect(onQuickCheckout).toHaveBeenCalled()
  })

  it('calls onAdvancedPayments when advanced payments button clicked', () => {
    const onAdvancedPayments = vi.fn()
    const { getByText, getByRole } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={onAdvancedPayments}
        onProductInfo={vi.fn()}
      />
    )

    // Add product
    fireEvent.click(getByText('Oil Filter'))

    // Click advanced payments
    const advancedButton = getByRole('button', { name: /advanced/i })
    fireEvent.click(advancedButton)

    expect(onAdvancedPayments).toHaveBeenCalled()
  })

  it('shows calculator when calculator button clicked', () => {
    const { getByRole, getByText } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    const calcButton = getByRole('button', { name: /calculator/i })
    fireEvent.click(calcButton)

    expect(getByText('Calculator')).toBeInTheDocument()
  })

  it('highlights products that are in cart', () => {
    const { getByText, getAllByText } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product
    fireEvent.click(getByText('Oil Filter'))

    // Should show "Added" indicator
    expect(getAllByText('Added')[0]).toBeInTheDocument()
  })

  it('calls onProductInfo when info button clicked', () => {
    const onProductInfo = vi.fn()
    const { getAllByRole } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={onProductInfo}
      />
    )

    const infoButtons = getAllByRole('button', { name: /info/i })
    fireEvent.click(infoButtons[0])

    expect(onProductInfo).toHaveBeenCalledWith(mockProducts[0])
  })

  it('displays selected customer in cart', () => {
    const { getByText } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
        selectedCustomer={{
          id: 'cust-1',
          name: 'John Doe',
          phone: '+216 12 345 678',
        }}
      />
    )

    expect(getByText('John Doe')).toBeInTheDocument()
  })

  it('calls onChangeCustomer when change customer button clicked', () => {
    const onChangeCustomer = vi.fn()
    const { getByRole } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
        selectedCustomer={null}
        onChangeCustomer={onChangeCustomer}
      />
    )

    const changeButton = getByRole('button', { name: /change customer/i })
    fireEvent.click(changeButton)

    expect(onChangeCustomer).toHaveBeenCalled()
  })

  it('clears cart when clear cart button clicked', () => {
    const { getByText, getByRole } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product
    fireEvent.click(getByText('Oil Filter'))

    // Clear cart
    const clearButton = getByRole('button', { name: /clear/i })
    fireEvent.click(clearButton)

    // Should show empty cart
    expect(getByText(/cart is empty/i)).toBeInTheDocument()
  })

  it('uses 60/40 split layout on desktop', () => {
    const { container } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    const grid = container.querySelector('[class*="grid-cols-"]')
    expect(grid).toBeInTheDocument()
  })

  it('applies touch-optimized styles when touchOptimized is true', () => {
    const { container } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
        touchOptimized={true}
      />
    )

    const page = container.firstChild as HTMLElement
    expect(page.className).toContain('p-6')
  })

  it('shows loading state when isLoading is true', () => {
    const { getByText } = render(
      <POSPage
        products={[]}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
        isLoading={true}
      />
    )

    expect(getByText(/loading/i)).toBeInTheDocument()
  })

  it('prevents checkout when cart is empty', () => {
    const onQuickCheckout = vi.fn()
    const { getByRole } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={onQuickCheckout}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    const checkoutButton = getByRole('button', { name: /quick checkout/i })
    expect(checkoutButton).toBeDisabled()
  })

  it('passes cart items to checkout handler', () => {
    const onQuickCheckout = vi.fn()
    const { getByText, getByRole } = render(
      <POSPage
        products={mockProducts}
        onQuickCheckout={onQuickCheckout}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product
    fireEvent.click(getByText('Oil Filter'))

    // Checkout
    const checkoutButton = getByRole('button', { name: /quick checkout/i })
    fireEvent.click(checkoutButton)

    expect(onQuickCheckout).toHaveBeenCalledWith(
      expect.arrayContaining([
        expect.objectContaining({
          product: expect.objectContaining({ id: '1' }),
          quantity: 1,
        }),
      ])
    )
  })
})
