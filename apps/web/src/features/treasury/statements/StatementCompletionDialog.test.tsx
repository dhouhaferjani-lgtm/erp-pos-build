import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { StatementCompletionDialog } from './StatementCompletionDialog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('StatementCompletionDialog', () => {
  it('requires acknowledgment when ignored lines have a nonzero signed total', () => {
    const onConfirm = vi.fn()
    render(<StatementCompletionDialog isOpen hasIgnoredLines ignoredTotal="10.000" currency="TND" onClose={vi.fn()} onConfirm={onConfirm} />)

    const confirm = screen.getByRole('button', { name: 'statements.workspace.complete.confirm' })
    expect(confirm).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox'))
    fireEvent.click(confirm)
    expect(onConfirm).toHaveBeenCalledWith(true)
  })

  it('requires acknowledgment when ignored lines net to zero', () => {
    const onConfirm = vi.fn()
    render(<StatementCompletionDialog isOpen hasIgnoredLines ignoredTotal="0.000" currency="TND" onClose={vi.fn()} onConfirm={onConfirm} />)

    expect(screen.getByRole('button', { name: 'statements.workspace.complete.confirm' })).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox'))
    fireEvent.click(screen.getByRole('button', { name: 'statements.workspace.complete.confirm' }))
    expect(onConfirm).toHaveBeenCalledWith(true)
  })

  it('does not require acknowledgment when no line is ignored', () => {
    const onConfirm = vi.fn()
    render(<StatementCompletionDialog isOpen hasIgnoredLines={false} ignoredTotal="0.000" currency="TND" onClose={vi.fn()} onConfirm={onConfirm} />)

    fireEvent.click(screen.getByRole('button', { name: 'statements.workspace.complete.confirm' }))
    expect(onConfirm).toHaveBeenCalledWith(false)
  })
})
