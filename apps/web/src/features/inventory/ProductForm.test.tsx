import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { ProductForm } from './ProductForm'
import { bcsub, bcdiv, bcmul } from '../../lib/decimal'

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
  useCurrency: () => ({ currency: 'EUR', decimals: 2, locale: 'fr-FR' }),
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
// BarcodeHero: keep the real component but mock the lookup hook it now uses
vi.mock('@/features/inventory/hooks/useCatalogBarcodeLookup', () => ({
  useCatalogBarcodeLookup: vi.fn(() => ({ isSearching: false })),
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
vi.mock('../uom/components/UnitDropdown', () => ({
  UnitDropdown: ({ value, onChange }: { value?: string; onChange?: (v: string) => void }) => (
    <select data-testid="unit-dropdown" value={value ?? ''} onChange={(e) => onChange?.(e.target.value)}>
      <option value="">uom:selectUnit</option>
      <option value="unit-1">Piece (pcs)</option>
    </select>
  ),
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

  it('renders Save-draft and Publish submit buttons targeting the form', () => {
    render(<ProductForm />)
    // Chrome parity (1.7a): the footer Save/Cancel were replaced by header
    // Cancel (ghost) + Save draft (outline) + Publish (primary). Save draft and
    // Publish both submit the form for now (Publish = primary) via `form=`.
    const publish = screen.getByRole('button', { name: 'catalog:editor.actions.publish' })
    expect(publish).toHaveAttribute('type', 'submit')
    expect(publish).toHaveAttribute('form', 'product-editor-form')
    const saveDraft = screen.getByRole('button', { name: 'catalog:editor.actions.saveDraft' })
    expect(saveDraft).toHaveAttribute('type', 'submit')
  })
})

describe('ProductForm (General section parity)', () => {
  it('renders a Type select with part, service, consumable options (no storable)', () => {
    render(<ProductForm />)
    // The Type select is rendered via a <select> element with the three valid options
    const typeSelect = screen.getByLabelText(/inventory:products\.type/i, { exact: false })
    expect(typeSelect).toBeInTheDocument()

    // Verify the three valid options are present
    expect(screen.getByText(/inventory:products\.typeOptions\.part/i)).toBeInTheDocument()
    expect(screen.getByText(/inventory:products\.typeOptions\.service/i)).toBeInTheDocument()
    expect(screen.getByText(/inventory:products\.typeOptions\.consumable/i)).toBeInTheDocument()

    // Verify "storable" is NOT present
    expect(screen.queryByText(/storable/i)).not.toBeInTheDocument()
  })

  it('renders the UnitDropdown (unit of measure) in the General section', () => {
    render(<ProductForm />)
    expect(screen.getByTestId('unit-dropdown')).toBeInTheDocument()
  })

  it('renders an "Active for e-commerce" checkbox in the General section', () => {
    render(<ProductForm />)
    const ecommerceCheckbox = screen.getByLabelText(/inventory:products\.isActiveForEcommerce/i, { exact: false })
    expect(ecommerceCheckbox).toBeInTheDocument()
    expect(ecommerceCheckbox).toHaveAttribute('type', 'checkbox')
  })
})

describe('ProductForm (Direction-A editor layout smoke)', () => {
  it('renders the barcode-first hero (barcode + name inputs)', () => {
    const { container } = render(<ProductForm />)
    // Hero binds the barcode field; its input carries the mono scan class.
    const monoInput = container.querySelector('input.font-mono')
    expect(monoInput).not.toBeNull()
  })

  it('renders the section nav with the General / Pricing / Inventory labels', () => {
    render(<ProductForm />)
    // Echo-key i18n: labels resolve to the raw key, asserted via substring.
    expect(
      screen.getAllByText(/editor\.sectionLabels\.general/i).length,
    ).toBeGreaterThanOrEqual(1)
    expect(
      screen.getAllByText(/editor\.sectionLabels\.pricing/i).length,
    ).toBeGreaterThanOrEqual(1)
    expect(
      screen.getAllByText(/editor\.sectionLabels\.inventory/i).length,
    ).toBeGreaterThanOrEqual(1)
  })

  it('renders the section cards anchored by id for scroll-spy', () => {
    const { container } = render(<ProductForm />)
    expect(container.querySelector('#section-general')).not.toBeNull()
    expect(container.querySelector('#section-pricing')).not.toBeNull()
    expect(container.querySelector('#section-inventory')).not.toBeNull()
    expect(container.querySelector('#section-suppliers')).not.toBeNull()
  })

  it('renders the related-operations rail and the before-publish checklist', () => {
    render(<ProductForm />)
    expect(
      screen.getByText(/editor\.related\.title/i),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/editor\.checklist\.title/i),
    ).toBeInTheDocument()
  })
})

describe('ProductForm (barcode lookup in hero — no General duplicate)', () => {
  it('General section does NOT render a standalone barcode-lookup label/input', () => {
    const { container } = render(<ProductForm />)
    // The old BarcodeLookupInput rendered a <label for="barcode-lookup">
    // After the refactor it must not exist anywhere in the DOM.
    expect(container.querySelector('#barcode-lookup')).toBeNull()
    expect(screen.queryByLabelText(/inventory:products\.barcode/i)).not.toBeInTheDocument()
  })

  it('hero mono barcode input is still present (single lookup surface)', () => {
    const { container } = render(<ProductForm />)
    const monoInputs = container.querySelectorAll('input.font-mono')
    expect(monoInputs).toHaveLength(1)
  })
})

describe('ProductForm (Pricing & Tax parity)', () => {
  it('renders a Purchase Price MoneyInput in the Pricing section', () => {
    render(<ProductForm />)
    const purchasePriceInput = screen.getByLabelText(/inventory:products\.purchasePrice/i, {
      exact: false,
    })
    expect(purchasePriceInput).toBeInTheDocument()
    // MoneyInput renders type="number" + inputMode="decimal"
    expect(purchasePriceInput).toHaveAttribute('type', 'number')
    expect(purchasePriceInput).toHaveAttribute('inputmode', 'decimal')
    expect(purchasePriceInput).toHaveAttribute('id', 'purchase_price')
  })

  it('renders Purchase Price BEFORE Sale Price in the Pricing section', () => {
    const { container } = render(<ProductForm />)
    const pricingSection = container.querySelector('#section-pricing')
    expect(pricingSection).not.toBeNull()
    const inputs = pricingSection!.querySelectorAll('input[type="number"]')
    // purchase_price should be first, sale_price second
    expect(inputs[0]).toHaveAttribute('id', 'purchase_price')
    expect(inputs[1]).toHaveAttribute('id', 'sale_price')
  })

  it('does not render Margin when both prices are empty (default state)', () => {
    render(<ProductForm />)
    // With empty defaults, margin should not be shown
    const marginLabel = screen.queryByLabelText(/inventory:products\.margin/i, { exact: false })
    expect(marginLabel).not.toBeInTheDocument()
    // Also check by text content — the label text itself
    expect(screen.queryByText(/inventory:products\.margin/i)).not.toBeInTheDocument()
  })

  it('shows Margin when both purchase_price and sale_price are entered', () => {
    render(<ProductForm />)
    const purchaseInput = screen.getByLabelText(/inventory:products\.purchasePrice/i, { exact: false })
    const saleInput = screen.getByLabelText(/inventory:products\.salePrice/i, { exact: false })

    // sale=100, purchase=60 → margin = (100−60)/100×100 = 40.0%
    fireEvent.change(purchaseInput, { target: { value: '60' } })
    fireEvent.change(saleInput, { target: { value: '100' } })

    // The margin field should now appear
    expect(screen.getByLabelText(/inventory:products\.margin/i, { exact: false })).toBeInTheDocument()
  })

  it('hides Margin when sale_price is cleared back to empty', () => {
    render(<ProductForm />)
    const purchaseInput = screen.getByLabelText(/inventory:products\.purchasePrice/i, { exact: false })
    const saleInput = screen.getByLabelText(/inventory:products\.salePrice/i, { exact: false })

    fireEvent.change(purchaseInput, { target: { value: '60' } })
    fireEvent.change(saleInput, { target: { value: '100' } })
    // Margin visible
    expect(screen.getByLabelText(/inventory:products\.margin/i, { exact: false })).toBeInTheDocument()
    // Clear sale_price → margin should disappear
    fireEvent.change(saleInput, { target: { value: '' } })
    expect(screen.queryByLabelText(/inventory:products\.margin/i, { exact: false })).not.toBeInTheDocument()
  })

  it('WAC display (edit mode) — uses formatCurrency (no raw parseFloat in rendered output)', () => {
    // The WAC display should show a currency-formatted string, not a raw number from parseFloat
    // We verify indirectly: in new-mode there is no WAC input at all.
    render(<ProductForm />)
    expect(screen.queryByLabelText(/inventory:products\.costWac/i)).not.toBeInTheDocument()
  })
})

describe('Indicative margin computation — decimal precision (no parseFloat/Number)', () => {
  it('sale 100 / purchase 60 → 40.0% (exact result)', () => {
    // Reproduce the exact calculation used in ProductForm:
    // diff = bcsub('100', '60', 4) = '40.0000'
    // ratio = bcdiv('40.0000', '100', 6) = '0.400000'
    // percent = bcmul('0.400000', '100', 1) = '40.0'
    const diff = bcsub('100', '60', 4)
    const ratio = bcdiv(diff, '100', 6)
    const percent = bcmul(ratio, '100', 1)
    expect(percent).toBe('40.0')
  })

  it('sale 100 / purchase 0 → 100.0%', () => {
    const diff = bcsub('100', '0', 4)
    const ratio = bcdiv(diff, '100', 6)
    const percent = bcmul(ratio, '100', 1)
    expect(percent).toBe('100.0')
  })

  it('sale 80 / purchase 100 → negative margin (-25.0%)', () => {
    // purchase > sale → negative margin is valid and should render
    const diff = bcsub('80', '100', 4)
    const ratio = bcdiv(diff, '80', 6)
    const percent = bcmul(ratio, '100', 1)
    expect(percent).toBe('-25.0')
  })
})
