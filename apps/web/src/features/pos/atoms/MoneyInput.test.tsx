import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { MoneyInput } from './MoneyInput'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

describe('MoneyInput', () => {
  it('renders with label', () => {
    const { getByText } = render(
      <MoneyInput label="Amount" value="" onChange={vi.fn()} />
    )
    expect(getByText('Amount')).toBeInTheDocument()
  })

  it('renders without label when not provided', () => {
    const { container } = render(<MoneyInput value="" onChange={vi.fn()} />)
    expect(container.querySelector('label')).not.toBeInTheDocument()
  })

  it('displays the value correctly', () => {
    const { getByDisplayValue } = render(
      <MoneyInput value="100.50" onChange={vi.fn()} />
    )
    expect(getByDisplayValue('100.50')).toBeInTheDocument()
  })

  it('displays currency symbol', () => {
    const { getByText } = render(
      <MoneyInput value="" onChange={vi.fn()} currency="TND" />
    )
    expect(getByText('TND')).toBeInTheDocument()
  })

  it('uses company currency when not specified', () => {
    const { getByText } = render(<MoneyInput value="" onChange={vi.fn()} />)
    // Default company currency is EUR (no company set in test env)
    expect(getByText('EUR')).toBeInTheDocument()
  })

  it('calls onChange with formatted value when user types', () => {
    const onChange = vi.fn()
    const { getByRole } = render(
      <MoneyInput value="" onChange={onChange} />
    )
    const input = getByRole('textbox') as HTMLInputElement

    fireEvent.change(input, { target: { value: '123' } })
    expect(onChange).toHaveBeenCalledWith('123')
  })

  it('allows decimal input', () => {
    const onChange = vi.fn()
    const { getByRole } = render(
      <MoneyInput value="" onChange={onChange} />
    )
    const input = getByRole('textbox') as HTMLInputElement

    fireEvent.change(input, { target: { value: '123.45' } })
    expect(onChange).toHaveBeenCalledWith('123.45')
  })

  it('prevents non-numeric characters', () => {
    const onChange = vi.fn()
    const { getByRole } = render(
      <MoneyInput value="" onChange={onChange} />
    )
    const input = getByRole('textbox') as HTMLInputElement

    fireEvent.change(input, { target: { value: 'abc' } })
    expect(onChange).not.toHaveBeenCalled()
  })

  it('allows only one decimal point', () => {
    const onChange = vi.fn()
    const { getByRole } = render(
      <MoneyInput value="12.3" onChange={onChange} />
    )
    const input = getByRole('textbox') as HTMLInputElement

    fireEvent.change(input, { target: { value: '12.3.4' } })
    expect(onChange).not.toHaveBeenCalledWith('12.3.4')
  })

  it('limits decimal places based on currency', () => {
    const onChange = vi.fn()
    const { getByRole } = render(
      <MoneyInput value="" onChange={onChange} />
    )
    const input = getByRole('textbox') as HTMLInputElement

    // Default currency is EUR (2 decimals) in test env
    fireEvent.change(input, { target: { value: '123.456' } })
    expect(onChange).toHaveBeenCalledWith('123.45')
  })

  it('applies touch-optimized styles when touchOptimized is true', () => {
    const { getByRole } = render(
      <MoneyInput value="" onChange={vi.fn()} touchOptimized />
    )
    const input = getByRole('textbox') as HTMLInputElement
    expect(input.className).toContain('text-xl')
    expect(input.className).toContain('py-4')
  })

  it('displays error message when provided', () => {
    const { getByText } = render(
      <MoneyInput value="" onChange={vi.fn()} error="Invalid amount" />
    )
    expect(getByText('Invalid amount')).toBeInTheDocument()
  })

  it('applies error styles when error is present', () => {
    const { getByRole } = render(
      <MoneyInput value="" onChange={vi.fn()} error="Error" />
    )
    const input = getByRole('textbox') as HTMLInputElement
    expect(input.className).toContain(colorTokens.intent.danger.borderFocus)
  })

  it('disables input when disabled prop is true', () => {
    const { getByRole } = render(
      <MoneyInput value="" onChange={vi.fn()} disabled />
    )
    const input = getByRole('textbox') as HTMLInputElement
    expect(input).toBeDisabled()
  })

  it('applies disabled styles when disabled', () => {
    const { getByRole } = render(
      <MoneyInput value="" onChange={vi.fn()} disabled />
    )
    const input = getByRole('textbox') as HTMLInputElement
    expect(input.className).toContain(colorTokens.surface.muted)
    expect(input.className).toContain('cursor-not-allowed')
  })

  it('shows placeholder when provided', () => {
    const { getByPlaceholderText } = render(
      <MoneyInput value="" onChange={vi.fn()} placeholder="0.00" />
    )
    expect(getByPlaceholderText('0.00')).toBeInTheDocument()
  })

  it('uses default placeholder when not provided', () => {
    const { getByPlaceholderText } = render(
      <MoneyInput value="" onChange={vi.fn()} />
    )
    // Default currency is EUR (2 decimals) in test env
    expect(getByPlaceholderText('0.00')).toBeInTheDocument()
  })

  it('focuses input when autoFocus is true', () => {
    const { getByRole } = render(
      <MoneyInput value="" onChange={vi.fn()} autoFocus />
    )
    const input = getByRole('textbox') as HTMLInputElement
    expect(input).toHaveFocus()
  })

  it('applies custom className', () => {
    const { container } = render(
      <MoneyInput value="" onChange={vi.fn()} className="custom-class" />
    )
    const wrapper = container.firstChild as HTMLElement
    expect(wrapper.className).toContain('custom-class')
  })

  it('handles empty value', () => {
    const { getByRole } = render(
      <MoneyInput value="" onChange={vi.fn()} />
    )
    const input = getByRole('textbox') as HTMLInputElement
    expect(input.value).toBe('')
  })

  it('handles zero value', () => {
    const { getByDisplayValue } = render(
      <MoneyInput value="0" onChange={vi.fn()} />
    )
    expect(getByDisplayValue('0')).toBeInTheDocument()
  })
})
