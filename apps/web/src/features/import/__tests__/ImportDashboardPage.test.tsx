import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import {
  defaultCompanyConfig,
  parapharmacyCompanyConfig,
} from '@/test/fixtures/companyConfig'
import { ImportDashboardPage } from '../pages/ImportDashboardPage'

vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfig: vi.fn(),
}))

const mockUseCompanyConfig = vi.mocked(useCompanyConfig)

function renderDashboard(initialEntry = '/settings/import') {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <ImportDashboardPage />
    </MemoryRouter>
  )
}

describe('ImportDashboardPage', () => {
  beforeEach(() => {
    mockUseCompanyConfig.mockReturnValue({
      config: defaultCompanyConfig,
      isLoading: false,
      error: null,
      hasModule: (moduleName: string) => moduleName === 'Inventory',
    })
  })

  it('shows parties and advanced imports for non-parapharmacy companies', () => {
    renderDashboard()

    expect(screen.getByRole('heading', { name: 'Business partners' })).toBeInTheDocument()
    expect(screen.getByText('Import business partners first, then products.')).toBeInTheDocument()
    expect(screen.getByText('Advanced imports')).toBeInTheDocument()
  })

  it('hides advanced imports for parapharmacy while keeping parties visible', () => {
    mockUseCompanyConfig.mockReturnValue({
      config: parapharmacyCompanyConfig,
      isLoading: false,
      error: null,
      hasModule: (moduleName: string) => moduleName === 'Inventory',
    })

    renderDashboard()

    expect(screen.getByRole('heading', { name: 'Business partners' })).toBeInTheDocument()
    expect(screen.queryByText('Advanced imports')).not.toBeInTheDocument()
  })

  /**
   * Register G-9 (c). PartnerListPage and ProductListPage sent the user here
   * with `?entity=…`, and this page never read searchParams — the parameter was
   * inert, so "Import products" dropped the user on an undifferentiated card
   * list. Read it and mark the card the user asked for.
   */
  it('marks the products card as current when arrived with ?entity=products', () => {
    renderDashboard('/settings/import?entity=products')

    const current = screen.getAllByRole('link', { current: 'page' })

    expect(current).toHaveLength(1)
    expect(current[0]).toHaveAttribute('href', '/settings/import/products')
  })

  it('maps the partner-list entities onto the parties card', () => {
    for (const entity of ['customers', 'suppliers']) {
      const { unmount } = renderDashboard(`/settings/import?entity=${entity}`)

      const current = screen.getAllByRole('link', { current: 'page' })

      expect(current).toHaveLength(1)
      expect(current[0]).toHaveAttribute('href', '/settings/import/parties')

      unmount()
    }
  })

  it('opens the advanced section when the requested card lives inside it', () => {
    const { container } = renderDashboard('/settings/import?entity=composite_items')

    expect(container.querySelector('details')).toHaveAttribute('open')
    expect(screen.getByRole('link', { current: 'page' })).toHaveAttribute(
      'href',
      '/settings/import/composite_items'
    )
  })

  it('marks nothing when no entity is requested', () => {
    renderDashboard()

    expect(screen.queryAllByRole('link', { current: 'page' })).toHaveLength(0)
  })

  it('marks nothing for an unrecognised entity', () => {
    renderDashboard('/settings/import?entity=not-a-thing')

    expect(screen.queryAllByRole('link', { current: 'page' })).toHaveLength(0)
  })
})
