import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { TransactionDiscountInput } from './TransactionDiscountInput'

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
        'pos:errors.discountExceedsLimit': `Discount exceeds maximum allowed (${params?.limit}%)`,
        'pos:errors.reasonRequired': 'Reason is required for discounts above 10%',
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

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    expect(screen.getByText('Discount amount is required')).toBeInTheDocument()
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
    expect(screen.getByText(/100.000 TND/)).toBeInTheDocument() // Original
    expect(screen.getByText(/-10.000 TND/)).toBeInTheDocument() // Discount
    expect(screen.getByText(/90.000 TND/)).toBeInTheDocument() // After discount
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

    const applyButton = screen.getByText('Apply Discount') as HTMLButtonElement

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

    // First, trigger an error
    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    expect(screen.getByText('Discount amount is required')).toBeInTheDocument()

    // Now change input
    const amountInput = screen.getByLabelText('Discount Amount') as HTMLInputElement
    fireEvent.change(amountInput, { target: { value: '5.000' } })

    // Error should be gone
    expect(screen.queryByText('Discount amount is required')).not.toBeInTheDocument()
  })
})
