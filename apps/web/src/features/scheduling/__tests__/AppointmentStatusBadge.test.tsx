import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { AppointmentStatusBadge } from '../components/atoms/AppointmentStatusBadge'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('AppointmentStatusBadge', () => {
  it('renders the localized status label', () => {
    render(<AppointmentStatusBadge status="checked_in" />)
    expect(screen.getByText('status.checked_in')).toBeInTheDocument()
  })

  it('applies status-specific styling', () => {
    const { container, rerender } = render(<AppointmentStatusBadge status="completed" />)
    const span = container.querySelector('span')
    expect(span?.className).toContain('emerald')

    rerender(<AppointmentStatusBadge status="no_show" />)
    const span2 = container.querySelector('span')
    expect(span2?.className).toContain('rose')

    rerender(<AppointmentStatusBadge status="in_progress" />)
    const span3 = container.querySelector('span')
    expect(span3?.className).toContain('violet')
  })
})
