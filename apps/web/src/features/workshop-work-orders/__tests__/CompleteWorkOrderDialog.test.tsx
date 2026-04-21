import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { CompleteWorkOrderDialog } from '../components/CompleteWorkOrderDialog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('CompleteWorkOrderDialog', () => {
  it('renders nothing when closed', () => {
    render(
      <CompleteWorkOrderDialog
        isOpen={false}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        isPending={false}
      />,
    )
    expect(screen.queryByText('complete_dialog.title')).not.toBeInTheDocument()
  })

  it('submits the typed mileage value', async () => {
    const user = userEvent.setup()
    const onConfirm = vi.fn()
    render(
      <CompleteWorkOrderDialog
        isOpen={true}
        onClose={vi.fn()}
        onConfirm={onConfirm}
        isPending={false}
      />,
    )

    await user.type(screen.getByLabelText('complete_dialog.mileage_label'), '42000')
    await user.click(screen.getByRole('button', { name: 'complete_dialog.confirm' }))

    expect(onConfirm).toHaveBeenCalledTimes(1)
    expect(onConfirm).toHaveBeenCalledWith({ completion_mileage: 42000 })
  })

  it('submits null when mileage is left blank', async () => {
    const user = userEvent.setup()
    const onConfirm = vi.fn()
    render(
      <CompleteWorkOrderDialog
        isOpen={true}
        onClose={vi.fn()}
        onConfirm={onConfirm}
        isPending={false}
      />,
    )

    await user.click(screen.getByRole('button', { name: 'complete_dialog.confirm' }))

    expect(onConfirm).toHaveBeenCalledWith({ completion_mileage: null })
  })

  it('invokes onClose when cancel is clicked', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()
    const onConfirm = vi.fn()
    render(
      <CompleteWorkOrderDialog
        isOpen={true}
        onClose={onClose}
        onConfirm={onConfirm}
        isPending={false}
      />,
    )

    await user.click(screen.getByRole('button', { name: 'complete_dialog.cancel' }))

    expect(onClose).toHaveBeenCalledTimes(1)
    expect(onConfirm).not.toHaveBeenCalled()
  })

  it('disables buttons while pending', () => {
    render(
      <CompleteWorkOrderDialog
        isOpen={true}
        onClose={vi.fn()}
        onConfirm={vi.fn()}
        isPending={true}
      />,
    )

    expect(screen.getByRole('button', { name: 'complete_dialog.confirm' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'complete_dialog.cancel' })).toBeDisabled()
  })
})
