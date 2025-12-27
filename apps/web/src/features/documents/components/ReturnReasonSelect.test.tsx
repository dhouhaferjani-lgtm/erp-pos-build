import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ReturnReasonSelect } from './ReturnReasonSelect'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('ReturnReasonSelect', () => {
  it('renders with label', () => {
    render(<ReturnReasonSelect value="" onChange={vi.fn()} />)
    expect(screen.getByLabelText(/sales:returnNotes.reason.label/i)).toBeInTheDocument()
  })

  it('shows required indicator when required', () => {
    render(<ReturnReasonSelect value="" onChange={vi.fn()} required />)
    expect(screen.getByText('*')).toBeInTheDocument()
  })

  it('renders all return reason options', () => {
    render(<ReturnReasonSelect value="" onChange={vi.fn()} />)

    expect(screen.getByText('sales:returnNotes.reason.defective')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.reason.wrongItem')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.reason.customerRegret')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.reason.damagedInTransit')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.reason.warranty')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.reason.exchange')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.reason.other')).toBeInTheDocument()
  })

  it('calls onChange when selection changes', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()

    render(<ReturnReasonSelect value="" onChange={onChange} />)

    const select = screen.getByRole('combobox')
    await user.selectOptions(select, 'defective')

    expect(onChange).toHaveBeenCalledWith('defective')
  })

  it('shows selected value', () => {
    render(<ReturnReasonSelect value="warranty" onChange={vi.fn()} />)

    const select = screen.getByRole('combobox') as HTMLSelectElement
    expect(select.value).toBe('warranty')
  })

  it('can be disabled', () => {
    render(<ReturnReasonSelect value="" onChange={vi.fn()} disabled />)

    const select = screen.getByRole('combobox')
    expect(select).toBeDisabled()
  })

  it('displays error message when provided', () => {
    render(<ReturnReasonSelect value="" onChange={vi.fn()} error="This field is required" />)

    expect(screen.getByText('This field is required')).toBeInTheDocument()
  })

  it('applies error styling when error is present', () => {
    render(<ReturnReasonSelect value="" onChange={vi.fn()} error="Error" />)

    const select = screen.getByRole('combobox')
    expect(select.className).toContain('border-red-300')
  })

  it('applies custom className', () => {
    const { container } = render(
      <ReturnReasonSelect value="" onChange={vi.fn()} className="custom-class" />
    )

    expect(container.firstChild).toHaveClass('custom-class')
  })
})
