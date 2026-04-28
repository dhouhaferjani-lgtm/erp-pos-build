import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { TransactionDiscountInput } from './TransactionDiscountInput'

// Mock useCurrency
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    locale: 'fr-FR',
    decimals: 2,
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

// Mock i18n
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const translations: Record<string, string> = {
        'pos:cart.discountAmount': 'Discount Amount',
        'pos:cart.maxAllowed': 'Max allowed',
        'pos:cart.currentDiscount': 'Current',
        'pos:cart.reason': 'Reason',
        'pos:cart.reasonPlaceholder': 'Enter reason for discount...',
        'pos:cart.preview': 'Preview',
        'pos:cart.originalTotal': 'Original',
        'pos:cart.discount': 'Discount',
        'pos:cart.discountedTotal': 'Discounted',
        'pos:cart.clearDiscount': 'Clear Discount',
        'pos:cart.applyDiscount': 'Apply Discount',
        'pos:errors.amountRequired': 'Discount amount is required',
        'pos:errors.discountExceedsSubtotal': 'Discount cannot exceed subtotal',
        'pos:errors.discountExceedsLimit': `Discount exceeds maximum allowed (${params?.['limit']}%)`,
        'pos:errors.reasonRequired': 'Reason is required for discounts above 10%',
        'pos:discount.below_tolerance': 'This discount is too small — use payment tolerance at the till instead.',
      }
      return translations[key] || key
    },
  }),
}))

describe('TransactionDiscountInput', () => {
  const defaultProps = {
    subtotal: '100.000',
    effectiveLimit: 15,
    requiresReason: false,
    onApply: vi.fn(),
    onClear: vi.fn(),
  }

  it('renders correctly with initial values', () => {
    render(<TransactionDiscountInput {...defaultProps} />)

    expect(screen.getByLabelText('Discount Amount')).toBeInTheDocument()
    expect(screen.getByText(/Max allowed: 15%/)).toBeInTheDocument()
    expect(screen.getByText('Apply Discount')).toBeInTheDocument()
    expect(screen.getByText('Clear Discount')).toBeInTheDocument()
  })

  it('validates discount amount is required', () => {
    render(<TransactionDiscountInput {...defaultProps} />)

    // When amount is empty, the Apply button is disabled, preventing submission
    // This effectively enforces the "amount required" validation at the UI level
    const applyButton = screen.getByRole('button', { name: /Apply Discount/i })
    expect(applyButton).toBeDisabled()
    expect(defaultProps.onApply).not.toHaveBeenCalled()
  })

  it('validates discount cannot exceed subtotal', () => {
    render(<TransactionDiscountInput {...defaultProps} />)

    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement
    fireEvent.change(amountInput, { target: { value: '150.000' } })

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    expect(screen.getByText('Discount cannot exceed subtotal')).toBeInTheDocument()
    expect(defaultProps.onApply).not.toHaveBeenCalled()
  })

  it('validates discount against effective limit', () => {
    render(<TransactionDiscountInput {...defaultProps} />)

    // 20% of 100 = 20, which exceeds 15% limit
    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement
    fireEvent.change(amountInput, { target: { value: '20.000' } })

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    expect(screen.getByText(/Discount exceeds maximum allowed \(15%\)/)).toBeInTheDocument()
    expect(defaultProps.onApply).not.toHaveBeenCalled()
  })

  it('shows reason field when discount > 10%', () => {
    render(<TransactionDiscountInput {...defaultProps} />)

    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement

    // 11% of 100 = 11
    fireEvent.change(amountInput, { target: { value: '11.000' } })

    expect(screen.getByLabelText(/Reason/)).toBeInTheDocument()
  })

  it('requires reason when discount > 10%', () => {
    render(<TransactionDiscountInput {...defaultProps} />)

    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement

    // 11% of 100 = 11
    fireEvent.change(amountInput, { target: { value: '11.000' } })

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    expect(screen.getByText('Reason is required for discounts above 10%')).toBeInTheDocument()
    expect(defaultProps.onApply).not.toHaveBeenCalled()
  })

  it('shows preview of subtotal after discount', () => {
    render(<TransactionDiscountInput {...defaultProps} />)

    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement
    fireEvent.change(amountInput, { target: { value: '10.000' } })

    expect(screen.getByText('Preview:')).toBeInTheDocument()
    expect(screen.getByText(/100.000 EUR/)).toBeInTheDocument() // Original
    expect(screen.getByText(/-10.000 EUR/)).toBeInTheDocument() // Discount
    expect(screen.getByText(/90.000 EUR/)).toBeInTheDocument() // After discount
  })

  it('calls onApply with correct values', () => {
    const onApply = vi.fn()
    render(<TransactionDiscountInput {...defaultProps} onApply={onApply} />)

    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement
    fireEvent.change(amountInput, { target: { value: '5.000' } })

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    expect(onApply).toHaveBeenCalledWith('5.000', undefined)
  })

  it('calls onApply with amount and reason', () => {
    const onApply = vi.fn()
    render(<TransactionDiscountInput {...defaultProps} onApply={onApply} />)

    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement
    fireEvent.change(amountInput, { target: { value: '11.000' } })

    const reasonInput = screen.getByLabelText(/Reason/) as HTMLTextAreaElement
    fireEvent.change(reasonInput, { target: { value: 'Loyal customer' } })

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    expect(onApply).toHaveBeenCalledWith('11.000', 'Loyal customer')
  })

  it('calls onClear when clear button clicked', () => {
    const onClear = vi.fn()
    render(<TransactionDiscountInput {...defaultProps} onClear={onClear} />)

    const clearButton = screen.getByText('Clear Discount')
    fireEvent.click(clearButton)

    expect(onClear).toHaveBeenCalled()
  })

  it('disables apply button when amount is zero', () => {
    render(<TransactionDiscountInput {...defaultProps} />)

    const applyButton = screen.getByRole('button', { name: /Apply Discount/i })

    expect(applyButton).toBeDisabled()
  })

  it('displays current discount percentage', () => {
    render(<TransactionDiscountInput {...defaultProps} />)

    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement
    fireEvent.change(amountInput, { target: { value: '10.000' } })

    // 10/100 = 10%
    expect(screen.getByText(/Current: 10.00%/)).toBeInTheDocument()
  })

  it('accepts current amount and reason as initial values', () => {
    render(
      <TransactionDiscountInput
        {...defaultProps}
        currentAmount="15.000"
        currentReason="VIP customer"
      />
    )

    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement
    expect(amountInput.value).toBe('15.000')

    // Reason field should be visible because 15% > 10%
    const reasonInput = screen.getByLabelText(/Reason/) as HTMLTextAreaElement
    expect(reasonInput.value).toBe('VIP customer')
  })

  it('clears error when user modifies input', () => {
    render(<TransactionDiscountInput {...defaultProps} />)

    // First, enter a value that exceeds subtotal to trigger an error
    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement
    fireEvent.change(amountInput, { target: { value: '150.000' } })

    const applyButton = screen.getByRole('button', { name: /Apply Discount/i })
    fireEvent.click(applyButton)

    expect(screen.getByText('Discount cannot exceed subtotal')).toBeInTheDocument()

    // Now change input to a valid value
    fireEvent.change(amountInput, { target: { value: '5.000' } })

    // Error should be gone
    expect(screen.queryByText('Discount cannot exceed subtotal')).not.toBeInTheDocument()
  })

  describe('tolerance boundary mirror (spec §7)', () => {
    const FR_TOLERANCE = {
      enabled: true,
      percentage: '0.0050',
      max_amount: '0.5000',
      source: 'country' as const,
    }

    it('shows below-tolerance warning for sub-threshold fixed discount', () => {
      render(
        <TransactionDiscountInput
          {...defaultProps}
          subtotal="100.00"
          toleranceSettings={FR_TOLERANCE}
        />,
      )

      const input = screen.getByRole('spinbutton')
      fireEvent.change(input, { target: { value: '0.20' } })

      expect(
        screen.getByText(/use payment tolerance at the till/i),
      ).toBeInTheDocument()
    })

    it('disables apply button when discount is below tolerance', () => {
      render(
        <TransactionDiscountInput
          {...defaultProps}
          subtotal="100.00"
          toleranceSettings={FR_TOLERANCE}
        />,
      )

      const input = screen.getByRole('spinbutton')
      fireEvent.change(input, { target: { value: '0.20' } })

      const applyButton = screen.getByRole('button', { name: /Apply Discount/i })
      expect(applyButton).toBeDisabled()
    })

    it('does not render the warning when toleranceSettings is omitted', () => {
      render(<TransactionDiscountInput {...defaultProps} subtotal="100.00" />)

      const input = screen.getByRole('spinbutton')
      fireEvent.change(input, { target: { value: '0.20' } })

      expect(
        screen.queryByText(/use payment tolerance at the till/i),
      ).not.toBeInTheDocument()
    })
  })
})
