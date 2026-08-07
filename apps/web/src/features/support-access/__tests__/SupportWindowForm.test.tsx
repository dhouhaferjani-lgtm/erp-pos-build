import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { SupportWindowForm } from '../components/SupportWindowForm'

describe('SupportWindowForm', () => {
  it('refuses a window whose expiry is not after its start', async () => {
    const onSubmit = vi.fn()
    const user = userEvent.setup()
    render(<SupportWindowForm busy={false} onSubmit={onSubmit} />)

    fireEvent.change(screen.getByLabelText(/window starts/i), { target: { value: '2026-08-10T12:00' } })
    fireEvent.change(screen.getByLabelText(/window expires/i), { target: { value: '2026-08-10T11:00' } })
    await user.type(screen.getByLabelText(/support reason/i), 'Investigate a documented issue')
    await user.type(screen.getByLabelText(/ticket reference/i), 'SUP-VALIDATION')
    await user.click(screen.getByRole('button', { name: /create support window/i }))

    expect(onSubmit).not.toHaveBeenCalled()
    expect(screen.getByText(/expiry must be after the start/i)).toBeInTheDocument()
  })
})
