import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { DraftMoneyInput } from './DraftMoneyInput'

describe('DraftMoneyInput', () => {
  it('keeps the focused draft while parent values change and commits on blur', async () => {
    const user = userEvent.setup()
    const onCommit = vi.fn()
    const { rerender } = render(
      <DraftMoneyInput
        aria-label="Amount"
        currency="EUR"
        initialValue="1.00"
        onCommit={onCommit}
      />,
    )
    const input = screen.getByRole('spinbutton', { name: 'Amount' })

    await user.click(input)
    await user.clear(input)
    await user.type(input, '12')
    expect(input).toHaveValue(12)

    rerender(
      <DraftMoneyInput
        aria-label="Amount"
        currency="EUR"
        initialValue="99.00"
        onCommit={onCommit}
      />,
    )

    expect(input).toHaveValue(12)
    expect(onCommit).not.toHaveBeenCalled()

    await user.tab()

    expect(onCommit).toHaveBeenCalledWith('12')
    expect(input).toHaveValue(99)
  })

  it('passes common input props through', () => {
    render(
      <DraftMoneyInput
        aria-label="Amount"
        className="custom-class"
        currency="TND"
        disabled
        error
        initialValue="3.000"
        max="10"
        min="0"
        onCommit={vi.fn()}
        placeholder="0.000"
      />,
    )

    const input = screen.getByRole('spinbutton', { name: 'Amount' })
    expect(input).toBeDisabled()
    expect(input).toHaveAttribute('max', '10')
    expect(input).toHaveAttribute('min', '0')
    expect(input).toHaveAttribute('placeholder', '0.000')
    expect(input).toHaveClass('custom-class')
  })

  it('does not commit when the focused value is unchanged', async () => {
    const user = userEvent.setup()
    const onCommit = vi.fn()
    render(
      <DraftMoneyInput
        aria-label="Amount"
        currency="EUR"
        initialValue="7.00"
        onCommit={onCommit}
      />,
    )

    await user.click(screen.getByRole('spinbutton', { name: 'Amount' }))
    await user.tab()

    expect(onCommit).not.toHaveBeenCalled()
  })
})
