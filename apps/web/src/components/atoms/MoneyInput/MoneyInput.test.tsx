import { describe, it, expect, vi } from 'vitest'
import { useState } from 'react'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MoneyInput } from './MoneyInput'

/**
 * Stateful harness mirroring real controlled usage: the parent feeds the
 * emitted string back as `value` so typing accumulates. `onSpy` observes
 * every emitted value to assert it is always a string.
 */
function Harness({
  onSpy,
  currency = 'EUR',
}: {
  onSpy: (v: string) => void
  currency?: string
}) {
  const [val, setVal] = useState('')
  return (
    <MoneyInput
      value={val}
      currency={currency}
      onChange={(v) => {
        onSpy(v)
        setVal(v)
      }}
    />
  )
}

describe('MoneyInput', () => {
  it('emits a canonical string (not a JS number) on change', async () => {
    const onSpy = vi.fn<(v: string) => void>()
    render(<Harness onSpy={onSpy} />)
    const input = screen.getByRole('spinbutton')
    await userEvent.type(input, '5.123')
    // Every emission must be a string, and the final accumulated value '5.123'.
    const emitted = onSpy.mock.calls.map((call) => call[0])
    emitted.forEach((arg) => {
      expect(typeof arg).toBe('string')
    })
    expect(emitted.at(-1)).toBe('5.123')
  })

  it('passes through the raw string without parseFloat (no Number coercion)', async () => {
    const onSpy = vi.fn<(v: string) => void>()
    render(<Harness onSpy={onSpy} />)
    const input = screen.getByRole('spinbutton')
    await userEvent.type(input, '5')
    const lastArg = onSpy.mock.calls.at(-1)?.[0]
    expect(typeof lastArg).toBe('string')
    expect(lastArg).toBe('5')
  })

  it('uses currency-aware step for EUR (2 decimals)', () => {
    render(<MoneyInput value="" onChange={vi.fn()} currency="EUR" />)
    expect(screen.getByRole('spinbutton')).toHaveAttribute('step', '0.01')
  })

  it('uses currency-aware step for TND (3 decimals)', () => {
    render(<MoneyInput value="" onChange={vi.fn()} currency="TND" />)
    expect(screen.getByRole('spinbutton')).toHaveAttribute('step', '0.001')
  })

  it('defaults min to 0', () => {
    render(<MoneyInput value="" onChange={vi.fn()} currency="EUR" />)
    expect(screen.getByRole('spinbutton')).toHaveAttribute('min', '0')
  })

  it('allows overriding min and max with strings', () => {
    render(<MoneyInput value="" onChange={vi.fn()} currency="EUR" min="-10" max="100" />)
    const input = screen.getByRole('spinbutton')
    expect(input).toHaveAttribute('min', '-10')
    expect(input).toHaveAttribute('max', '100')
  })

  it('renders the raw string value', () => {
    render(<MoneyInput value="12.34" onChange={vi.fn()} currency="EUR" />)
    expect(screen.getByRole('spinbutton')).toHaveValue(12.34)
  })

  it('forwards standard input attributes', () => {
    render(
      <MoneyInput
        value=""
        onChange={vi.fn()}
        currency="EUR"
        id="amount"
        disabled
        placeholder="0.00"
        aria-label="Amount"
        className="custom-class"
      />,
    )
    const input = screen.getByRole('spinbutton')
    expect(input).toHaveAttribute('id', 'amount')
    expect(input).toBeDisabled()
    expect(input).toHaveAttribute('placeholder', '0.00')
    expect(input).toHaveAttribute('aria-label', 'Amount')
    expect(input).toHaveClass('custom-class')
  })
})
