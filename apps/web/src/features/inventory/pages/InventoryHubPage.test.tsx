import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { InventoryHubPage } from './InventoryHubPage'

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

const mockHasModule = vi.fn()
vi.mock('../../../contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({ hasModule: mockHasModule }),
}))

beforeEach(() => {
  mockCanAccessModule.mockReturnValue(true)
  mockHasModule.mockReturnValue(true)
})

describe('InventoryHubPage canonicalization', () => {
  it('renders a single canonical <h1> page title via PageHeader', () => {
    const { container } = render(<InventoryHubPage />)
    const h1s = container.querySelectorAll('h1')
    expect(h1s.length).toBe(1)
    expect(h1s[0].textContent).toContain('hub.title')
  })

  it('renders every card with the SAME icon-chip class (no per-card rainbow color)', () => {
    const { container } = render(<InventoryHubPage />)
    const chips = container.querySelectorAll('[data-testid="hub-card-icon-chip"]')
    expect(chips.length).toBeGreaterThan(0)
    const classes = new Set(Array.from(chips).map((c) => c.className))
    expect(classes.size).toBe(1)
  })

  it('renders the products card linking to /inventory/products', () => {
    render(<InventoryHubPage />)
    const link = screen.getByRole('link', { name: /hub\.cards\.products\.title/ })
    expect(link).toHaveAttribute('href', '/inventory/products')
  })

  it('hides permission-gated cards when the module is not accessible', () => {
    mockCanAccessModule.mockImplementation((m: string) => m !== 'pricing')
    render(<InventoryHubPage />)
    expect(screen.queryByText('hub.cards.priceLists.title')).not.toBeInTheDocument()
  })

  it('hides module-gated cards when the company module is disabled', () => {
    mockHasModule.mockReturnValue(false)
    render(<InventoryHubPage />)
    expect(screen.queryByText('hub.cards.compositeItems.title')).not.toBeInTheDocument()
  })
})
