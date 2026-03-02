import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { I18nextProvider } from 'react-i18next'
import i18n from '@/lib/i18n'
import { AdvancedPaymentsModal } from './AdvancedPaymentsModal'
import type { CartItem } from '../../molecules/CartLineItem'

// Mock the hooks
vi.mock('../../hooks', () => ({
  useCompanySettings: vi.fn(() => ({
    settings: { auto_print_receipts: false, receipt_logo: null, receipt_footer: null },
    isLoading: false,
    error: null,
    autoPrintReceipts: false,
    receiptLogo: null,
    receiptFooter: null,
  })),
}))

// Import the mocked module for use in tests
import { useCompanySettings } from '../../hooks'

// Mock useCurrency
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
  getDecimals: () => 2,
  getLocale: () => 'fr-FR',
  formatAmount: (value: string | number, currency: string) => {
    const num = typeof value === 'string' ? parseFloat(value) : value
    return `${num.toFixed(2)} ${currency}`
  },
}))

// Mock payment APIs
vi.mock('../../api/paymentMethodApi', () => ({
  fetchPaymentMethods: vi.fn(() => Promise.resolve([
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
  ])),
}))

vi.mock('../../api/paymentRepositoryApi', () => ({
  fetchPaymentRepositories: vi.fn(() => Promise.resolve([
    {
      id: 'repo-cash',
      code: 'CR1',
      name: 'Main Register',
      type: 'cash_register',
      bank_name: null,
      account_number: null,
      iban: null,
      bic: null,
      balance: '1000.00',
      is_active: true,
    },
  ])),
}))

vi.mock('../../components/ReceiptPrintButton', () => ({
  ReceiptPrintButton: ({ receiptId, autoPrint }: { receiptId: string; autoPrint: boolean }) => (
    <div data-testid="receipt-print-button">
      <span>Receipt ID: {receiptId}</span>
      <span>Auto-print: {autoPrint.toString()}</span>
    </div>
  ),
}))

/** Helper: tap Cash button, accept pre-filled amount, and click "Add Payment" */
async function addCashPayment(amount?: string) {
  const user = userEvent.setup()

  // Tap Cash method button
  const cashButton = await screen.findByRole('button', { name: /cash/i })
  await user.click(cashButton)

  // Optionally change amount
  if (amount !== undefined) {
    const amountInput = screen.getByPlaceholderText('0.00')
    await user.clear(amountInput)
    await user.type(amountInput, amount)
  }

  // Click "Add Payment"
  const addButton = screen.getByRole('button', { name: /add payment/i })
  await user.click(addButton)

  // Click "Complete Transaction"
  const completeButton = screen.getByRole('button', {
    name: /complete transaction/i,
  })
  await user.click(completeButton)
}

describe('AdvancedPaymentsModal - Receipt Printing Integration', () => {
  const mockCartItems: CartItem[] = [
    {
      id: 'item-1',
      product: {
        id: 'prod-1',
        name: 'Product A',
        sku: 'SKU-001',
        price: '10.000',
      },
      quantity: 2,
      unit_price: '10.000',
      line_total: '20.000',
      tax_amount: '0.000',
    },
  ]

  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
        mutations: { retry: false },
      },
    })
    vi.clearAllMocks()
  })

  const renderModal = (onComplete = vi.fn(), autoPrintReceipts = false) => {
    const mockedUseCompanySettings = vi.mocked(useCompanySettings)
    mockedUseCompanySettings.mockReturnValue({
      settings: { auto_print_receipts: autoPrintReceipts, receipt_logo: null, receipt_footer: null },
      isLoading: false,
      error: null,
      autoPrintReceipts,
      receiptLogo: null,
      receiptFooter: null,
    })

    return render(
      <QueryClientProvider client={queryClient}>
        <I18nextProvider i18n={i18n}>
          <AdvancedPaymentsModal
            isOpen={true}
            onClose={vi.fn()}
            cartItems={mockCartItems}
            onComplete={onComplete}
          />
        </I18nextProvider>
      </QueryClientProvider>
    )
  }

  it('should show receipt print button after successful payment', async () => {
    const mockOnComplete = vi.fn().mockResolvedValue({
      receiptId: 'receipt-123',
      receiptNumber: 'REC-0001',
    })

    renderModal(mockOnComplete)

    await addCashPayment()

    // Wait for success state
    await waitFor(() => {
      expect(screen.getByText(/payment successful/i)).toBeInTheDocument()
    })

    // Verify receipt print button is shown
    const printButton = screen.getByTestId('receipt-print-button')
    expect(printButton).toBeInTheDocument()
    expect(printButton).toHaveTextContent('Receipt ID: receipt-123')
  })

  it('should pass auto-print setting to ReceiptPrintButton', async () => {
    const mockOnComplete = vi.fn().mockResolvedValue({
      receiptId: 'receipt-456',
      receiptNumber: 'REC-0002',
    })

    renderModal(mockOnComplete, true) // Enable auto-print

    await addCashPayment()

    await waitFor(() => {
      expect(screen.getByText(/payment successful/i)).toBeInTheDocument()
    })

    // Verify auto-print is enabled
    const printButton = screen.getByTestId('receipt-print-button')
    expect(printButton).toHaveTextContent('Auto-print: true')
  })

  it('should show receipt number in success message', async () => {
    const mockOnComplete = vi.fn().mockResolvedValue({
      receiptId: 'receipt-789',
      receiptNumber: 'REC-0003',
    })

    renderModal(mockOnComplete)

    await addCashPayment()

    await waitFor(() => {
      expect(screen.getByText(/receipt #REC-0003/i)).toBeInTheDocument()
    })
  })

  it('should show New Transaction button after success', async () => {
    const mockOnComplete = vi.fn().mockResolvedValue({
      receiptId: 'receipt-999',
      receiptNumber: 'REC-0004',
    })

    renderModal(mockOnComplete)

    await addCashPayment()

    await waitFor(() => {
      expect(screen.getByText(/new transaction/i)).toBeInTheDocument()
    })

    const newTransactionButton = screen.getByRole('button', {
      name: /new transaction/i,
    })
    expect(newTransactionButton).toBeInTheDocument()
  })

  it('should show processing state while payment is being processed', async () => {
    let resolvePayment: (value: unknown) => void
    const mockOnComplete = vi.fn(
      () =>
        new Promise((resolve) => {
          resolvePayment = resolve
        })
    )

    renderModal(mockOnComplete)

    await addCashPayment()

    // Verify processing state
    await waitFor(() => {
      expect(screen.getByText(/processing/i)).toBeInTheDocument()
    })

    // Resolve payment
    resolvePayment!({
      receiptId: 'receipt-123',
      receiptNumber: 'REC-0001',
    })

    // Wait for success
    await waitFor(() => {
      expect(screen.getByText(/payment successful/i)).toBeInTheDocument()
    })
  })

  it('should not show success state if payment fails', async () => {
    const mockOnComplete = vi.fn().mockRejectedValue(new Error('Payment failed'))

    renderModal(mockOnComplete)

    await addCashPayment()

    // Wait a bit
    await waitFor(() => expect(mockOnComplete).toHaveBeenCalled())

    // Verify success screen is NOT shown
    expect(screen.queryByText(/payment successful/i)).not.toBeInTheDocument()
  })
})
