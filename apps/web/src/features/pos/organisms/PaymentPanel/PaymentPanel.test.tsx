import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { userEvent } from '@testing-library/user-event'
import { PaymentPanel } from './PaymentPanel'
import { type CartItem } from '../../molecules'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('PaymentPanel', () => {
  const mockOnQuickCheckout = vi.fn()
  const mockOnAdvancedPayments = vi.fn()
  const mockOnOpenCalculator = vi.fn()

  const sampleCartItems: CartItem[] = [
    {
      id: '1',
      product: {
        id: 'p1',
        name: 'Product A',
        price: '25.000',
        stock_quantity: 10,
      },
      quantity: 2,
      unit_price: '25.000',
      line_total: '50.000',
      tax_amount: '9.500', // 19% VAT
    },
    {
      id: '2',
      product: {
        id: 'p2',
        name: 'Product B',
        price: '50.000',
        stock_quantity: 5,
      },
      quantity: 1,
      unit_price: '50.000',
      line_total: '50.000',
      tax_amount: '9.500', // 19% VAT
    },
  ]

  beforeEach(() => {
    mockOnQuickCheckout.mockClear()
    mockOnAdvancedPayments.mockClear()
    mockOnOpenCalculator.mockClear()
  })

  describe('Rendering', () => {
    it('should render fixed panel with correct positioning on desktop', () => {
      const { container } = render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
          isNarrowScreen={false}
        />
      )

      const panel = container.firstChild as HTMLElement
      expect(panel).toHaveClass('fixed', 'z-40', 'bottom-4', 'end-4', 'w-96')
    })

    it('should render full-width panel at bottom on narrow screens', () => {
      const { container } = render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
          isNarrowScreen={true}
        />
      )

      const panel = container.firstChild as HTMLElement
      expect(panel).toHaveClass('fixed', 'z-40', 'bottom-0', 'left-0', 'right-0', 'w-full')
    })

    it('should have floating visual style with shadow and border', () => {
      const { container } = render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const panelContent = container.querySelector('.bg-white')
      expect(panelContent).toHaveClass('border-2', 'border-gray-300', 'shadow-2xl', 'rounded-lg')
    })

    it('should render totals section when cart has items', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      // Using i18n keys (these will fail until component is fixed)
      expect(screen.getByText('common:pos.subtotal')).toBeInTheDocument()
      expect(screen.getByText('common:pos.tax')).toBeInTheDocument()
      expect(screen.getByText('common:pos.total')).toBeInTheDocument()
    })

    it('should not render totals section when cart is empty', () => {
      render(
        <PaymentPanel
          items={[]}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      // Totals should not be rendered for empty cart
      expect(screen.queryByText('common:pos.subtotal')).not.toBeInTheDocument()
    })

    it('should render Quick Checkout button', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const quickCheckoutButton = screen.getByRole('button', { name: /common:pos.quickCheckout/i })
      expect(quickCheckoutButton).toBeInTheDocument()
    })

    it('should render Advanced Payments button', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const advancedButton = screen.getByRole('button', { name: /common:pos.advancedPayments/i })
      expect(advancedButton).toBeInTheDocument()
    })

    it('should render Calculator button when onOpenCalculator is provided', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
          onOpenCalculator={mockOnOpenCalculator}
        />
      )

      const calculatorButton = screen.getByRole('button', { name: /calculator/i })
      expect(calculatorButton).toBeInTheDocument()
    })

    it('should not render Calculator button when onOpenCalculator is not provided', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const calculatorButton = screen.queryByRole('button', { name: /calculator/i })
      expect(calculatorButton).not.toBeInTheDocument()
    })
  })

  describe('Total Calculations', () => {
    it('should calculate and display correct subtotal', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      // Subtotal = 50.000 + 50.000 = 100.000
      expect(screen.getByText('100.000')).toBeInTheDocument()
    })

    it('should calculate and display correct tax total', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      // Tax = 9.500 + 9.500 = 19.000
      expect(screen.getByText('19.000')).toBeInTheDocument()
    })

    it('should calculate and display correct grand total', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      // Total = 100.000 + 19.000 = 119.000
      expect(screen.getByText('119.000')).toBeInTheDocument()
    })

    it('should handle zero tax amounts correctly', () => {
      const itemsWithoutTax: CartItem[] = [
        {
          id: '1',
          product: { id: 'p1', name: 'Product', price: '100.000', stock_quantity: 10 },
          quantity: 1,
          unit_price: '100.000',
          line_total: '100.000',
          tax_amount: '0.000',
        },
      ]

      render(
        <PaymentPanel
          items={itemsWithoutTax}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      // Tax should be 0.000
      expect(screen.getByText('0.000')).toBeInTheDocument()
    })

    it('should recalculate totals when items change', () => {
      const { rerender } = render(
        <PaymentPanel
          items={[sampleCartItems[0]]}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      // Initial: 50.000 subtotal
      expect(screen.getByText('50.000')).toBeInTheDocument()

      // Update to all items
      rerender(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      // Updated: 100.000 subtotal
      expect(screen.getByText('100.000')).toBeInTheDocument()
    })
  })

  describe('User Interactions', () => {
    it('should call onQuickCheckout when Quick Checkout button is clicked', async () => {
      const user = userEvent.setup()

      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const quickCheckoutButton = screen.getByRole('button', { name: /common:pos.quickCheckout/i })
      await user.click(quickCheckoutButton)

      expect(mockOnQuickCheckout).toHaveBeenCalledTimes(1)
    })

    it('should call onAdvancedPayments when Advanced Payments button is clicked', async () => {
      const user = userEvent.setup()

      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const advancedButton = screen.getByRole('button', { name: /common:pos.advancedPayments/i })
      await user.click(advancedButton)

      expect(mockOnAdvancedPayments).toHaveBeenCalledTimes(1)
    })

    it('should call onOpenCalculator when Calculator button is clicked', async () => {
      const user = userEvent.setup()

      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
          onOpenCalculator={mockOnOpenCalculator}
        />
      )

      const calculatorButton = screen.getByRole('button', { name: /calculator/i })
      await user.click(calculatorButton)

      expect(mockOnOpenCalculator).toHaveBeenCalledTimes(1)
    })
  })

  describe('Button States', () => {
    it('should disable Quick Checkout button when cart is empty', () => {
      render(
        <PaymentPanel
          items={[]}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const quickCheckoutButton = screen.getByRole('button', { name: /common:pos.quickCheckout/i })
      expect(quickCheckoutButton).toBeDisabled()
    })

    it('should disable Advanced Payments button when cart is empty', () => {
      render(
        <PaymentPanel
          items={[]}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const advancedButton = screen.getByRole('button', { name: /common:pos.advancedPayments/i })
      expect(advancedButton).toBeDisabled()
    })

    it('should enable buttons when cart has items', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const quickCheckoutButton = screen.getByRole('button', { name: /common:pos.quickCheckout/i })
      const advancedButton = screen.getByRole('button', { name: /common:pos.advancedPayments/i })

      expect(quickCheckoutButton).toBeEnabled()
      expect(advancedButton).toBeEnabled()
    })

    it('should not disable Calculator button when cart is empty', () => {
      render(
        <PaymentPanel
          items={[]}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
          onOpenCalculator={mockOnOpenCalculator}
        />
      )

      // Calculator should always be available
      const calculatorButton = screen.getByRole('button', { name: /calculator/i })
      expect(calculatorButton).toBeEnabled()
    })
  })

  describe('Touch Optimization', () => {
    it('should use larger text sizes when touchOptimized is true', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
          touchOptimized={true}
        />
      )

      // Check for larger text size classes
      const subtotalLabel = screen.getByText('common:pos.subtotal')
      expect(subtotalLabel).toHaveClass('text-lg') // vs text-base
    })

    it('should use standard text sizes when touchOptimized is false', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
          touchOptimized={false}
        />
      )

      const subtotalLabel = screen.getByText('common:pos.subtotal')
      expect(subtotalLabel).toHaveClass('text-base')
    })
  })

  describe('Accessibility', () => {
    it('should have accessible button labels', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
          onOpenCalculator={mockOnOpenCalculator}
        />
      )

      // All buttons should have accessible names
      expect(screen.getByRole('button', { name: /common:pos.quickCheckout/i })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: /common:pos.advancedPayments/i })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: /calculator/i })).toBeInTheDocument()
    })

    it('should have proper ARIA attributes on disabled buttons', () => {
      render(
        <PaymentPanel
          items={[]}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const quickCheckoutButton = screen.getByRole('button', { name: /common:pos.quickCheckout/i })
      expect(quickCheckoutButton).toHaveAttribute('disabled')
    })
  })

  describe('Visual Styling', () => {
    it('should have white background for panel content', () => {
      const { container } = render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const panelContent = container.querySelector('.bg-white')
      expect(panelContent).toBeInTheDocument()
    })

    it('should have rounded corners on desktop', () => {
      const { container } = render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
          isNarrowScreen={false}
        />
      )

      const panelContent = container.querySelector('.rounded-lg')
      expect(panelContent).toBeInTheDocument()
    })

    it('should have rounded top corners only on narrow screens', () => {
      const { container } = render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
          isNarrowScreen={true}
        />
      )

      const panelContent = container.querySelector('.rounded-t-lg')
      expect(panelContent).toBeInTheDocument()
    })

    it('should have prominent styling for total amount', () => {
      render(
        <PaymentPanel
          items={sampleCartItems}
          onQuickCheckout={mockOnQuickCheckout}
          onAdvancedPayments={mockOnAdvancedPayments}
        />
      )

      const totalLabel = screen.getByText('common:pos.total')
      expect(totalLabel).toHaveClass('font-bold', 'text-gray-900')

      // Total amount should be blue and bold
      const totalAmount = screen.getByText('119.000')
      expect(totalAmount).toHaveClass('font-bold', 'text-blue-600')
    })
  })
})
