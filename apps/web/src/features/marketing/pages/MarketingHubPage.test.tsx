import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MarketingHubPage } from './MarketingHubPage'

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

describe('MarketingHubPage canonicalization', () => {
  it('renders a single canonical <h1> page title via PageHeader', () => {
    const { container } = render(<MarketingHubPage />)
    const h1s = container.querySelectorAll('h1')
    expect(h1s.length).toBe(1)
    expect(h1s[0].textContent).toContain('hub.title')
  })

  it('renders every card with the SAME icon-chip class (no per-card rainbow color)', () => {
    const { container } = render(<MarketingHubPage />)
    const chips = container.querySelectorAll('[data-testid="hub-card-icon-chip"]')
    expect(chips.length).toBeGreaterThan(0)
    const classes = new Set(Array.from(chips).map((c) => c.className))
    expect(classes.size).toBe(1)
  })

  it('renders the promotions card linking to /pos/promotions', () => {
    render(<MarketingHubPage />)
    const link = screen.getByRole('link', { name: /hub\.cards\.promotions\.title/ })
    expect(link).toHaveAttribute('href', '/pos/promotions')
  })

  it('hides permission-gated cards when the module is not accessible', () => {
    mockCanAccessModule.mockImplementation((m: string) => m !== 'loyalty')
    render(<MarketingHubPage />)
    expect(screen.queryByText('hub.cards.loyaltyPrograms.title')).not.toBeInTheDocument()
  })
})
