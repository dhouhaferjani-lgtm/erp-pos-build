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
 * Locks the vertical-based gate for the parapharmacy metadata section in
 * ProductForm: the section renders only when the tenant vertical is
 * `parapharmacy` (config.vertical === 'parapharmacy'), matching the backend
 * which authorizes parapharmacy metadata by vertical (not by module). Enabling
 * the Parapharmacy module on another vertical must NOT show the section,
 * because the API would 422-reject the metadata.
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
  getDecimals: () => 2,
}))

vi.mock('@/hooks/useTaxConfigName', () => ({
  useTaxConfigName: () => null,
}))

// Avoid pulling tax-config network queries into this gate-focused test.
vi.mock('@/components/molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: () => null,
}))

const PARAPHARMACY_SECTION_KEY = 'products:parapharmacy.title'

describe('ProductForm parapharmacy vertical gate', () => {
  beforeEach(() => {
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
    vi.clearAllMocks()
  })

  it('renders the parapharmacy metadata section when the tenant vertical is parapharmacy', async () => {
    renderWithProviders(<ProductForm />, {
      route: '/inventory/products/new',
      companyConfig: parapharmacyCompanyConfig,
    })

    await waitFor(() => {
      expect(screen.getByText(PARAPHARMACY_SECTION_KEY)).toBeInTheDocument()
    })
  })

  it('does NOT render the parapharmacy metadata section on a non-parapharmacy vertical', async () => {
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

  it('does NOT render the section based on the module when the vertical is not parapharmacy (Parapharmacy enabled as an extra on a non-parapharmacy vertical)', async () => {
    renderWithProviders(<ProductForm />, {
      route: '/inventory/products/new',
      companyConfig: genericWithParapharmacyExtraCompanyConfig,
    })

    // Form name field renders for both verticals; wait for the form to mount.
    await waitFor(() => {
      expect(screen.getByText(/inventory:products\.name/)).toBeInTheDocument()
    })

    // Vertical-based gate: the module being enabled as an extra is not enough —
    // the backend authorizes parapharmacy metadata by vertical and would
    // 422-reject it here, so the section must stay hidden.
    expect(screen.queryByText(PARAPHARMACY_SECTION_KEY)).not.toBeInTheDocument()
  })
})
