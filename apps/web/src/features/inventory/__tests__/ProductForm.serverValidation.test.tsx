import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, type AxiosResponse } from 'axios'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { defaultCompanyConfig } from '@/test/fixtures/companyConfig'
import { ProductForm } from '../ProductForm'

/**
 * DEV-QA-026 (duplicate SKU) / DEV-QA-056 (invalid UOM):
 * the backend returns a 422 with field errors, but ProductForm swallowed it
 * (only `reportBarcodeConflict` ran → returned false → nothing happened).
 * The fix surfaces the 422 on the matching field.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key, i18n: { language: 'en' } }),
}))

const mockToast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('sonner', () => ({ toast: mockToast }))

const mockApiPost = vi.hoisted(() => vi.fn())
vi.mock('../../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../../lib/api')>('../../../lib/api')
  return { ...actual, apiPost: mockApiPost }
})

vi.mock('../api/platformQueries', async () => {
  const actual = await vi.importActual<typeof import('../api/platformQueries')>('../api/platformQueries')
  return { ...actual, useProductSubmission: () => ({ mutateAsync: vi.fn(), isPending: false }) }
})

vi.mock('@/features/catalog/hooks/useVariants', () => ({
  useVariantsForProduct: () => ({ data: undefined, isLoading: false }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'EUR', locale: 'en', decimals: 2 }),
  getDecimals: () => 2,
}))

vi.mock('@/hooks/useTaxConfigName', () => ({ useTaxConfigName: () => null }))
vi.mock('@/components/molecules/TaxConfigurationField', () => ({ TaxConfigurationField: () => null }))

function make422(errors: Record<string, string[]>): AxiosError {
  const err = new AxiosError('Request failed with status code 422', 'ERR_BAD_REQUEST')
  err.response = {
    status: 422,
    statusText: 'Unprocessable Content',
    headers: {},
    config: {} as AxiosResponse['config'],
    data: { error: { code: 'VALIDATION_ERROR', message: 'Validation failed', errors } },
  }
  return err
}

async function fillAndSave(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/inventory:products\.name/), 'Widget')
  await user.type(screen.getByLabelText(/inventory:products\.sku/), 'SKU-DUP')
  await user.click(screen.getByRole('button', { name: 'catalog:editor.actions.save' }))
}

beforeEach(() => {
  seedAuth()
  window.HTMLElement.prototype.scrollIntoView = vi.fn()
})

afterEach(() => {
  resetAuth()
  vi.clearAllMocks()
})

describe('ProductForm server validation surfacing', () => {
  it('DEV-QA-026: shows a clear "SKU already exists" error instead of failing silently', async () => {
    const user = userEvent.setup()
    mockApiPost.mockRejectedValue(make422({ sku: ['The sku has already been taken.'] }))

    renderWithProviders(<ProductForm />, { route: '/inventory/products/new', companyConfig: defaultCompanyConfig })
    await waitFor(() => expect(screen.getByLabelText(/inventory:products\.sku/)).toBeInTheDocument())

    await fillAndSave(user)

    expect(await screen.findByText('The sku has already been taken.')).toBeInTheDocument()
    expect(mockApiPost).toHaveBeenCalled()
  })

  it('DEV-QA-056: surfaces an invalid-UOM 422 on the unit-of-measure field', async () => {
    const user = userEvent.setup()
    mockApiPost.mockRejectedValue(make422({ unit_id: ['The selected unit id is invalid.'] }))

    renderWithProviders(<ProductForm />, { route: '/inventory/products/new', companyConfig: defaultCompanyConfig })
    await waitFor(() => expect(screen.getByLabelText(/inventory:products\.sku/)).toBeInTheDocument())

    await fillAndSave(user)

    expect(await screen.findByText('The selected unit id is invalid.')).toBeInTheDocument()
  })
})
