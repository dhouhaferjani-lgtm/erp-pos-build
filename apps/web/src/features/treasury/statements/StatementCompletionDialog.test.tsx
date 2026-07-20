import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { StatementCompletionDialog } from './StatementCompletionDialog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('StatementCompletionDialog', () => {
  it('gates completion on ignored-total acknowledgment only when ignored lines exist', () => {
    const onConfirm = vi.fn()
    const { rerender } = render(<StatementCompletionDialog isOpen ignoredTotal="10.000" currency="TND" onClose={vi.fn()} onConfirm={onConfirm} />)

    const confirm = screen.getByRole('button', { name: 'statements.workspace.complete.confirm' })
    expect(confirm).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox'))
    fireEvent.click(confirm)
    expect(onConfirm).toHaveBeenCalledWith(true)

    onConfirm.mockClear()
    rerender(<StatementCompletionDialog isOpen ignoredTotal="0.000" currency="TND" onClose={vi.fn()} onConfirm={onConfirm} />)
    fireEvent.click(screen.getByRole('button', { name: 'statements.workspace.complete.confirm' }))
    expect(onConfirm).toHaveBeenCalledWith(false)
  })
})
