/**
 * NotesCell Component Tests
 * TDD: Tests written FIRST before implementation
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { NotesCell } from '../NotesCell'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const translations: Record<string, string> = {
        'documents:lines.additionalDescription.addAriaLabel': 'Add additional description',
        'documents:lines.additionalDescription.editAriaLabel': 'Additional description',
        'documents:lines.additionalDescription.placeholder': 'e.g. 2.5h × 60/hr by mechanic John',
      }
      return translations[key] ?? key
    },
  }),
}))

describe('NotesCell', () => {
  let onCommit: ReturnType<typeof vi.fn>

  beforeEach(() => {
    onCommit = vi.fn()
  })

  // 1. add affordance hidden by default when notes empty
  it('add affordance hidden by default when notes empty', () => {
    render(<NotesCell value={null} readOnly={false} onCommit={onCommit} />)
    const btn = screen.getByRole('button', { name: 'Add additional description' })
    expect(btn).toBeInTheDocument()
    expect(btn.className).toContain('opacity-0')
  })

  // 2. add affordance visible on hover (group-hover class applied)
  it('add affordance has group-hover:opacity-100 class', () => {
    render(<NotesCell value={null} readOnly={false} onCommit={onCommit} />)
    const btn = screen.getByRole('button', { name: 'Add additional description' })
    expect(btn.className).toMatch(/group-hover:opacity-100/)
  })

  // 3. clicking add affordance opens textarea
  it('clicking add affordance opens textarea', async () => {
    const user = userEvent.setup()
    render(<NotesCell value={null} readOnly={false} onCommit={onCommit} />)
    await user.click(screen.getByRole('button', { name: 'Add additional description' }))
    expect(screen.getByRole('textbox', { name: 'Additional description' })).toBeInTheDocument()
  })

  // 4. Enter commits and calls onCommit
  it('Enter commits and calls onCommit', async () => {
    const user = userEvent.setup()
    render(<NotesCell value={null} readOnly={false} onCommit={onCommit} />)
    await user.click(screen.getByRole('button', { name: 'Add additional description' }))
    const textarea = screen.getByRole('textbox', { name: 'Additional description' })
    await user.type(textarea, 'Service note')
    await user.keyboard('{Enter}')
    expect(onCommit).toHaveBeenCalledWith('Service note')
    expect(screen.queryByRole('textbox')).toBeNull()
  })

  // 5. Shift+Enter inserts newline (does not commit)
  it('Shift+Enter inserts newline without committing', async () => {
    const user = userEvent.setup()
    render(<NotesCell value={null} readOnly={false} onCommit={onCommit} />)
    await user.click(screen.getByRole('button', { name: 'Add additional description' }))
    const textarea = screen.getByRole('textbox', { name: 'Additional description' })
    await user.type(textarea, 'Line one')
    await user.keyboard('{Shift>}{Enter}{/Shift}')
    expect(onCommit).not.toHaveBeenCalled()
    expect(screen.getByRole('textbox')).toBeInTheDocument()
  })

  // 6. Escape cancels
  it('Escape cancels without committing', async () => {
    const user = userEvent.setup()
    render(<NotesCell value={null} readOnly={false} onCommit={onCommit} />)
    await user.click(screen.getByRole('button', { name: 'Add additional description' }))
    await user.type(screen.getByRole('textbox', { name: 'Additional description' }), 'some text')
    await user.keyboard('{Escape}')
    expect(onCommit).not.toHaveBeenCalled()
    expect(screen.queryByRole('textbox')).toBeNull()
  })

  // 7. empty textarea commits null
  it('empty textarea commits null', async () => {
    const user = userEvent.setup()
    render(<NotesCell value={null} readOnly={false} onCommit={onCommit} />)
    await user.click(screen.getByRole('button', { name: 'Add additional description' }))
    await user.keyboard('{Enter}')
    expect(onCommit).toHaveBeenCalledWith(null)
  })

  // 8. non-empty value shown as muted text
  it('non-empty value shown as muted text', () => {
    render(<NotesCell value="existing note" readOnly={false} onCommit={onCommit} />)
    expect(screen.getByText('existing note')).toBeInTheDocument()
  })

  // 9. clicking muted text enters edit mode
  it('clicking muted text enters edit mode', async () => {
    const user = userEvent.setup()
    render(<NotesCell value="existing note" readOnly={false} onCommit={onCommit} />)
    await user.click(screen.getByText('existing note'))
    const textarea = screen.getByRole('textbox', { name: 'Additional description' })
    expect(textarea).toBeInTheDocument()
    expect((textarea as HTMLTextAreaElement).value).toBe('existing note')
  })

  // 10. readOnly: no affordance, muted text not clickable
  it('readOnly: no affordance rendered, notes shown as static text', () => {
    render(<NotesCell value="read only note" readOnly={true} onCommit={onCommit} />)
    expect(screen.queryByRole('button')).toBeNull()
    expect(screen.getByText('read only note')).toBeInTheDocument()
  })

  // 11. textarea has dir="auto"
  it('textarea has dir="auto"', async () => {
    const user = userEvent.setup()
    render(<NotesCell value={null} readOnly={false} onCommit={onCommit} />)
    await user.click(screen.getByRole('button', { name: 'Add additional description' }))
    expect(screen.getByRole('textbox', { name: 'Additional description' })).toHaveAttribute('dir', 'auto')
  })
})
