import { render, screen } from '@testing-library/react'
import { StatusBadge } from '../StatusBadge'
import type { StatusTone } from '@/components/atoms/StatusBadge'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@/components/atoms/StatusBadge', () => ({
  StatusBadge: ({ tone, children }: { tone: StatusTone; children: React.ReactNode }) => (
    <span data-tone={tone}>{children}</span>
  ),
  statusTone: (status: string, overrides: Record<string, StatusTone>) => overrides[status] ?? 'neutral',
}))

describe('Voucher StatusBadge', () => {
  it.each([
    ['Issued', 'success'],
    ['PartiallyRedeemed', 'info'],
    ['FullyRedeemed', 'neutral'],
    ['Voided', 'danger'],
    ['Expired', 'warning'],
  ] as const)('renders %s with %s tone and unchanged i18n key', (status, tone) => {
    render(<StatusBadge status={status} />)

    const badge = screen.getByText(`statuses.${status}`)
    expect(badge).toHaveAttribute('data-tone', tone)
  })
})
