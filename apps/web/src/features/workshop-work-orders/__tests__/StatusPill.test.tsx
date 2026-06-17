import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { StatusPill } from '../components/StatusPill'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('StatusPill', () => {
  it('renders the localized status label', () => {
    render(<StatusPill status="in_progress" />)
    expect(screen.getByText('status.in_progress')).toBeInTheDocument()
  })

  it('applies the canonical success tone for approved and danger tone for cancelled', () => {
    const { container, rerender } = render(<StatusPill status="approved" />)
    const approvedSpan = container.querySelector('span')
    // Canonical StatusBadge success tone resolves to the green alert palette.
    expect(approvedSpan?.className).toContain('green')
    expect(approvedSpan?.className).not.toContain('red')

    rerender(<StatusPill status="cancelled" />)
    const cancelledSpan = container.querySelector('span')
    // Canonical StatusBadge danger tone resolves to the red alert palette.
    expect(cancelledSpan?.className).toContain('red')
    expect(cancelledSpan?.className).not.toContain('green')
  })
})
