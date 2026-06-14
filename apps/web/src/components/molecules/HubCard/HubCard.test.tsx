import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { CreditCard, Package } from 'lucide-react'
import { describe, expect, it } from 'vitest'
import { tokens } from '@/lib/designTokens'
import { HubCard, HubGrid } from './HubCard'

describe('HubCard', () => {
  it('renders the title', () => {
    render(
      <MemoryRouter>
        <HubCard to="/finance" icon={CreditCard} title="Finance" />
      </MemoryRouter>
    )
    expect(screen.getByText('Finance')).toBeInTheDocument()
  })

  it('renders the description when provided', () => {
    render(
      <MemoryRouter>
        <HubCard
          to="/finance"
          icon={CreditCard}
          title="Finance"
          description="Manage accounts and ledgers"
        />
      </MemoryRouter>
    )
    expect(screen.getByText('Manage accounts and ledgers')).toBeInTheDocument()
  })

  it('does not render a description paragraph when omitted', () => {
    render(
      <MemoryRouter>
        <HubCard to="/finance" icon={CreditCard} title="Finance" />
      </MemoryRouter>
    )
    expect(screen.queryByText('Manage accounts and ledgers')).not.toBeInTheDocument()
  })

  it('renders a link pointing at the `to` path', () => {
    render(
      <MemoryRouter>
        <HubCard to="/inventory" icon={Package} title="Inventory" />
      </MemoryRouter>
    )
    const link = screen.getByRole('link')
    expect(link).toHaveAttribute('href', '/inventory')
  })

  it('renders the passed icon as an svg', () => {
    const { container } = render(
      <MemoryRouter>
        <HubCard to="/finance" icon={CreditCard} title="Finance" />
      </MemoryRouter>
    )
    expect(container.querySelector('svg')).toBeInTheDocument()
  })

  it('uses the SAME icon-chip class for every card regardless of icon (no per-card color)', () => {
    const { container: c1 } = render(
      <MemoryRouter>
        <HubCard to="/finance" icon={CreditCard} title="Finance" />
      </MemoryRouter>
    )
    const { container: c2 } = render(
      <MemoryRouter>
        <HubCard to="/inventory" icon={Package} title="Inventory" />
      </MemoryRouter>
    )

    const chip1 = c1.querySelector('[data-testid="hub-card-icon-chip"]')
    const chip2 = c2.querySelector('[data-testid="hub-card-icon-chip"]')

    expect(chip1).not.toBeNull()
    expect(chip2).not.toBeNull()
    // Both chips share the single brand chip style — proving no per-card rainbow color.
    expect(chip1?.className).toBe(chip2?.className)
    // The chip uses the shared brand/info token, not a per-card literal color.
    expect(chip1?.className).toContain(tokens.alert.info)
  })

  it('merges a passed className onto the card link', () => {
    render(
      <MemoryRouter>
        <HubCard
          to="/finance"
          icon={CreditCard}
          title="Finance"
          className="custom-card"
        />
      </MemoryRouter>
    )
    expect(screen.getByRole('link')).toHaveClass('custom-card')
  })
})

describe('HubGrid', () => {
  it('renders its children', () => {
    render(
      <MemoryRouter>
        <HubGrid>
          <HubCard to="/finance" icon={CreditCard} title="Finance" />
          <HubCard to="/inventory" icon={Package} title="Inventory" />
        </HubGrid>
      </MemoryRouter>
    )
    expect(screen.getByText('Finance')).toBeInTheDocument()
    expect(screen.getByText('Inventory')).toBeInTheDocument()
    expect(screen.getAllByRole('link')).toHaveLength(2)
  })
})
