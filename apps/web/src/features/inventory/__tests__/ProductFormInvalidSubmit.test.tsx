import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { parapharmacyCompanyConfig } from '@/test/fixtures/companyConfig'
import { ProductForm } from '../ProductForm'

/**
 * BUG-003 — clicking "Enregistrer" on the product form fired zero network
 * requests, showed no toast and did not move the page. Client-side validation
 * was blocking on a required field rendered BELOW the fold (proven on staging:
 * the required "Product Category" in the Parapharmacy section), surfaced only
 * as inline text the operator never saw.
 *
 * The fix is a GENERIC mechanism on the form's react-hook-form `onInvalid`
 * handler — an error toast plus a scroll to (and focus on) the first invalid
 * field in DOM order — not a special case for one field.
 */

// i18n: echo the key so assertions are on stable keys, not catalog text.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { language: 'en' },
  }),
}))

const mockToast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('sonner', () => ({ toast: mockToast }))

const mockApiPost = vi.hoisted(() => vi.fn())
vi.mock('../../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../../lib/api')>('../../../lib/api')
  return { ...actual, apiPost: mockApiPost }
})

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
  return { ...actual, useProductConfig: () => ({ isOtospex: false, product: 'izipos' }) }
})

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'EUR', locale: 'en', decimals: 2 }),
  getDecimals: () => 2,
}))

vi.mock('@/hooks/useTaxConfigName', () => ({ useTaxConfigName: () => null }))

vi.mock('@/components/molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: () => null,
}))

describe('ProductForm blocked submit feedback (BUG-003)', () => {
  let scrollIntoView: ReturnType<typeof vi.fn>

  beforeEach(() => {
    seedAuth()
    // jsdom does not implement scrollIntoView at all.
    scrollIntoView = vi.fn()
    window.HTMLElement.prototype.scrollIntoView = scrollIntoView
  })

  afterEach(() => {
    resetAuth()
    vi.clearAllMocks()
  })

  it('shows an error toast and scrolls to the first invalid field when validation blocks the save', async () => {
    const user = userEvent.setup()

    renderWithProviders(<ProductForm />, {
      route: '/inventory/products/new',
      companyConfig: parapharmacyCompanyConfig,
    })

    await waitFor(() => {
      expect(screen.getByText('products:parapharmacy.title')).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: 'catalog:editor.actions.save' }))

    await waitFor(() => {
      expect(mockToast.error).toHaveBeenCalledWith('inventory:products.validationBlocked')
    })
    expect(mockApiPost).not.toHaveBeenCalled()
    expect(scrollIntoView).toHaveBeenCalled()
  })

  it('scrolls to and focuses the BELOW-THE-FOLD required field when the visible fields are filled', async () => {
    const user = userEvent.setup()

    renderWithProviders(<ProductForm />, {
      route: '/inventory/products/new',
      companyConfig: parapharmacyCompanyConfig,
    })

    await waitFor(() => {
      expect(screen.getByText('products:parapharmacy.title')).toBeInTheDocument()
    })

    // Fill everything the operator can see at the top of the form. The only
    // remaining required field is the parapharmacy category, below the fold.
    await user.type(screen.getByLabelText(/inventory:products\.name/), 'Doliprane 500mg')
    await user.type(screen.getByLabelText(/inventory:products\.sku/), 'DOL-500')

    await user.click(screen.getByRole('button', { name: 'catalog:editor.actions.save' }))

    await waitFor(() => {
      expect(mockToast.error).toHaveBeenCalledWith('inventory:products.validationBlocked')
    })

    const categoryField = document.querySelector<HTMLElement>(
      '[name="parapharmacy_metadata.category"]',
    )
    expect(categoryField).not.toBeNull()
    expect(document.activeElement).toBe(categoryField)
    expect(scrollIntoView).toHaveBeenCalled()
  })
})
