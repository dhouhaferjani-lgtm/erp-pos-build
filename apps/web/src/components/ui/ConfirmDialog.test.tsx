import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, it, expect, vi } from 'vitest'
import { ConfirmDialog } from './ConfirmDialog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        'actions.cancel': 'Cancel',
        'actions.confirm': 'Confirm',
        'actions.processing': 'Processing...',
      }
      return map[key] ?? key
    },
  }),
}))

describe('ConfirmDialog', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    onConfirm: vi.fn(),
    title: 'Delete item?',
    message: 'This action cannot be undone.',
  }

  it('renders nothing when isOpen is false', () => {
    render(<ConfirmDialog {...defaultProps} isOpen={false} />)
    expect(screen.queryByText('Delete item?')).not.toBeInTheDocument()
  })

  it('renders title and message when open', () => {
    render(<ConfirmDialog {...defaultProps} />)
    expect(screen.getByText('Delete item?')).toBeInTheDocument()
    expect(screen.getByText('This action cannot be undone.')).toBeInTheDocument()
  })

  it('exposes the open panel as a dialog', () => {
    render(<ConfirmDialog {...defaultProps} />)

    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('names the dialog from the rendered title', () => {
    render(<ConfirmDialog {...defaultProps} />)

    const dialog = screen.getByRole('dialog', { name: 'Delete item?' })
    const headingId = dialog.getAttribute('aria-labelledby')
    expect(headingId).not.toBeNull()
    expect(document.getElementById(headingId as string)).toHaveTextContent('Delete item?')
  })

  it('describes the dialog from the rendered message', () => {
    render(<ConfirmDialog {...defaultProps} />)

    const dialog = screen.getByRole('dialog')
    const descriptionId = dialog.getAttribute('aria-describedby')
    expect(descriptionId).not.toBeNull()
    expect(document.getElementById(descriptionId as string)).toHaveTextContent(
      'This action cannot be undone.'
    )
  })

  it('does not claim modal containment before the dialog-surfaces follow-up', () => {
    render(<ConfirmDialog {...defaultProps} />)

    const dialog = screen.getByRole('dialog')
    // L-2-FU-dialog-surfaces owns focus trapping, Escape handling, and containment.
    expect(dialog).not.toHaveAttribute('aria-modal')
  })

  it('renders via portal so it does not nest inside parent forms', () => {
    const { container } = render(
      <form data-testid="parent-form">
        <ConfirmDialog {...defaultProps} />
      </form>
    )

    const parentForm = container.querySelector('[data-testid="parent-form"]')
    const dialogTitle = screen.getByText('Delete item?')

    // Dialog should NOT be inside the parent form
    expect(parentForm?.contains(dialogTitle)).toBe(false)
  })

  it('calls onConfirm when confirm button is clicked', async () => {
    const user = userEvent.setup()
    const onConfirm = vi.fn()

    render(<ConfirmDialog {...defaultProps} onConfirm={onConfirm} />)
    await user.click(screen.getByText('Confirm'))
    expect(onConfirm).toHaveBeenCalledOnce()
  })

  it('calls onClose when cancel button is clicked', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()

    render(<ConfirmDialog {...defaultProps} onClose={onClose} />)
    await user.click(screen.getByText('Cancel'))
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('shows loading state and disables buttons', () => {
    render(<ConfirmDialog {...defaultProps} isLoading={true} />)
    expect(screen.getByText('Processing...')).toBeInTheDocument()
    expect(screen.getByText('Cancel')).toBeDisabled()
    expect(screen.getByText('Processing...')).toBeDisabled()
  })
})
