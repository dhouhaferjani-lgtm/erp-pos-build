import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import {
  defaultCompanyConfig,
  parapharmacyCompanyConfig,
  genericWithParapharmacyExtraCompanyConfig,
} from '@/test/fixtures/companyConfig'
import { ProductForm } from '../ProductForm'

/**
 * Locks the module-based gate for the parapharmacy metadata section in
 * ProductForm: the section renders only when the Parapharmacy module is
 * enabled for the tenant (hasModule('Parapharmacy')), not for other verticals.
 */

// i18n: echo the key so we can assert on stable keys regardless of catalog state.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { language: 'en' },
  }),
}))

// Heavy data/mutation hooks are stubbed: this test only exercises the render gate.
vi.mock('../api/platformQueries', async () => {
  const actual = await vi.importActual<typeof import('../api/platformQueries')>(
    '../api/platformQueries'
  )
  return {
    ...actual,
    useProductSubmission: () => ({ mutateAsync: vi.fn(), isPending: false }),
  }
})

vi.mock('@/features/catalog/hooks/useVariants', () => ({
  useVariantsForProduct: () => ({ data: undefined, isLoading: false }),
}))

vi.mock('@/contexts/ProductConfigContext', async () => {
  const actual = await vi.importActual<typeof import('@/contexts/ProductConfigContext')>(
    '@/contexts/ProductConfigContext'
  )
  return {
    ...actual,
    useProductConfig: () => ({ isOtospex: false, product: 'izipos' }),
  }
})

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'EUR', locale: 'en', decimals: 2 }),
}))

vi.mock('@/hooks/useTaxConfigName', () => ({
  useTaxConfigName: () => null,
}))

// Avoid pulling tax-config network queries into this gate-focused test.
vi.mock('@/components/molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: () => null,
}))

const PARAPHARMACY_SECTION_KEY = 'products:parapharmacy.title'

describe('ProductForm parapharmacy module gate', () => {
  beforeEach(() => {
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
    vi.clearAllMocks()
  })

  it('renders the parapharmacy metadata section when the Parapharmacy module is enabled', async () => {
    renderWithProviders(<ProductForm />, {
      route: '/inventory/products/new',
      companyConfig: parapharmacyCompanyConfig,
    })

    await waitFor(() => {
      expect(screen.getByText(PARAPHARMACY_SECTION_KEY)).toBeInTheDocument()
    })
  })

  it('does NOT render the parapharmacy metadata section when the Parapharmacy module is disabled', async () => {
    renderWithProviders(<ProductForm />, {
      route: '/inventory/products/new',
      companyConfig: defaultCompanyConfig,
    })

    // Form name field renders for both verticals; wait for the form to mount.
    await waitFor(() => {
      expect(screen.getByText(/inventory:products\.name/)).toBeInTheDocument()
    })

    expect(screen.queryByText(PARAPHARMACY_SECTION_KEY)).not.toBeInTheDocument()
  })

  it('renders the section based on the module, not the vertical name (Parapharmacy enabled as an extra on a non-parapharmacy vertical)', async () => {
    renderWithProviders(<ProductForm />, {
      route: '/inventory/products/new',
      companyConfig: genericWithParapharmacyExtraCompanyConfig,
    })

    await waitFor(() => {
      expect(screen.getByText(PARAPHARMACY_SECTION_KEY)).toBeInTheDocument()
    })
  })
})
