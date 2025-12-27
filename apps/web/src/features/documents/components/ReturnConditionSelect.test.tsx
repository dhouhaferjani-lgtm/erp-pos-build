import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ReturnConditionSelect } from './ReturnConditionSelect'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('ReturnConditionSelect', () => {
  it('renders with label', () => {
    render(<ReturnConditionSelect value="" onChange={vi.fn()} />)
    expect(screen.getByLabelText(/sales:returnNotes.condition.label/i)).toBeInTheDocument()
  })

  it('shows required indicator when required', () => {
    render(<ReturnConditionSelect value="" onChange={vi.fn()} required />)
    expect(screen.getByText('*')).toBeInTheDocument()
  })

  it('renders all return condition options', () => {
    render(<ReturnConditionSelect value="" onChange={vi.fn()} />)

    expect(screen.getByText('sales:returnNotes.condition.unopened')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.condition.used')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.condition.damaged')).toBeInTheDocument()
    expect(screen.getByText('sales:returnNotes.condition.unusable')).toBeInTheDocument()
  })

  it('calls onChange when selection changes', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()

    render(<ReturnConditionSelect value="" onChange={onChange} />)

    const select = screen.getByRole('combobox')
    await user.selectOptions(select, 'damaged')

    expect(onChange).toHaveBeenCalledWith('damaged')
  })

  it('shows selected value', () => {
    render(<ReturnConditionSelect value="used" onChange={vi.fn()} />)

    const select = screen.getByRole('combobox')
    expect(select.value).toBe('used')
  })

  it('can be disabled', () => {
    render(<ReturnConditionSelect value="" onChange={vi.fn()} disabled />)

    const select = screen.getByRole('combobox')
    expect(select).toBeDisabled()
  })

  it('displays error message when provided', () => {
    render(<ReturnConditionSelect value="" onChange={vi.fn()} error="This field is required" />)

    expect(screen.getByText('This field is required')).toBeInTheDocument()
  })

  it('applies error styling when error is present', () => {
    render(<ReturnConditionSelect value="" onChange={vi.fn()} error="Error" />)

    const select = screen.getByRole('combobox')
    expect(select.className).toContain('border-red-300')
  })

  it('applies custom className', () => {
    const { container } = render(
      <ReturnConditionSelect value="" onChange={vi.fn()} className="custom-class" />
    )

    expect(container.firstChild).toHaveClass('custom-class')
  })
})
