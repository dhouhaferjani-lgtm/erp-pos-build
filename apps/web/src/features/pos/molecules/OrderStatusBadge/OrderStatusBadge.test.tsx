import { render, screen } from '@testing-library/react'
import { OrderStatusBadge } from './OrderStatusBadge'
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

describe('OrderStatusBadge', () => {
  it.each([
    ['open', 'info'],
    ['sent_to_kitchen', 'warning'],
    ['ready', 'success'],
    ['closed', 'neutral'],
    ['cancelled', 'danger'],
  ] as const)('renders %s with %s tone and unchanged i18n key', (status, tone) => {
    render(<OrderStatusBadge status={status} />)

    const badge = screen.getByText(`orders.status.${status}`)
    expect(badge).toHaveAttribute('data-tone', tone)
  })
})
