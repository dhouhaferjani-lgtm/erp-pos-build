import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { FinanceHubPage } from './FinanceHubPage'

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
const mockHasPermission = vi.fn()
vi.mock('../../../hooks/usePermissions', () => ({
  usePermissions: () => ({
    canAccessModule: mockCanAccessModule,
    hasPermission: mockHasPermission,
  }),
}))

beforeEach(() => {
  mockCanAccessModule.mockReturnValue(true)
  mockHasPermission.mockReturnValue(true)
})

describe('FinanceHubPage canonicalization', () => {
  it('renders a single canonical <h1> page title via PageHeader', () => {
    const { container } = render(<FinanceHubPage />)
    const h1s = container.querySelectorAll('h1')
    expect(h1s.length).toBe(1)
    expect(h1s[0].textContent).toContain('hub.title')
  })

  it('keeps the section headings (banking / accounting / reports)', () => {
    render(<FinanceHubPage />)
    expect(screen.getByText('hub.sections.bankingAndPayments')).toBeInTheDocument()
    expect(screen.getByText('hub.sections.accounting')).toBeInTheDocument()
    expect(screen.getByText('hub.sections.reports')).toBeInTheDocument()
  })

  it('renders every card with the SAME icon-chip class (no per-card rainbow color)', () => {
    const { container } = render(<FinanceHubPage />)
    const chips = container.querySelectorAll('[data-testid="hub-card-icon-chip"]')
    expect(chips.length).toBeGreaterThan(0)
    const classes = new Set(Array.from(chips).map((c) => c.className))
    expect(classes.size).toBe(1)
  })

  it('links to the treasury overview page from the banking and payments section', () => {
    render(<FinanceHubPage />)

    const link = screen.getByRole('link', {
      name: /hub\.cards\.treasuryOverview\.title/i,
    })
    expect(link).toHaveAttribute('href', '/finance/overview')
  })

  it('hides a whole section when none of its cards are accessible', () => {
    mockCanAccessModule.mockImplementation(
      (m: string) => m !== 'accounts' && m !== 'finance',
    )
    render(<FinanceHubPage />)
    // accounting + reports sections become empty → removed
    expect(screen.queryByText('hub.sections.accounting')).not.toBeInTheDocument()
    expect(screen.queryByText('hub.sections.reports')).not.toBeInTheDocument()
    expect(screen.getByText('hub.sections.bankingAndPayments')).toBeInTheDocument()
  })
})
