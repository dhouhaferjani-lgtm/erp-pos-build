import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { SupportWindowForm } from '../components/SupportWindowForm'

describe('SupportWindowForm', () => {
  afterEach(() => { vi.useRealTimers() })

  it('refuses a window whose expiry is not after its start', async () => {
    const onSubmit = vi.fn()
    const user = userEvent.setup()
    render(<SupportWindowForm busy={false} maxWindowHours={168} onSubmit={onSubmit} />)

    fireEvent.change(screen.getByLabelText(/window starts/i), { target: { value: '2026-08-10T12:00' } })
    fireEvent.change(screen.getByLabelText(/window expires/i), { target: { value: '2026-08-10T11:00' } })
    await user.type(screen.getByLabelText(/support reason/i), 'Investigate a documented issue')
    await user.type(screen.getByLabelText(/ticket reference/i), 'SUP-VALIDATION')
    await user.click(screen.getByRole('button', { name: /create support window/i }))

    expect(onSubmit).not.toHaveBeenCalled()
    expect(screen.getByText(/expiry must be after the start/i)).toBeInTheDocument()
  })

  it('matches backend length and absolute configured-window limits', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    vi.setSystemTime(new Date('2026-08-07T08:00:00Z'))
    const onSubmit = vi.fn()
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    render(<SupportWindowForm busy={false} maxWindowHours={24} onSubmit={onSubmit} />)

    fireEvent.change(screen.getByLabelText(/window starts/i), { target: { value: '2026-08-08T06:00' } })
    fireEvent.change(screen.getByLabelText(/window expires/i), { target: { value: '2026-08-08T09:00' } })
    fireEvent.change(screen.getByLabelText(/support reason/i), { target: { value: 'r'.repeat(2001) } })
    fireEvent.change(screen.getByLabelText(/ticket reference/i), { target: { value: 'T'.repeat(101) } })
    await user.click(screen.getByRole('button', { name: /create support window/i }))

    expect(onSubmit).not.toHaveBeenCalled()
    expect(screen.getAllByText(/characters or fewer/i)).toHaveLength(2)
    expect(screen.getByText(/configured maximum window/i)).toBeInTheDocument()
  })
})
