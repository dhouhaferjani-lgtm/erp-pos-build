import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { TransferVoucherModal } from '../TransferVoucherModal'

// ─── i18n mock ────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

// ─── PartnerPicker mock ───────────────────────────────────────────────────────

vi.mock('@/components/molecules/pickers/PartnerPicker', () => ({
  PartnerPicker: ({
    onChange,
  }: {
    onChange?: (value: { id: string; name: string } | null) => void
  }) => (
    <button
      type="button"
      data-testid="partner-picker"
      onClick={() => { onChange?.({ id: 'partner-99', name: 'Test Customer' }) }}
    >
      partner-picker
    </button>
  ),
}))

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('TransferVoucherModal', () => {
  const onClose = vi.fn()
  const onSubmit = vi.fn()

  beforeEach(() => {
    onClose.mockClear()
    onSubmit.mockClear()
  })

  it('renders the form with partner picker and reason textarea', () => {
    render(<TransferVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)
    expect(screen.getByTestId('partner-picker')).toBeInTheDocument()
    expect(screen.getByRole('textbox')).toBeInTheDocument()
  })

  it('rejects a 1-character reason and shows client-side error', async () => {
    render(<TransferVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    // Select a partner first to avoid the partner-required error masking the reason error
    fireEvent.click(screen.getByTestId('partner-picker'))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'A' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:transfer.action' }))

    await waitFor(() => {
      expect(screen.getByText('vouchers:validation.reasonMin')).toBeInTheDocument()
    })
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('rejects a 4-character reason and shows client-side error', async () => {
    render(<TransferVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    fireEvent.click(screen.getByTestId('partner-picker'))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'ABCD' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:transfer.action' }))

    await waitFor(() => {
      expect(screen.getByText('vouchers:validation.reasonMin')).toBeInTheDocument()
    })
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('accepts a 5-character reason with selected partner and calls onSubmit', async () => {
    render(<TransferVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    fireEvent.click(screen.getByTestId('partner-picker'))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'ABCDE' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:transfer.action' }))

    await waitFor(() => {
      expect(onSubmit).toHaveBeenCalledWith({ to_partner_id: 'partner-99', reason: 'ABCDE' })
    })
  })

  it('shows partner-required error if no partner selected before submit', async () => {
    render(<TransferVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Valid reason here' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:transfer.action' }))

    await waitFor(() => {
      expect(screen.getByText('vouchers:transfer.partnerRequired')).toBeInTheDocument()
    })
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('disables the submit button while isPending', () => {
    render(<TransferVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending />)
    expect(screen.getByRole('button', { name: 'common:saving' })).toBeDisabled()
  })
})
