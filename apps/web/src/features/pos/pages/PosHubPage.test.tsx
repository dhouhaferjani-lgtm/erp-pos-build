import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PosHubPage } from './PosHubPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('../../../hooks/usePageTitle', () => ({
  usePageTitle: vi.fn(),
}))

const mockCanAccessModule = vi.fn()
vi.mock('../../../hooks/usePermissions', () => ({
  usePermissions: () => ({ canAccessModule: mockCanAccessModule }),
}))

beforeEach(() => {
  mockCanAccessModule.mockReturnValue(true)
})

describe('PosHubPage canonicalization', () => {
  it('renders a single canonical <h1> page title via PageHeader', () => {
    const { container } = render(<PosHubPage />)
    const h1s = container.querySelectorAll('h1')
    expect(h1s.length).toBe(1)
    expect(h1s[0].textContent).toContain('hub.title')
  })

  it('keeps the prominent Open POS call-to-action linking to /pos/transactions', () => {
    render(<PosHubPage />)
    const openPos = screen.getByRole('link', { name: /hub\.openPos/ })
    expect(openPos).toHaveAttribute('href', '/pos/transactions')
  })

  it('renders every grid card with the SAME icon-chip class (no per-card rainbow color)', () => {
    const { container } = render(<PosHubPage />)
    const chips = container.querySelectorAll('[data-testid="hub-card-icon-chip"]')
    expect(chips.length).toBeGreaterThan(0)
    const classes = new Set(Array.from(chips).map((c) => c.className))
    expect(classes.size).toBe(1)
  })

  it('hides permission-gated cards when the pos module is not accessible', () => {
    mockCanAccessModule.mockReturnValue(false)
    render(<PosHubPage />)
    expect(screen.queryByText('hub.cards.orders.title')).not.toBeInTheDocument()
  })
})
