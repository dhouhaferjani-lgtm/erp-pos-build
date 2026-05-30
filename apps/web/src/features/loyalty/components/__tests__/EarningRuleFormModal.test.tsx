import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { EarningRuleFormModal } from '../EarningRuleFormModal'
import type { CreateEarningRuleData } from '../../types/loyalty'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('EarningRuleFormModal — string payload', () => {
  const onClose = vi.fn()
  const onSubmit = vi.fn()

  beforeEach(() => {
    onClose.mockClear()
    onSubmit.mockClear()
  })

  it('passes reward_value as a string in the onSubmit payload', async () => {
    render(
      <EarningRuleFormModal
        isOpen
        onClose={onClose}
        onSubmit={onSubmit}
        isPending={false}
        editingRule={null}
      />,
    )

    // Fill required name field
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Test Rule' } })

    // QuantityInput renders as type="number" — find all spinbuttons and pick reward_value
    const spinbuttons = screen.getAllByRole('spinbutton')
    // spinbuttons[0] = priority, spinbuttons[1] = reward_value, spinbuttons[2] = max_earn_per_transaction, spinbuttons[3] = max_earn_per_day
    const rewardValueInput = spinbuttons[1]
    fireEvent.change(rewardValueInput, { target: { value: '5.50' } })

    fireEvent.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => {
      expect(onSubmit).toHaveBeenCalled()
    })

    const [payload] = onSubmit.mock.calls[0] as [CreateEarningRuleData]
    // QuantityInput emits raw string — payload must never be a JS number
    expect(typeof payload.reward_value).toBe('string')
    expect(payload.reward_value).toBe('5.50')
  })
})
