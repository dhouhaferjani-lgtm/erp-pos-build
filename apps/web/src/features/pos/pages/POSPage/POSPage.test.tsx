import { describe, it, expect, vi } from 'vitest'
import { fireEvent, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { POSPage } from './POSPage'
import type { Product } from '../../molecules'

// Mock react-router-dom
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => vi.fn(),
  }
})

// Mock useCurrency hook
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    locale: 'fr-FR',
    decimals: 2,
    symbol: '\u20ac',
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

function renderWithClient(ui: React.ReactElement) {
  return renderWithProviders(ui, { productConfig: { product: 'izipos' } })
}

describe('POSPage', () => {
  const mockProducts: Product[] = [
    {
      id: '1',
      name: 'Oil Filter',
      sku: 'OF-1234',
      sale_price: '15.50',
      stock_quantity: 50,
      category: 'Filters',
    },
    {
      id: '2',
      name: 'Air Filter',
      sku: 'AF-5678',
      sale_price: '12.00',
      stock_quantity: 30,
      category: 'Filters',
    },
  ]

  it('renders ProductGrid and TransactionCart', () => {
    const { getByText } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    expect(getByText('Oil Filter')).toBeInTheDocument()
    // TransactionCart renders cart title via t('pos:cart.title') = "Cart"
    expect(getByText('Cart')).toBeInTheDocument()
  })

  it('adds product to cart when product card is clicked', () => {
    const { getByText, getAllByText } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Click on product
    fireEvent.click(getByText('Oil Filter'))

    // Should show in cart (one in grid, one in cart)
    const oilFilterInCart = getAllByText('Oil Filter')
    expect(oilFilterInCart.length).toBeGreaterThan(1)
  })

  it('updates cart item quantity when increment clicked', () => {
    const { getByText, getAllByRole } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product to cart
    fireEvent.click(getByText('Oil Filter'))

    // Increment quantity (CartLineItem uses aria-label="Increment quantity")
    const incrementButtons = getAllByRole('button', { name: /increment/i })
    fireEvent.click(incrementButtons[0])

    // Promoted UoM unit-precision lane (Phase 1.2.18): missing metadata uses four-decimal storage precision.
    expect(getByText('2.0000')).toBeInTheDocument()
  })

  it('removes item from cart when remove button clicked', async () => {
    const { getByText, getAllByRole, getAllByText } = renderWithClient(
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

    // Remove it (CartLineItem uses aria-label="Remove item")
    const removeButtons = getAllByRole('button', { name: /remove/i })
    fireEvent.click(removeButtons[0])

    // Should only show in grid now (1 instance)
    await waitFor(() => {
      expect(getAllByText('Oil Filter').length).toBe(1)
    })
  })

  it('calculates cart totals correctly', () => {
    const { getByText, getAllByText } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product (sale_price = 15.50, with 2 decimal currency => line_total = "15.50")
    fireEvent.click(getByText('Oil Filter'))

    // Price appears in multiple places (product card, cart line item, payment panel totals)
    const priceElements = getAllByText(/15\.50/)
    expect(priceElements.length).toBeGreaterThan(0)
  })

  it('calls onQuickCheckout when cash payment button clicked', () => {
    const onQuickCheckout = vi.fn()
    const { getByText, getByRole } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={onQuickCheckout}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product
    fireEvent.click(getByText('Oil Filter'))

    // Click Cash Payment (was "quick checkout" - now uses t('pos:payment.cashPayment') = "Cash Payment")
    const checkoutButton = getByRole('button', { name: /cash payment/i })
    fireEvent.click(checkoutButton)

    expect(onQuickCheckout).toHaveBeenCalled()
  })

  it('calls onAdvancedPayments when split/card payment button clicked', () => {
    const onAdvancedPayments = vi.fn()
    const { getByText, getByRole } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={onAdvancedPayments}
        onProductInfo={vi.fn()}
      />
    )

    // Add product
    fireEvent.click(getByText('Oil Filter'))

    // Click Split / Card Payment (was "advanced" - now uses t('pos:payment.splitCardPayment'))
    const advancedButton = getByRole('button', { name: /split/i })
    fireEvent.click(advancedButton)

    expect(onAdvancedPayments).toHaveBeenCalled()
  })

  it('shows calculator when calculator button clicked', () => {
    const { getByText, getByRole } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add a product so the PaymentPanel renders (it only shows when cart is non-empty)
    fireEvent.click(getByText('Oil Filter'))

    // Calculator button has aria-label from t('common:pos.calculator') = "Calculator"
    const calcButton = getByRole('button', { name: /calculator/i })
    fireEvent.click(calcButton)

    // Calculator modal renders title t('pos:calculator.title') = "Calculator"
    // There will be multiple "Calculator" texts, but the modal title should be present
    expect(getByText('Calculator')).toBeInTheDocument()
  })

  it('highlights products that are in cart', () => {
    const { getByText, getAllByText } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product
    fireEvent.click(getByText('Oil Filter'))

    // ProductCard shows hardcoded "Added" when isInCart
    expect(getAllByText('Added')[0]).toBeInTheDocument()
  })

  it('calls onProductInfo when info button clicked', () => {
    const onProductInfo = vi.fn()
    const { getAllByRole } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={onProductInfo}
      />
    )

    // ProductCard uses aria-label="Product info"
    const infoButtons = getAllByRole('button', { name: /info/i })
    fireEvent.click(infoButtons[0])

    expect(onProductInfo).toHaveBeenCalledWith(mockProducts[0])
  })

  it('displays selected customer in cart', () => {
    const { getByText } = renderWithClient(
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
    const { getByRole } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
        selectedCustomer={null}
        onChangeCustomer={onChangeCustomer}
      />
    )

    // TransactionCart uses t('pos:cart.change') = "Change" for the button
    const changeButton = getByRole('button', { name: /change/i })
    fireEvent.click(changeButton)

    expect(onChangeCustomer).toHaveBeenCalled()
  })

  it('clears cart when clear cart button clicked', async () => {
    const { getByText, getByRole } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // Add product
    fireEvent.click(getByText('Oil Filter'))

    // Clear cart (t('pos:cart.clear') = "Clear")
    const clearButton = getByRole('button', { name: /clear/i })
    fireEvent.click(clearButton)

    // Should show empty cart message t('pos:cart.empty') = "Cart is empty"
    await waitFor(() => {
      expect(getByText(/cart is empty/i)).toBeInTheDocument()
    })
  })

  it('uses flex layout on desktop', () => {
    const { container } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // POSPage uses flex layout (not grid-cols), with flex-[3] and flex-[2] children
    const flexContainer = container.querySelector('.flex')
    expect(flexContainer).toBeInTheDocument()
  })

  it('applies touch-optimized styles when touchOptimized is true', () => {
    const { container } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
        touchOptimized={true}
      />
    )

    // POSPage wraps in POSLayout (fixed inset-0), the inner div gets p-6 when touchOptimized
    const touchDiv = container.querySelector('.p-6')
    expect(touchDiv).toBeInTheDocument()
  })

  it('shows loading state when isLoading is true', () => {
    const { getByText } = renderWithClient(
      <POSPage
        products={[]}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
        isLoading={true}
      />
    )

    // t('common:loading') = "Loading..."
    expect(getByText(/loading/i)).toBeInTheDocument()
  })

  it('does not show payment buttons when cart is empty', () => {
    const { queryByRole } = renderWithClient(
      <POSPage
        products={mockProducts}
        onQuickCheckout={vi.fn()}
        onAdvancedPayments={vi.fn()}
        onProductInfo={vi.fn()}
      />
    )

    // PaymentPanel only renders when cart is non-empty
    const checkoutButton = queryByRole('button', { name: /cash payment/i })
    expect(checkoutButton).not.toBeInTheDocument()
  })

  it('passes cart items to checkout handler', () => {
    const onQuickCheckout = vi.fn()
    const { getByText, getByRole } = renderWithClient(
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
    const checkoutButton = getByRole('button', { name: /cash payment/i })
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
