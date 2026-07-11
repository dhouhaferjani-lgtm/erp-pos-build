import { render, screen } from '@testing-library/react'
import { VatPeriodStatusBadge } from '../VatPeriodStatusBadge'
import type { StatusTone } from '@/components/atoms/StatusBadge'

vi.mock('@/components/atoms/StatusBadge', () => ({
  StatusBadge: ({ tone, children }: { tone: StatusTone; children: React.ReactNode }) => (
    <span data-tone={tone}>{children}</span>
  ),
  statusTone: (status: string, overrides: Record<string, StatusTone>) => overrides[status] ?? 'neutral',
}))

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
  it('renders "Open" with success tone for OPEN status', () => {
    render(<VatPeriodStatusBadge status="OPEN" />)
    const badge = screen.getByText('Open')
    expect(badge).toBeInTheDocument()
    expect(badge).toHaveAttribute('data-tone', 'success')
  })

  it('renders "Closed" with warning tone for CLOSED status', () => {
    render(<VatPeriodStatusBadge status="CLOSED" />)
    const badge = screen.getByText('Closed')
    expect(badge).toBeInTheDocument()
    expect(badge).toHaveAttribute('data-tone', 'warning')
  })

  it('renders "Filed" with info tone for FILED status', () => {
    render(<VatPeriodStatusBadge status="FILED" />)
    const badge = screen.getByText('Filed')
    expect(badge).toBeInTheDocument()
    expect(badge).toHaveAttribute('data-tone', 'info')
  })
})
