import { render, screen } from '@testing-library/react'
import { BatchStatusBadge } from './BatchStatusBadge'
import type { ExpiryStatus } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback ?? key,
  }),
}))

describe('BatchStatusBadge', () => {
  it.each([
    'OK',
    'APPROACHING',
    'WARNING',
    'CRITICAL',
    'EXPIRED',
  ] satisfies ExpiryStatus[])('renders %s with unchanged i18n key', (status) => {
    render(<BatchStatusBadge status={status} />)

    expect(screen.getByText(`batches:expiryStatus.${status.toLowerCase()}`)).toBeInTheDocument()
  })

  it('keeps the days-remaining suffix for non-expired batches', () => {
    render(<BatchStatusBadge status="WARNING" daysUntilExpiry={12} />)

    expect(screen.getByText('(12 days)')).toBeInTheDocument()
  })

  it('does not render days remaining for expired batches', () => {
    render(<BatchStatusBadge status="EXPIRED" daysUntilExpiry={-1} />)

    expect(screen.queryByText(/days/)).not.toBeInTheDocument()
  })
})
