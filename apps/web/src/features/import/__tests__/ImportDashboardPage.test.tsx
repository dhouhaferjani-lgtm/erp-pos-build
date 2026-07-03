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

function renderDashboard() {
  return render(
    <MemoryRouter>
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
})
