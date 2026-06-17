import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { defaultCompanyConfig, pharmacyCompanyConfig } from '@/test/fixtures/companyConfig'
import { ProductForm } from '../ProductForm'

/**
 * Locks the module-based gate for the batch/expiry tracking section in
 * ProductForm: the "Track batches / expiry" checkbox (bound to
 * products.requires_batch_tracking) renders only when the tenant has a
 * stock-tracking module — `hasModule('BatchExpiry') || hasModule('Inventory')`.
 * On a tenant with neither module the section is meaningless and stays hidden.
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

const BATCH_TRACKING_LABEL_KEY = 'inventory:products.requiresBatchTracking'

describe('ProductForm batch-tracking module gate', () => {
  beforeEach(() => {
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
    vi.clearAllMocks()
  })

  it('renders the batch/expiry section when the tenant has a stock-tracking module (Inventory)', async () => {
    renderWithProviders(<ProductForm />, {
      route: '/inventory/products/new',
      companyConfig: defaultCompanyConfig,
    })

    await waitFor(() => {
      expect(screen.getByTestId('batch-tracking-section')).toBeInTheDocument()
    })
    expect(screen.getByText(BATCH_TRACKING_LABEL_KEY)).toBeInTheDocument()
  })

  it('does NOT render the batch/expiry section when the tenant has neither BatchExpiry nor Inventory', async () => {
    renderWithProviders(<ProductForm />, {
      route: '/inventory/products/new',
      companyConfig: pharmacyCompanyConfig,
    })

    // Form name field renders regardless; wait for the form to mount.
    await waitFor(() => {
      expect(screen.getByText(/inventory:products\.name/)).toBeInTheDocument()
    })

    expect(screen.queryByTestId('batch-tracking-section')).not.toBeInTheDocument()
    expect(screen.queryByText(BATCH_TRACKING_LABEL_KEY)).not.toBeInTheDocument()
  })
})
