import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { ExtendExpiryModal } from '../ExtendExpiryModal'

// ─── i18n mock ────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Returns an ISO date string N days from today, e.g. "2026-05-05" */
function futureDate(daysAhead: number): string {
  const d = new Date()
  d.setDate(d.getDate() + daysAhead)
  return d.toISOString().split('T')[0]
}

/** Returns an ISO date string N days before today */
function pastDate(daysBehind: number): string {
  const d = new Date()
  d.setDate(d.getDate() - daysBehind)
  return d.toISOString().split('T')[0]
}

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('ExtendExpiryModal', () => {
  const onClose = vi.fn()
  const onSubmit = vi.fn()

  beforeEach(() => {
    onClose.mockClear()
    onSubmit.mockClear()
  })

  it('renders the form with date input and reason textarea', () => {
    render(<ExtendExpiryModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)
    expect(document.querySelector('input[type="date"]')).toBeInTheDocument()
    const textboxes = screen.getAllByRole('textbox')
    expect(textboxes.length).toBeGreaterThan(0)
  })

  it('rejects a past date and shows client-side error', async () => {
    render(<ExtendExpiryModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    const inputs = screen.getAllByRole('textbox')
    // The date field is type="date" — accessible via its value, find first
    const dateInput = document.querySelector('input[type="date"]') as HTMLInputElement
    fireEvent.change(dateInput, { target: { value: pastDate(1) } })
    fireEvent.change(inputs[inputs.length - 1], { target: { value: 'Valid reason here' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:extend.action' }))

    await waitFor(() => {
      expect(screen.getByText('vouchers:validation.dateInFuture')).toBeInTheDocument()
    })
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('rejects today as a past date (not strictly in the future)', async () => {
    render(<ExtendExpiryModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    const dateInput = document.querySelector('input[type="date"]') as HTMLInputElement
    const today = new Date().toISOString().split('T')[0]
    fireEvent.change(dateInput, { target: { value: today } })
    const inputs = screen.getAllByRole('textbox')
    fireEvent.change(inputs[inputs.length - 1], { target: { value: 'Valid reason here' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:extend.action' }))

    await waitFor(() => {
      expect(screen.getByText('vouchers:validation.dateInFuture')).toBeInTheDocument()
    })
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('rejects a 1-character reason and shows client-side error', async () => {
    render(<ExtendExpiryModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    const dateInput = document.querySelector('input[type="date"]') as HTMLInputElement
    fireEvent.change(dateInput, { target: { value: futureDate(30) } })
    const inputs = screen.getAllByRole('textbox')
    fireEvent.change(inputs[inputs.length - 1], { target: { value: 'A' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:extend.action' }))

    await waitFor(() => {
      expect(screen.getByText('vouchers:validation.reasonMin')).toBeInTheDocument()
    })
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('rejects a 4-character reason and shows client-side error', async () => {
    render(<ExtendExpiryModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    const dateInput = document.querySelector('input[type="date"]') as HTMLInputElement
    fireEvent.change(dateInput, { target: { value: futureDate(30) } })
    const inputs = screen.getAllByRole('textbox')
    fireEvent.change(inputs[inputs.length - 1], { target: { value: 'ABCD' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:extend.action' }))

    await waitFor(() => {
      expect(screen.getByText('vouchers:validation.reasonMin')).toBeInTheDocument()
    })
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('accepts a future date with a 5+ character reason and calls onSubmit', async () => {
    render(<ExtendExpiryModal isOpen onClose={onClose} onSubmit={onSubmit} isPending={false} />)

    const future = futureDate(30)
    const dateInput = document.querySelector('input[type="date"]') as HTMLInputElement
    fireEvent.change(dateInput, { target: { value: future } })
    const inputs = screen.getAllByRole('textbox')
    fireEvent.change(inputs[inputs.length - 1], { target: { value: 'ABCDE' } })
    fireEvent.click(screen.getByRole('button', { name: 'vouchers:extend.action' }))

    await waitFor(() => {
      expect(onSubmit).toHaveBeenCalledWith({ new_expires_at: future, reason: 'ABCDE' })
    })
  })

  it('disables the submit button while isPending', () => {
    render(<ExtendExpiryModal isOpen onClose={onClose} onSubmit={onSubmit} isPending />)
    expect(screen.getByRole('button', { name: 'common:saving' })).toBeDisabled()
  })
})
