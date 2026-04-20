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

  it('applies status-specific styling for each WorkOrderStatus', () => {
    const { container, rerender } = render(<StatusPill status="approved" />)
    const approvedSpan = container.querySelector('span')
    expect(approvedSpan?.className).toContain('emerald')

    rerender(<StatusPill status="cancelled" />)
    const cancelledSpan = container.querySelector('span')
    expect(cancelledSpan?.className).toContain('rose')
  })
})
