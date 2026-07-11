import { render, screen } from '@testing-library/react'
import { TableStatusBadge } from './TableStatusBadge'
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

describe('TableStatusBadge', () => {
  it.each([
    ['available', 'success'],
    ['occupied', 'danger'],
    ['reserved', 'info'],
    ['cleaning', 'warning'],
    ['unknown', 'neutral'],
  ] as const)('renders %s with %s tone and unchanged i18n key', (status, tone) => {
    render(<TableStatusBadge status={status} />)

    const badge = screen.getByText(`tables.status.${status}`)
    expect(badge).toHaveAttribute('data-tone', tone)
  })
})
