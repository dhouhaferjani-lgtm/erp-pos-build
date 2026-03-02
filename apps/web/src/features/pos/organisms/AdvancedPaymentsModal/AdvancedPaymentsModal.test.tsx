import { describe, it, expect, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AdvancedPaymentsModal } from './AdvancedPaymentsModal'
import type { CartItem } from '../../molecules'

// Mock translation hook — returns key as-is
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, string>) => {
      if (opts?.defaultValue) return opts.defaultValue
      if (opts?.number) return `${key.replace(/\{\{.*\}\}/, '')}${opts.number}`
      return key
    },
  }),
}))

// Mock useCurrency
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    locale: 'fr-FR',
    decimals: 2,
    symbol: '€',
    format: (value: string | number) => {
      const num = typeof value === 'string' ? parseFloat(value) : value
      return `${num.toFixed(2)} EUR`
    },
    toFixed: (value: number) => value.toFixed(2),
  }),
  getDecimals: () => 2,
  getLocale: () => 'fr-FR',
  formatAmount: (value: string | number, currency: string) => {
    const num = typeof value === 'string' ? parseFloat(value) : value
    return `${num.toFixed(2)} ${currency}`
  },
}))

// Mock useCompanySettings
vi.mock('../../hooks', () => ({
  useCompanySettings: () => ({
    settings: { auto_print_receipts: false, receipt_logo: null, receipt_footer: null },
    isLoading: false,
    error: null,
    autoPrintReceipts: false,
    receiptLogo: null,
    receiptFooter: null,
  }),
}))

// Mock ReceiptPrintButton to avoid further deps
vi.mock('../../components/ReceiptPrintButton', () => ({
  ReceiptPrintButton: () => <div data-testid="receipt-print-button" />,
}))

const mockPaymentMethods = [
  {
    id: 'cash',
    code: 'cash',
    name: 'Cash',
    is_physical: true,
    has_maturity: false,
    requires_third_party: false,
    is_push: false,
    has_deducted_fees: false,
    is_restricted: false,
    fee_type: null,
    fee_fixed: '0',
    fee_percent: '0',
    restriction_type: null,
    is_active: true,
    position: 1,
  },
  {
    id: 'card',
    code: 'card',
    name: 'Card',
    is_physical: false,
    has_maturity: false,
    requires_third_party: true,
    is_push: false,
    has_deducted_fees: false,
    is_restricted: false,
    fee_type: null,
    fee_fixed: '0',
    fee_percent: '0',
    restriction_type: null,
    is_active: true,
    position: 2,
  },
  {
    id: 'check',
    code: 'check',
    name: 'Check',
    is_physical: false,
    has_maturity: true,
    requires_third_party: false,
    is_push: false,
    has_deducted_fees: false,
    is_restricted: false,
    fee_type: null,
    fee_fixed: '0',
    fee_percent: '0',
    restriction_type: null,
    is_active: true,
    position: 3,
  },
  {
    id: 'bank_transfer',
    code: 'transfer',
    name: 'Bank Transfer',
    is_physical: false,
    has_maturity: false,
    requires_third_party: false,
    is_push: false,
    has_deducted_fees: false,
    is_restricted: false,
    fee_type: null,
    fee_fixed: '0',
    fee_percent: '0',
    restriction_type: null,
    is_active: true,
    position: 4,
  },
]

const mockPaymentRepositories = [
  {
    id: 'repo-cash',
    code: 'CR1',
    name: 'Main Register',
    type: 'cash_register' as const,
    bank_name: null,
    account_number: null,
    iban: null,
    bic: null,
    balance: '1000.00',
    is_active: true,
  },
  {
    id: 'repo-bank',
    code: 'BA1',
    name: 'Bank Account',
    type: 'bank_account' as const,
    bank_name: 'Test Bank',
    account_number: '1234',
    iban: null,
    bic: null,
    balance: '5000.00',
    is_active: true,
  },
  {
    id: 'repo-safe',
    code: 'SF1',
    name: 'Safe',
    type: 'safe' as const,
    bank_name: null,
    account_number: null,
    iban: null,
    bic: null,
    balance: '2000.00',
    is_active: true,
  },
]

// Mock the API modules
vi.mock('../../api/paymentMethodApi', () => ({
  fetchPaymentMethods: vi.fn(() => Promise.resolve(mockPaymentMethods)),
}))

vi.mock('../../api/paymentRepositoryApi', () => ({
  fetchPaymentRepositories: vi.fn(() => Promise.resolve(mockPaymentRepositories)),
}))

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
    const { container } = renderWithClient(
      <AdvancedPaymentsModal
        isOpen={false}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    expect(container.firstChild).toBeNull()
  })

  it('renders when isOpen is true', async () => {
    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Title uses i18n key: t('advancedPayments.title')
    await waitFor(() => {
      expect(screen.getByText('advancedPayments.title')).toBeInTheDocument()
    })
  })

  it('displays cart summary with correct totals', async () => {
    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Wait for data to load
    await waitFor(() => {
      expect(screen.getByText('advancedPayments.cartSummary')).toBeInTheDocument()
    })

    // Totals rendered as toFixed(2) + " EUR" (from useCurrency mock)
    // Subtotal: 100.00 EUR, Tax: 19.00 EUR, Total: 119.00 EUR
    expect(screen.getByText('100.00 EUR')).toBeInTheDocument()
    expect(screen.getByText('19.00 EUR')).toBeInTheDocument()
    // 119.00 EUR appears for both total and remaining, so use getAllByText
    const totalTexts = screen.getAllByText('119.00 EUR')
    expect(totalTexts.length).toBeGreaterThanOrEqual(2)
  })

  it('displays all payment methods', async () => {
    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    // Payment method names come from the mock data, not i18n
    await waitFor(() => {
      expect(screen.getByText('Cash')).toBeInTheDocument()
    })
    expect(screen.getByText('Card')).toBeInTheDocument()
    expect(screen.getByText('Check')).toBeInTheDocument()
    expect(screen.getByText('Bank Transfer')).toBeInTheDocument()
  })

  it('selects payment method when clicked', async () => {
    const user = userEvent.setup()

    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /cash/i })).toBeInTheDocument()
    })

    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // After selection, payment distribution section should appear (i18n key)
    expect(screen.getByText('advancedPayments.paymentDistribution')).toBeInTheDocument()
  })

  it('allows entering payment amount for selected method', async () => {
    const user = userEvent.setup()

    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /cash/i })).toBeInTheDocument()
    })

    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // The input placeholder is hardcoded as "0.000" in the component
    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '119.000')

    expect(paymentInput).toHaveValue(119)
  })

  it('calculates remaining amount correctly', async () => {
    const user = userEvent.setup()

    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /cash/i })).toBeInTheDocument()
    })

    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '50')

    // Remaining should be 119 - 50 = 69, displayed as "69.00 EUR"
    await waitFor(() => {
      expect(screen.getByText('69.00 EUR')).toBeInTheDocument()
    })
  })

  it('enables complete button when payment equals total', async () => {
    const user = userEvent.setup()

    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /cash/i })).toBeInTheDocument()
    })

    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '119')

    // Button text uses i18n key: t('advancedPayments.completeTransaction')
    const completeButton = screen.getByRole('button', { name: /advancedPayments\.completeTransaction/i })
    expect(completeButton).not.toBeDisabled()
  })

  it('disables complete button when payment is insufficient', async () => {
    const user = userEvent.setup()

    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /cash/i })).toBeInTheDocument()
    })

    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '50')

    const completeButton = screen.getByRole('button', { name: /advancedPayments\.completeTransaction/i })
    expect(completeButton).toBeDisabled()
  })

  it('shows overpayment handler when payment exceeds total', async () => {
    const user = userEvent.setup()

    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /cash/i })).toBeInTheDocument()
    })

    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '150')

    // Overpayment text uses i18n keys
    await waitFor(() => {
      expect(screen.getByText('advancedPayments.overpaymentDetected')).toBeInTheDocument()
    })
    expect(screen.getByText(/advancedPayments\.excess/)).toBeInTheDocument()
  })

  it('supports split payment with multiple methods', async () => {
    const user = userEvent.setup()

    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /cash/i })).toBeInTheDocument()
    })

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
    const completeButton = screen.getByRole('button', { name: /advancedPayments\.completeTransaction/i })
    expect(completeButton).not.toBeDisabled()
  })

  it('calls onComplete with correct payment data', async () => {
    const user = userEvent.setup()
    const onComplete = vi.fn().mockResolvedValue({ receiptId: 'r1', receiptNumber: 'R-001' })

    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={onComplete}
      />
    )

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /cash/i })).toBeInTheDocument()
    })

    // Select and enter payment
    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    const paymentInput = screen.getByPlaceholderText('0.000')
    await user.type(paymentInput, '119')

    // Complete transaction
    const completeButton = screen.getByRole('button', { name: /advancedPayments\.completeTransaction/i })
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

    renderWithClient(
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

    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /cash/i })).toBeInTheDocument()
    })

    // Select cash
    const cashButton = screen.getByRole('button', { name: /cash/i })
    await user.click(cashButton)

    // Click "Use Remaining" — button text is i18n key
    const useRemainingButton = screen.getByRole('button', { name: /advancedPayments\.useRemaining/i })
    await user.click(useRemainingButton)

    // Input should have the full amount (119.00 as toFixed(2))
    const paymentInput = screen.getByPlaceholderText('0.000')
    expect(paymentInput).toHaveValue(119)
  })

  it('displays cart items in mini cart', async () => {
    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    await waitFor(() => {
      expect(screen.getByText('advancedPayments.cartSummary')).toBeInTheDocument()
    })

    // Check product name is displayed
    expect(screen.getByText('Product 1')).toBeInTheDocument()

    // Check quantity
    expect(screen.getByText('×2')).toBeInTheDocument()

    // Check line total — item.line_total is "100.000", displayed as "{line_total} {currency}"
    expect(screen.getByText('100.000 EUR')).toBeInTheDocument()
  })
})
