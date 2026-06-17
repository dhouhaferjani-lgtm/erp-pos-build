import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ProductForm } from './ProductForm'

// i18n → return the key (string 2nd arg = default value)
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

// router
const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => ({ id: '' }),
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

// decouple from network
vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => ({ data: undefined, isLoading: false }),
    useMutation: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

vi.mock('../../stores/authStore', () => {
  const state = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (selector: (s: typeof state) => unknown) => selector(state)
  useAuthStore.getState = () => state
  return { useAuthStore }
})
vi.mock('../../stores/companyStore', () => {
  const state = { currentCompanyId: 'company-1' }
  const useCompanyStore = (selector: (s: typeof state) => unknown) => selector(state)
  useCompanyStore.getState = () => state
  return { useCompanyStore }
})

vi.mock('../../contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({
    config: { vertical: 'generic' },
    hasModule: (name: string) => name === 'Inventory',
  }),
}))
vi.mock('../../contexts/ProductConfigContext', () => ({
  useProductConfig: () => ({ isOtospex: false }),
}))
vi.mock('../../hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'EUR', decimals: 2 }),
  getDecimals: () => 2,
}))
vi.mock('./api/platformQueries', () => ({
  useProductSubmission: () => ({ mutate: vi.fn() }),
}))
vi.mock('../catalog/hooks/useVariants', () => ({
  useVariantsForProduct: () => ({ data: [] }),
}))

// child components that fetch / render heavy trees — stub them out
vi.mock('../../components/catalog/CategorySelect', () => ({
  CategorySelect: () => <div data-testid="category-select" />,
}))
vi.mock('./components/BarcodeLookupInput', () => ({
  BarcodeLookupInput: () => <div data-testid="barcode-lookup" />,
}))
vi.mock('./components/CatalogBanner', () => ({
  CatalogBanner: () => null,
}))
vi.mock('../products/components', () => ({
  ProductImageSection: () => <div data-testid="product-image" />,
  ParapharmacyMetadataFields: () => null,
}))
vi.mock('../catalog/components/ProductVariantMatrixEditor', () => ({
  ProductVariantMatrixEditor: () => null,
}))
vi.mock('../../components/molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: () => <div data-testid="tax-config" />,
}))

describe('ProductForm (canonical layout)', () => {
  it('renders a single page-level heading', () => {
    render(<ProductForm />)
    const h1s = screen.getAllByRole('heading', { level: 1 })
    expect(h1s).toHaveLength(1)
    expect(h1s[0]).toHaveTextContent(/inventory:products\.new/i)
  })

  it('groups fields under section headings', () => {
    render(<ProductForm />)
    // Canonical forms section fields under <h2>; the original used bespoke
    // heading classes — this guards the section structure stays.
    expect(
      screen.getAllByRole('heading', { level: 2 }).length,
    ).toBeGreaterThanOrEqual(1)
  })

  it('renders labelled form fields via FormField', () => {
    render(<ProductForm />)
    expect(
      screen.getByLabelText('inventory:products.name', { exact: false }),
    ).toBeInTheDocument()
    expect(
      screen.getByLabelText('inventory:products.sku', { exact: false }),
    ).toBeInTheDocument()
  })

  it('renders the sale price field via MoneyInput', () => {
    render(<ProductForm />)
    const priceInput = screen.getByLabelText('inventory:products.salePrice', {
      exact: false,
    })
    // MoneyInput renders type="number" + inputMode="decimal"; the original raw
    // <input type="number"> had no inputMode. This is the red→green driver.
    expect(priceInput).toHaveAttribute('type', 'number')
    expect(priceInput).toHaveAttribute('inputmode', 'decimal')
  })

  it('renders a submit button', () => {
    render(<ProductForm />)
    const save = screen.getByRole('button', { name: 'actions.save' })
    expect(save).toHaveAttribute('type', 'submit')
  })
})
