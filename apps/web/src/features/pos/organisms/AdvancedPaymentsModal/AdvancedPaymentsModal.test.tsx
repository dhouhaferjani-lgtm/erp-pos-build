import { describe, it, expect, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AdvancedPaymentsModal } from './AdvancedPaymentsModal'
import type { CartItem } from '../../molecules'

// Mock translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

const mockCartItems: CartItem[] = [
  {
    id: '1',
    product: { id: 'p1', name: 'Product 1', sku: 'SKU1', price: '50.000' },
    quantity: 2,
    unit_price: '50.000',
    line_total: '100.000',
    tax_amount: '19.000',
  },
]

describe('AdvancedPaymentsModal', () => {
  it('does not render when isOpen is false', () => {
    const { container } = render(
      <AdvancedPaymentsModal
        isOpen={false}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    expect(container.firstChild).toBeNull()
  })

  it('renders when isOpen is true', () => {
    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    expect(screen.getByText('Advanced Payments')).toBeInTheDocument()
  })

  it('displays cart summary with correct totals', () => {
    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Check subtotal
    expect(screen.getByText('100.000 TND')).toBeInTheDocument()

    // Check tax
    expect(screen.getByText('19.000 TND')).toBeInTheDocument()

    // Check total (100 + 19 = 119)
    expect(screen.getByText('119.000 TND')).toBeInTheDocument()
  })

  it('displays all payment methods', () => {
    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    expect(screen.getByText('Cash')).toBeInTheDocument()
    expect(screen.getByText('Card')).toBeInTheDocument()
    expect(screen.getByText('Check')).toBeInTheDocument()
    expect(screen.getByText('Bank Transfer')).toBeInTheDocument()
  })

  it('selects payment method when clicked', async () => {
    const user = userEvent.setup()

    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // After selection, payment input should appear
    expect(screen.getByText('Payment Distribution')).toBeInTheDocument()
  })

  it('allows entering payment amount for selected method', async () => {
    const user = userEvent.setup()

    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Select cash payment method
    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // Find the payment input
    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '119.000')

    expect(paymentInput).toHaveValue('119.000')
  })

  it('calculates remaining amount correctly', async () => {
    const user = userEvent.setup()

    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Select cash payment method
    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // Enter partial payment
    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '50')

    // Remaining should be 119 - 50 = 69
    expect(screen.getByText('69.000 TND')).toBeInTheDocument()
  })

  it('enables complete button when payment equals total', async () => {
    const user = userEvent.setup()

    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Select cash payment method
    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // Enter full payment
    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '119.000')

    const completeButton = screen.getByRole('button', { name: /complete transaction/i })
    expect(completeButton).not.toBeDisabled()
  })

  it('disables complete button when payment is insufficient', async () => {
    const user = userEvent.setup()

    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Select cash payment method
    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // Enter partial payment
    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '50')

    const completeButton = screen.getByRole('button', { name: /complete transaction/i })
    expect(completeButton).toBeDisabled()
  })

  it('shows overpayment handler when payment exceeds total', async () => {
    const user = userEvent.setup()

    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Select cash payment method
    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // Enter overpayment
    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '150')

    // Overpayment message should appear
    expect(screen.getByText('Overpayment Detected')).toBeInTheDocument()
    expect(screen.getByText(/Excess:/)).toBeInTheDocument()
  })

  it('supports split payment with multiple methods', async () => {
    const user = userEvent.setup()

    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Select cash
    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // Select card
    const cardButton = screen.getByRole('button', { name: /card/i })
    await user.click(cardButton)

    // Both payment inputs should be visible
    const inputs = screen.getAllByPlaceholderText('0.000')
    expect(inputs).toHaveLength(2)

    // Enter amounts
    await user.type(inputs[0], '50')
    await user.type(inputs[1], '69')

    // Complete button should be enabled
    const completeButton = screen.getByRole('button', { name: /complete transaction/i })
    expect(completeButton).not.toBeDisabled()
  })

  it('calls onComplete with correct payment data', async () => {
    const user = userEvent.setup()
    const onComplete = vi.fn()

    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={onComplete}
      />
    )

    // Select and enter payment
    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '119')

    // Complete transaction
    const completeButton = screen.getByRole('button', { name: /complete transaction/i })
    await user.click(completeButton)

    expect(onComplete).toHaveBeenCalledWith(
      expect.objectContaining({
        methods: expect.arrayContaining([
          expect.objectContaining({
            methodId: 'cash',
            amount: 119,
          }),
        ]),
      })
    )
  })

  it('calls onClose when close button is clicked', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()

    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={onClose}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    const closeButton = screen.getByLabelText('Close')
    await user.click(closeButton)

    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('allows using "Use Remaining" button to auto-fill payment', async () => {
    const user = userEvent.setup()

    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Select cash
    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // Click "Use Remaining"
    const useRemainingButton = screen.getByRole('button', { name: /use remaining/i })
    await user.click(useRemainingButton)

    // Input should have the full amount
    const paymentInput = screen.getByPlaceholderText('0.000')
    expect(paymentInput).toHaveValue('119.000')
  })

  it('displays cart items in mini cart', () => {
    render(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Check product name is displayed
    expect(screen.getByText('Product 1')).toBeInTheDocument()

    // Check quantity
    expect(screen.getByText('×2')).toBeInTheDocument()

    // Check line total
    expect(screen.getByText('100.000 TND')).toBeInTheDocument()
  })
})
