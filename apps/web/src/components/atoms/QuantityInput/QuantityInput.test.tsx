import { describe, it, expect, vi } from 'vitest'
import { useState } from 'react'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QuantityInput } from './QuantityInput'

/**
 * Stateful harness mirroring real controlled usage: the parent feeds the
 * emitted string back as `value` so typing accumulates.
 */
function Harness({ onSpy, decimalPlaces = 4 }: { onSpy: (v: string) => void; decimalPlaces?: number }) {
  const [val, setVal] = useState('')
  return (
    <QuantityInput
      value={val}
      decimalPlaces={decimalPlaces}
      onChange={(v) => {
        onSpy(v)
        setVal(v)
      }}
    />
  )
}

describe('QuantityInput', () => {
  it('emits a canonical string (not a JS number) on change', async () => {
    const onSpy = vi.fn<(v: string) => void>()
    render(<Harness onSpy={onSpy} />)
    const input = screen.getByRole('spinbutton')
    await userEvent.type(input, '5.123')
    const emitted = onSpy.mock.calls.map((call) => call[0])
    emitted.forEach((arg) => {
      expect(typeof arg).toBe('string')
    })
    expect(emitted.at(-1)).toBe('5.123')
  })

  it('derives step from decimalPlaces', () => {
    render(<QuantityInput value="" onChange={vi.fn()} decimalPlaces={4} />)
    expect(screen.getByRole('spinbutton')).toHaveAttribute('step', '0.0001')
  })

  it('derives step from decimalPlaces (2)', () => {
    render(<QuantityInput value="" onChange={vi.fn()} decimalPlaces={2} />)
    expect(screen.getByRole('spinbutton')).toHaveAttribute('step', '0.01')
  })

  it('defaults min to 0', () => {
    render(<QuantityInput value="" onChange={vi.fn()} decimalPlaces={4} />)
    expect(screen.getByRole('spinbutton')).toHaveAttribute('min', '0')
  })

  it('allows overriding min and max with strings', () => {
    render(<QuantityInput value="" onChange={vi.fn()} decimalPlaces={4} min="-5" max="50" />)
    const input = screen.getByRole('spinbutton')
    expect(input).toHaveAttribute('min', '-5')
    expect(input).toHaveAttribute('max', '50')
  })

  it('renders the raw string value', () => {
    render(<QuantityInput value="3.5" onChange={vi.fn()} decimalPlaces={4} />)
    expect(screen.getByRole('spinbutton')).toHaveValue(3.5)
  })

  it('forwards standard input attributes', () => {
    render(
      <QuantityInput
        value=""
        onChange={vi.fn()}
        decimalPlaces={4}
        id="qty"
        disabled
        placeholder="0"
        aria-label="Quantity"
        className="custom-class"
      />,
    )
    const input = screen.getByRole('spinbutton')
    expect(input).toHaveAttribute('id', 'qty')
    expect(input).toBeDisabled()
    expect(input).toHaveAttribute('placeholder', '0')
    expect(input).toHaveAttribute('aria-label', 'Quantity')
    expect(input).toHaveClass('custom-class')
  })
})
