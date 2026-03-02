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
      if (opts && opts['defaultValue']) return opts['defaultValue']
      if (opts && opts['number']) return `${key.replace(/\{\{.*\}\}/, '')}${opts['number']}`
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
    code: 'CASH',
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
    code: 'CARD',
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
    code: 'CHECK',
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
    code: 'TRANSFER',
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

/** Helper: tap a payment method button by name, fill the config panel, and click "Add Payment" */
async function addPaymentViaButton(
  user: ReturnType<typeof userEvent.setup>,
  methodName: string,
  amount?: string
) {
  // Tap the method button
  const methodButton = screen.getByRole('button', { name: new RegExp(methodName, 'i') })
  await user.click(methodButton)

  // Optionally change amount (it's pre-filled with remaining)
  if (amount !== undefined) {
    const amountInput = screen.getByPlaceholderText('0.00')
    await user.clear(amountInput)
    await user.type(amountInput, amount)
  }

  // Click "Add Payment"
  const addButton = screen.getByRole('button', { name: /advancedPayments\.addPaymentButton/i })
  await user.click(addButton)
}

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

    await waitFor(() => {
      expect(screen.getByText('advancedPayments.title')).toBeInTheDocument()
    })
  })

  it('displays payment method buttons in a grid', async () => {
    renderWithClient(
      <AdvancedPaymentsModal
        isOpen={true}
        onClose={vi.fn()}
        cartItems={mockCartItems}
        onComplete={vi.fn()}
      />
    )

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    expect(screen.getByRole('button', { name: /Card/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Check/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Bank Transfer/i })).toBeInTheDocument()
  })

  it('shows configuration panel when a method button is tapped', async () => {
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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    // Tap Cash button
    await user.click(screen.getByRole('button', { name: /Cash/i }))

    // Config panel should appear with amount input pre-filled
    await waitFor(() => {
      expect(screen.getByPlaceholderText('0.00')).toBeInTheDocument()
    })

    // "Add Payment" button should appear
    expect(screen.getByRole('button', { name: /advancedPayments\.addPaymentButton/i })).toBeInTheDocument()
  })

  it('pre-fills amount with remaining balance when tapping a method', async () => {
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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /Cash/i }))

    const amountInput = screen.getByPlaceholderText('0.00') as HTMLInputElement
    // Total is 119.00 (100 + 19 tax)
    expect(amountInput.value).toBe('119.00')
  })

  it('adds payment to the list and resets config panel', async () => {
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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    await addPaymentViaButton(user, 'Cash')

    // Payment should appear in the "Added Payments" list
    await waitFor(() => {
      expect(screen.getByText('advancedPayments.addedPayments')).toBeInTheDocument()
    })
    // 119.00 appears in both the list and the balance bar
    const matches = screen.getAllByText('119.00 EUR')
    expect(matches.length).toBeGreaterThanOrEqual(1)
  })

  it('calculates remaining amount correctly with split payments', async () => {
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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    // Add Cash for 50
    await addPaymentViaButton(user, 'Cash', '50')

    // Remaining should be 69.00
    await waitFor(() => {
      const matches = screen.getAllByText('69.00 EUR')
      expect(matches.length).toBeGreaterThanOrEqual(1)
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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    // Add Cash for the full amount (auto-filled)
    await addPaymentViaButton(user, 'Cash')

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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    // Add Cash for partial amount
    await addPaymentViaButton(user, 'Cash', '50')

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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    // Add Cash for more than total
    await addPaymentViaButton(user, 'Cash', '150')

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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    // Add Cash for 50
    await addPaymentViaButton(user, 'Cash', '50')

    // Add Card for 69 (remaining)
    await addPaymentViaButton(user, 'Card')

    // Complete button should be enabled (50 + 69 = 119)
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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    // Add Cash for the full amount
    await addPaymentViaButton(user, 'Cash')

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

  it('allows deleting a payment from the added list', async () => {
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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    // Add Cash for the full amount
    await addPaymentViaButton(user, 'Cash')

    // Should have the payment in the list
    await waitFor(() => {
      expect(screen.getByText('advancedPayments.addedPayments')).toBeInTheDocument()
    })

    // Click delete button
    const deleteButton = screen.getByLabelText('advancedPayments.removeLine')
    await user.click(deleteButton)

    // List should be gone
    expect(screen.queryByText('advancedPayments.addedPayments')).not.toBeInTheDocument()
  })

  it('uses Pay Remaining button to fill amount', async () => {
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
      expect(screen.getByRole('button', { name: /Cash/i })).toBeInTheDocument()
    })

    // Add Cash for 50 first
    await addPaymentViaButton(user, 'Cash', '50')

    // Tap Card to open config panel
    await user.click(screen.getByRole('button', { name: /Card/i }))

    // Clear the amount that was pre-filled
    const amountInput = screen.getByPlaceholderText('0.00')
    await user.clear(amountInput)

    // Click "Pay Remaining"
    const payRemainingButton = screen.getByRole('button', { name: /advancedPayments\.payRemaining/i })
    await user.click(payRemainingButton)

    // Input should have remaining amount (69.00)
    expect(amountInput).toHaveValue(69)
  })

  it('shows card-specific fields for card payment method', async () => {
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
      expect(screen.getByRole('button', { name: /Card/i })).toBeInTheDocument()
    })

    // Tap Card button
    await user.click(screen.getByRole('button', { name: /Card/i }))

    // Should show card last 4 field
    await waitFor(() => {
      expect(screen.getByPlaceholderText('0000')).toBeInTheDocument()
    })
  })
})
