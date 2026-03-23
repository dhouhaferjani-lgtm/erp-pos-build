import { render, screen } from '@testing-library/react'
import { VatPeriodStatusBadge } from '../VatPeriodStatusBadge'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const translations: Record<string, string> = {
        'finance:vatReporting.status.open': 'Open',
        'finance:vatReporting.status.closed': 'Closed',
        'finance:vatReporting.status.filed': 'Filed',
      }
      return translations[key] ?? key
    },
  }),
}))

describe('VatPeriodStatusBadge', () => {
  it('renders "Open" with green styling for OPEN status', () => {
    render(<VatPeriodStatusBadge status="OPEN" />)
    const badge = screen.getByText('Open')
    expect(badge).toBeInTheDocument()
    expect(badge).toHaveClass('bg-green-100', 'text-green-800')
  })

  it('renders "Closed" with amber/yellow styling for CLOSED status', () => {
    render(<VatPeriodStatusBadge status="CLOSED" />)
    const badge = screen.getByText('Closed')
    expect(badge).toBeInTheDocument()
    expect(badge).toHaveClass('bg-yellow-100', 'text-yellow-800')
  })

  it('renders "Filed" with blue styling for FILED status', () => {
    render(<VatPeriodStatusBadge status="FILED" />)
    const badge = screen.getByText('Filed')
    expect(badge).toBeInTheDocument()
    expect(badge).toHaveClass('bg-blue-100', 'text-blue-800')
  })
})
