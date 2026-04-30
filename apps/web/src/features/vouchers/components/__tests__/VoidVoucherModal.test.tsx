import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { VoidVoucherModal } from '../VoidVoucherModal'

// ─── i18n mock ────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('VoidVoucherModal', () => {
  const onClose = vi.fn()
  const onSubmit = vi.fn()

  beforeEach(() => {
    onClose.mockClear()
    onSubmit.mockClear()
  })

  it('renders the form with reason textarea', () => {
    render(<VoidVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)
    expect(screen.getByRole('textbox')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'vouchers:void.action' })).toBeInTheDocument()
  })

  it('rejects a 1-character reason and shows client-side error', async () => {
    render(<VoidVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'A' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:void.action' }))

    await waitFor(() => {
      expect(screen.getByText('vouchers:validation.reasonMin')).toBeInTheDocument()
    })
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('rejects a 4-character reason and shows client-side error', async () => {
    render(<VoidVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'ABCD' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:void.action' }))

    await waitFor(() => {
      expect(screen.getByText('vouchers:validation.reasonMin')).toBeInTheDocument()
    })
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('accepts a 5-character reason and calls onSubmit', async () => {
    render(<VoidVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'ABCDE' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:void.action' }))

    await waitFor(() => {
      expect(onSubmit).toHaveBeenCalledWith({ reason: 'ABCDE' })
    })
  })

  it('disables the submit button while isPending', () => {
    render(<VoidVoucherModal isOpen onClose={onClose} onSubmit={onSubmit} isPending />)
    expect(screen.getByRole('button', { name: 'common:saving' })).toBeDisabled()
  })
})
