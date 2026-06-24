import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { RelatedOperationsRail } from './RelatedOperationsRail'

// i18n is initialised globally in src/test/setup.ts (imports ../lib/i18n)

function renderRail(props: Partial<React.ComponentProps<typeof RelatedOperationsRail>> = {}) {
  return render(
    <MemoryRouter>
      <RelatedOperationsRail {...props} />
    </MemoryRouter>,
  )
}

describe('RelatedOperationsRail', () => {
  it('renders a "Related operations" heading', () => {
    renderRail()
    expect(screen.getByRole('heading', { name: /related operations/i })).toBeInTheDocument()
  })

  it('renders shortcut links to the existing operation routes', () => {
    renderRail()
    const links = screen.getAllByRole('link')
    const hrefs = links.map((l) => l.getAttribute('href'))
    expect(hrefs).toContain('/purchases/orders/new')
    expect(hrefs).toContain('/sales/quotes/new')
    expect(hrefs).toContain('/inventory/movements')
  })

  it('renders the shortcuts as non-link rows (disabled) in create mode', () => {
    renderRail({ disabled: true })
    expect(screen.queryAllByRole('link')).toHaveLength(0)
    // Rows are still listed so the user knows what becomes available after save.
    expect(screen.getByRole('heading', { name: /related operations/i })).toBeInTheDocument()
  })
})
