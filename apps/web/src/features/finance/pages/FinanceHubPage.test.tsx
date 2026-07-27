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
const mockScope = { current: 'all' as 'all' | string[] }
vi.mock('../../../hooks/usePermissions', () => ({
  usePermissions: () => ({
    canAccessModule: mockCanAccessModule,
    hasPermission: mockHasPermission,
  }),
}))

vi.mock('@/features/locations/hooks/useViewScope', () => ({
  useViewScope: () => ({
    scope: mockScope.current,
    effectiveLocationIds: mockScope.current === 'all' ? [] : mockScope.current,
    isAll: mockScope.current === 'all',
    setScope: vi.fn(),
  }),
}))

beforeEach(() => {
  mockScope.current = 'all'
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

  it('links bank reconciliation to the statement workspace', () => {
    render(<FinanceHubPage />)

    const link = screen.getByRole('link', {
      name: /hub\.cards\.bankReconciliation\.title/i,
    })
    expect(link).toHaveAttribute('href', '/treasury/statements')
  })

  it('hides the statement workspace card without bank statement view permission', () => {
    mockHasPermission.mockImplementation((permission: string) => permission !== 'bank-statements.view')
    render(<FinanceHubPage />)

    expect(screen.queryByRole('link', {
      name: /hub\.cards\.bankReconciliation\.title/i,
    })).not.toBeInTheDocument()
    expect(mockHasPermission).toHaveBeenCalledWith('bank-statements.view')
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

  it('preserves a selected location scope in finance deep links', () => {
    mockScope.current = ['loc-a']
    render(<FinanceHubPage />)

    expect(screen.getByRole('link', { name: /hub\.cards\.treasuryOverview\.title/i })).toHaveAttribute(
      'href',
      '/finance/overview?location_ids%5B%5D=loc-a',
    )
  })
})
