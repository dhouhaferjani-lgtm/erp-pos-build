import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AdjustBalanceDialog } from './AdjustBalanceDialog'

const mocks = vi.hoisted(() => ({ mutate: vi.fn() }))

vi.mock('../hooks/useAdjustRepositoryBalance', () => ({
  useAdjustRepositoryBalance: () => ({ mutate: mocks.mutate, isPending: false }),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('AdjustBalanceDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.mutate.mockImplementation((
      _request: unknown,
      options?: { onSuccess?: (result: { movement_id: string }) => void },
    ) => {
      options?.onSuccess?.({ movement_id: 'movement-1' })
    })
  })

  it('renders canonical inputs and every adjustment reason', () => {
    render(
      <AdjustBalanceDialog
        isOpen
        onClose={vi.fn()}
        repositoryId="repo-1"
        repositoryCurrency="TND"
      />,
    )

    expect(screen.getByLabelText(/treasury:repositories\.adjustBalance\.direction/)).toBeInTheDocument()
    expect(screen.getByLabelText(/treasury:repositories\.adjustBalance\.amount/)).toHaveAttribute('step', '0.001')
    const reason = screen.getByLabelText(/treasury:repositories\.adjustBalance\.reasonCode/)
    expect(within(reason).getAllByRole('option').map((option) => option.getAttribute('value'))).toEqual([
      'count_variance',
      'correction',
      'theft_loss',
      'other',
    ])
    expect(screen.getByLabelText(/treasury:repositories\.adjustBalance\.reasonText/)).toHaveAttribute('maxlength', '1000')
  })

  it('preserves the amount string and submits the exact endpoint payload', async () => {
    const onClose = vi.fn()
    const onSuccess = vi.fn()
    render(
      <AdjustBalanceDialog
        isOpen
        onClose={onClose}
        repositoryId="repo-1"
        repositoryCurrency="TND"
        onSuccess={onSuccess}
      />,
    )

    fireEvent.change(screen.getByLabelText(/treasury:repositories\.adjustBalance\.direction/), { target: { value: 'out' } })
    fireEvent.change(screen.getByLabelText(/treasury:repositories\.adjustBalance\.amount/), { target: { value: '12.340' } })
    fireEvent.change(screen.getByLabelText(/treasury:repositories\.adjustBalance\.reasonCode/), { target: { value: 'theft_loss' } })
    fireEvent.change(screen.getByLabelText(/treasury:repositories\.adjustBalance\.reasonText/), { target: { value: 'Drawer shortage' } })
    fireEvent.click(screen.getByRole('button', { name: 'treasury:repositories.adjustBalance.submit' }))

    await waitFor(() => {
      expect(mocks.mutate).toHaveBeenCalledWith(
        {
          direction: 'out',
          amount: '12.340',
          reason_code: 'theft_loss',
          reason_text: 'Drawer shortage',
        },
        expect.objectContaining({ onSuccess: expect.any(Function) }),
      )
    })
    expect(onSuccess).toHaveBeenCalledWith({ movement_id: 'movement-1' })
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it.each(['0', '0.000'])(
    'blocks invalid amount %s before the mutation fires',
    async (amount) => {
      render(
        <AdjustBalanceDialog
          isOpen
          onClose={vi.fn()}
          repositoryId="repo-1"
          repositoryCurrency="TND"
        />,
      )

      fireEvent.change(screen.getByLabelText(/treasury:repositories\.adjustBalance\.amount/), { target: { value: amount } })
      fireEvent.change(screen.getByLabelText(/treasury:repositories\.adjustBalance\.reasonText/), { target: { value: 'Count' } })
      fireEvent.click(screen.getByRole('button', { name: 'treasury:repositories.adjustBalance.submit' }))

      await waitFor(() => {
        expect(screen.getByText('treasury:repositories.adjustBalance.validation.amount')).toBeInTheDocument()
      })
      expect(mocks.mutate).not.toHaveBeenCalled()
    },
  )

  it('shows localized validation for four decimal places before mutation', async () => {
    render(
      <AdjustBalanceDialog
        isOpen
        onClose={vi.fn()}
        repositoryId="repo-1"
        repositoryCurrency="TND"
      />,
    )

    const amountInput = screen.getByLabelText(/treasury:repositories\.adjustBalance\.amount/)
    expect(amountInput.closest('form')).toHaveAttribute('novalidate')
    fireEvent.change(amountInput, { target: { value: '1.2345' } })
    fireEvent.change(screen.getByLabelText(/treasury:repositories\.adjustBalance\.reasonText/), { target: { value: 'Count' } })
    fireEvent.click(screen.getByRole('button', { name: 'treasury:repositories.adjustBalance.submit' }))

    await waitFor(() => {
      expect(screen.getByText('treasury:repositories.adjustBalance.validation.amount')).toBeInTheDocument()
    })
    expect(mocks.mutate).not.toHaveBeenCalled()
  })
})
