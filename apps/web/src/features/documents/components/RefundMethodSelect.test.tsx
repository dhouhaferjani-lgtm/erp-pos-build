import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { RefundMethodSelect } from './RefundMethodSelect'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('RefundMethodSelect', () => {
  it('renders with label', () => {
    render(<RefundMethodSelect value="" onChange={vi.fn()} />)
    expect(screen.getByLabelText(/sales:returnNotes.refundMethod.label/i)).toBeInTheDocument()
  })

  it('shows required indicator when required', () => {
    render(<RefundMethodSelect value="" onChange={vi.fn()} required />)
    expect(screen.getByText('*')).toBeInTheDocument()
  })

  it('renders all refund method options', () => {
    render(<RefundMethodSelect value="" onChange={vi.fn()} />)

    expect(screen.getByText('sales:returnNotes.refundMethod.originalPayment')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.refundMethod.storeCredit')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.refundMethod.exchange')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.refundMethod.none')).toBeInTheDocument()
  })

  it('calls onChange when selection changes', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()

    render(<RefundMethodSelect value="" onChange={onChange} />)

    const select = screen.getByRole('combobox')
    await user.selectOptions(select, 'store_credit')

    expect(onChange).toHaveBeenCalledWith('store_credit')
  })

  it('shows selected value', () => {
    render(<RefundMethodSelect value="exchange" onChange={vi.fn()} />)

    const select = screen.getByRole('combobox')
    expect((select as HTMLSelectElement).value).toBe('exchange')
  })

  it('can be disabled', () => {
    render(<RefundMethodSelect value="" onChange={vi.fn()} disabled />)

    const select = screen.getByRole('combobox')
    expect(select).toBeDisabled()
  })

  it('displays error message when provided', () => {
    render(<RefundMethodSelect value="" onChange={vi.fn()} error="This field is required" />)

    expect(screen.getByText('This field is required')).toBeInTheDocument()
  })

  it('applies error styling when error is present', () => {
    render(<RefundMethodSelect value="" onChange={vi.fn()} error="Error" />)

    const select = screen.getByRole('combobox')
    expect(select.className).toContain('border-red-300')
  })

  it('applies custom className', () => {
    const { container } = render(
      <RefundMethodSelect value="" onChange={vi.fn()} className="custom-class" />
    )

    expect(container.firstChild).toHaveClass('custom-class')
  })
})
