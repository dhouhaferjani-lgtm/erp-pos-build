import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { DiscountInput } from './DiscountInput'

// Mock i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const translations: Record<string, string> = {
        'cart.discountType': 'Discount Type',
        'cart.percentage': 'Percentage',
        'cart.fixed': 'Fixed Amount',
        'cart.presets': 'Quick Presets',
        'cart.maxAllowed': 'Max allowed',
        'cart.reason': 'Reason',
        'cart.reasonRequired': 'Reason required for discounts above 10%',
        'cart.preview': 'Preview',
        'cart.originalTotal': 'Original',
        'cart.discountedTotal': 'Discounted',
        'cart.clearDiscount': 'Clear Discount',
        'cart.applyDiscount': 'Apply Discount',
        'errors.discountExceedsLimit': params?.limit ? `Discount exceeds maximum allowed (${params.limit}%)` : 'Discount exceeds maximum allowed',
        'errors.reasonRequired': 'Reason is required for discounts above 10%',
      }
      return translations[key] || key
    },
  }),
}))

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

describe('DiscountInput', () => {
  const defaultProps = {
    lineTotal: '100.000',
    onApplyDiscount: vi.fn(),
    onClearDiscount: vi.fn(),
    effectiveLimit: 15,
    requiresReason: false,
    touchOptimized: false,
  }

  it('renders discount type toggle buttons', () => {
    render(<DiscountInput {...defaultProps} />)

    expect(screen.getByRole('button', { name: 'Percentage' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Fixed Amount' })).toBeInTheDocument()
  })

  it('renders preset buttons for percentage discounts', () => {
    render(<DiscountInput {...defaultProps} />)

    expect(screen.getByText('5%')).toBeInTheDocument()
    expect(screen.getByText('10%')).toBeInTheDocument()
    expect(screen.getByText('15%')).toBeInTheDocument()
    expect(screen.getByText('20%')).toBeInTheDocument()
  })

  it('disables preset buttons exceeding effective limit', () => {
    render(<DiscountInput {...defaultProps} effectiveLimit={10} />)

    const preset15 = screen.getByRole('button', { name: '15%' })
    const preset20 = screen.getByRole('button', { name: '20%' })

    expect(preset15).toBeDisabled()
    expect(preset20).toBeDisabled()
  })

  it('switches between percentage and fixed discount types', () => {
    render(<DiscountInput {...defaultProps} />)

    const fixedButton = screen.getByText('Fixed Amount')
    fireEvent.click(fixedButton)

    // Presets should no longer be visible for fixed type
    expect(screen.queryByText('Quick Presets')).not.toBeInTheDocument()
  })

  it('applies preset discount value when preset button clicked', () => {
    render(<DiscountInput {...defaultProps} />)

    const preset10 = screen.getByRole('button', { name: '10%' })
    fireEvent.click(preset10)

    const input = screen.getByRole('spinbutton')
    expect(input).toHaveValue(10)
  })

  it('shows reason field when discount exceeds 10%', async () => {
    render(<DiscountInput {...defaultProps} />)

    const input = screen.getByRole('spinbutton')
    fireEvent.change(input, { target: { value: '12' } })

    await waitFor(() => {
      expect(screen.getByLabelText(/Reason/)).toBeInTheDocument()
    })
  })

  it('validates discount against effective limit', async () => {
    render(<DiscountInput {...defaultProps} effectiveLimit={10} />)

    const input = screen.getByRole('spinbutton')
    fireEvent.change(input, { target: { value: '15' } })

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    await waitFor(() => {
      expect(screen.getByText('Discount exceeds maximum allowed (10%)')).toBeInTheDocument()
    })

    expect(defaultProps.onApplyDiscount).not.toHaveBeenCalled()
  })

  it('requires reason for discounts above 10%', async () => {
    render(<DiscountInput {...defaultProps} />)

    const input = screen.getByRole('spinbutton')
    fireEvent.change(input, { target: { value: '12' } })

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    await waitFor(() => {
      expect(screen.getByText('Reason is required for discounts above 10%')).toBeInTheDocument()
    })

    expect(defaultProps.onApplyDiscount).not.toHaveBeenCalled()
  })

  it('calls onApplyDiscount with correct data for percentage discount', async () => {
    const onApplyDiscount = vi.fn()
    render(<DiscountInput {...defaultProps} onApplyDiscount={onApplyDiscount} />)

    const input = screen.getByRole('spinbutton')
    fireEvent.change(input, { target: { value: '5' } })

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    await waitFor(() => {
      expect(onApplyDiscount).toHaveBeenCalledWith({
        type: 'percentage',
        percent: '5',
        reason: undefined,
      })
    })
  })

  it('calls onApplyDiscount with correct data for fixed discount', async () => {
    const onApplyDiscount = vi.fn()
    render(<DiscountInput {...defaultProps} onApplyDiscount={onApplyDiscount} />)

    const fixedButton = screen.getByText('Fixed Amount')
    fireEvent.click(fixedButton)

    const input = screen.getByRole('spinbutton')
    fireEvent.change(input, { target: { value: '10.000' } })

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    await waitFor(() => {
      expect(onApplyDiscount).toHaveBeenCalledWith({
        type: 'fixed',
        amount: '10.000',
        reason: undefined,
      })
    })
  })

  it('displays preview of discounted total', async () => {
    render(<DiscountInput {...defaultProps} />)

    const input = screen.getByRole('spinbutton')
    fireEvent.change(input, { target: { value: '10' } })

    await waitFor(() => {
      expect(screen.getByText(/Preview/)).toBeInTheDocument()
      expect(screen.getByText(/90.00 EUR/)).toBeInTheDocument()
    })
  })

  it('calls onClearDiscount when clear button clicked', () => {
    const onClearDiscount = vi.fn()
    render(<DiscountInput {...defaultProps} onClearDiscount={onClearDiscount} />)

    const clearButton = screen.getByText('Clear Discount')
    fireEvent.click(clearButton)

    expect(onClearDiscount).toHaveBeenCalled()
  })

  it('uses larger inputs in touch-optimized mode', () => {
    const { container } = render(<DiscountInput {...defaultProps} touchOptimized={true} />)

    const input = container.querySelector('input[type="number"]')
    expect(input).toHaveClass('text-lg')
  })

  it('populates current discount values when provided', () => {
    const currentDiscount = {
      type: 'percentage' as const,
      percent: '12', // Above 10% to show reason field
      amount: undefined,
      reason: 'Loyal customer',
    }

    render(<DiscountInput {...defaultProps} currentDiscount={currentDiscount} />)

    const input = screen.getByRole('spinbutton')
    expect(input).toHaveValue(12)

    const reasonInput = screen.getByLabelText(/Reason/)
    expect(reasonInput).toHaveValue('Loyal customer')
  })

  it('disables apply button when discount value is empty', () => {
    render(<DiscountInput {...defaultProps} />)

    const applyButton = screen.getByRole('button', { name: 'Apply Discount' })
    expect(applyButton).toBeDisabled()
  })

  it('clears error when discount value changes', async () => {
    render(<DiscountInput {...defaultProps} effectiveLimit={10} />)

    const input = screen.getByRole('spinbutton')
    fireEvent.change(input, { target: { value: '15' } })

    const applyButton = screen.getByText('Apply Discount')
    fireEvent.click(applyButton)

    await waitFor(() => {
      expect(screen.getByText('Discount exceeds maximum allowed (10%)')).toBeInTheDocument()
    })

    // Change to valid value
    fireEvent.change(input, { target: { value: '8' } })

    await waitFor(() => {
      expect(screen.queryByText('Discount exceeds maximum allowed (10%)')).not.toBeInTheDocument()
    })
  })
})
