import { fireEvent, render, screen } from '@testing-library/react'
import Big from 'big.js'
import { describe, expect, it, vi } from 'vitest'

import { ManualMatchSearch } from './ManualMatchSearch'
import type { RepositoryMovementCandidate } from './api'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const movement: RepositoryMovementCandidate = {
  id: 'movement-1',
  direction: 'out',
  amount: '80.000',
  allocated_amount: '20.000',
  remaining_allocatable_amount: '60.000',
  currency: 'TND',
  balance_after: '500.000',
  ordinal: 12,
  source_type: 'payment',
  source_id: 'payment-1',
  journal_entry_id: null,
  reason_code: null,
  occurred_at: '2026-07-18T10:00:00Z',
}

function renderSearch(props: Partial<React.ComponentProps<typeof ManualMatchSearch>> = {}) {
  return render(
    <ManualMatchSearch
      movements={[movement]}
      currency="TND"
      lineRemaining="100.000"
      search=""
      amount=""
      onSearch={vi.fn()}
      onAmountChange={vi.fn()}
      onAllocate={vi.fn()}
      {...props}
    />,
  )
}

describe('ManualMatchSearch', () => {
  it('propagates the disabled prop to the search input, movement select, amount input and allocate button', () => {
    renderSearch({ disabled: true })

    expect(screen.getByLabelText('statements.workspace.manual.search')).toBeDisabled()
    expect(screen.getByLabelText('statements.workspace.manual.movement')).toBeDisabled()
    expect(screen.getByLabelText('statements.workspace.manual.amount')).toBeDisabled()
    expect(screen.getByRole('button', { name: 'statements.workspace.manual.allocate' })).toBeDisabled()
  })

  it('keeps the amount bounds coherent (min never exceeds max) when there is no allocatable capacity', () => {
    renderSearch({ movements: [], lineRemaining: '100.000' })

    const amount = screen.getByLabelText('statements.workspace.manual.amount')
    const min = amount.getAttribute('min') ?? '0'
    const max = amount.getAttribute('max') ?? '0'
    expect(new Big(min).lte(new Big(max))).toBe(true)
    expect(amount).toBeDisabled()
  })

  it('exposes the min(line, movement) capacity as the max and allocates within it', () => {
    const onAllocate = vi.fn()
    const onAmountChange = vi.fn()
    const { rerender } = renderSearch({ amount: '50.000', onAllocate, onAmountChange })

    const amount = screen.getByLabelText('statements.workspace.manual.amount')
    expect(amount).toHaveAttribute('max', '60.000')
    expect(amount).toHaveAttribute('step', '0.001')
    expect(amount).not.toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'statements.workspace.manual.allocate' }))
    expect(onAllocate).toHaveBeenCalledWith('movement-1', '50.000')

    // The amount input stays enabled while capacity remains.
    rerender(
      <ManualMatchSearch
        movements={[movement]}
        currency="TND"
        lineRemaining="100.000"
        search=""
        amount=""
        disabled={false}
        onSearch={vi.fn()}
        onAmountChange={onAmountChange}
        onAllocate={onAllocate}
      />,
    )
    expect(screen.getByLabelText('statements.workspace.manual.amount')).not.toBeDisabled()
  })
})
