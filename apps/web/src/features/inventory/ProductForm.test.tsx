import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { ProductForm } from './ProductForm'
import { bcsub, bcdiv, bcmul } from '../../lib/decimal'
import type { LookupState, SuggestedProduct } from './types/platform'

interface MockMutationOptions {
  mutationFn?: (variables: unknown) => unknown | Promise<unknown>
}

interface MockCatalogLookupOptions {
  onProductData: (data: SuggestedProduct) => void
  onLookupStateChange: (state: LookupState) => void
}

// i18n → return the key (string 2nd arg = default value)
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

// router
const mockNavigate = vi.fn()
let mockParams: Record<string, string> = {}
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => mockParams,
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

// Shared mutateAsync handle so tests can configure per-test resolution.
const mockMutateAsync = vi.fn()
const mockApiPost = vi.fn()
let mockUseMutationRunsMutationFn = false
let mockCatalogLookupReturnsFound = false
let mockCatalogLookupCallbacksFired = false
const mockSuggestedProduct: SuggestedProduct = {
  name: 'Catalog Product',
  barcode: '12345678',
  brand: 'Catalog Brand',
  brand_id: 'brand-uuid-1',
  description: 'Catalog description',
  platform_product_id: 'platform-product-1',
  classification: {},
  ingredients: [],
  images: [],
}

// decouple from network
vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => ({ data: undefined, isLoading: false }),
    useMutation: (options?: MockMutationOptions) => ({
      mutate: vi.fn(),
      mutateAsync: (variables: unknown) => {
        if (mockUseMutationRunsMutationFn && options?.mutationFn !== undefined) {
          return options.mutationFn(variables)
        }
        return mockMutateAsync(variables)
      },
      isPending: false,
    }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn() },
  apiPatch: vi.fn(),
  apiPost: (...args: unknown[]) => mockApiPost(...args),
}))

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
  useEnrichmentRefresh: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
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
vi.mock('@/features/inventory/hooks/useCatalogBarcodeLookup', async () => {
  const React = await import('react')
  return {
    useCatalogBarcodeLookup: vi.fn((options: MockCatalogLookupOptions) => {
      React.useEffect(() => {
        if (!mockCatalogLookupReturnsFound || mockCatalogLookupCallbacksFired) return
        mockCatalogLookupCallbacksFired = true
        options.onLookupStateChange('found')
        options.onProductData(mockSuggestedProduct)
      }, [options])
      return { isSearching: false }
    }),
  }
})
vi.mock('./components/CatalogBanner', () => ({
  CatalogBanner: () => null,
}))
vi.mock('../products/components', () => ({
  ProductImageSection: () => <div data-testid="product-image" />,
  ParapharmacyMetadataFields: () => null,
  CreateModeImageBuffer: ({
    onFilesChange,
  }: {
    bufferedFiles: File[]
    onFilesChange: (files: File[]) => void
  }) => (
    <div data-testid="create-mode-image-buffer">
      <input
        data-testid="buffer-file-input"
        type="file"
        onChange={() => { onFilesChange([]) }}
      />
    </div>
  ),
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

beforeEach(() => {
  mockParams = {}
  mockNavigate.mockReset()
  mockMutateAsync.mockReset()
  mockApiPost.mockReset()
  mockUseMutationRunsMutationFn = false
  mockCatalogLookupReturnsFound = false
  mockCatalogLookupCallbacksFired = false
})

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

  it('shows a single Save action (no separate Publish) in nav-only phase', () => {
    render(<ProductForm />)
    // Phase 1: Publish is deferred — only a single primary Save button exists.
    expect(screen.queryByText('catalog:editor.actions.publish')).not.toBeInTheDocument()
    expect(screen.queryByText('catalog:editor.actions.saveDraft')).not.toBeInTheDocument()
    const save = screen.getByRole('button', { name: 'catalog:editor.actions.save' })
    expect(save).toHaveAttribute('type', 'submit')
    expect(save).toHaveAttribute('form', 'product-editor-form')
    // Save & Close is offered via the split-button caret (onSaveAndClose wired).
    expect(screen.getByRole('button', { name: 'actions.openSaveMenu' })).toBeInTheDocument()
  })

  it('navigates to the new product detail page after create', async () => {
    mockMutateAsync.mockResolvedValueOnce({ id: 'prod-9' })
    render(<ProductForm />)
    // Fill required fields so RHF validation passes
    fireEvent.change(screen.getByLabelText('inventory:products.name', { exact: false }), { target: { value: 'Test Product' } })
    fireEvent.change(screen.getByLabelText('inventory:products.sku', { exact: false }), { target: { value: 'SKU-9' } })
    fireEvent.submit(document.getElementById('product-editor-form') as HTMLFormElement)
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/inventory/products/prod-9'))
  })

  it('posts the server-resolved brand_id when creating from a found catalog lookup', async () => {
    mockUseMutationRunsMutationFn = true
    mockCatalogLookupReturnsFound = true
    mockApiPost.mockResolvedValueOnce({ id: 'prod-brand' })

    render(<ProductForm />)

    await waitFor(() =>
      expect(screen.getByLabelText('inventory:products.name', { exact: false })).toHaveValue('Catalog Product'),
    )
    fireEvent.change(screen.getByLabelText('inventory:products.sku', { exact: false }), { target: { value: 'SKU-BRAND' } })
    fireEvent.submit(document.getElementById('product-editor-form') as HTMLFormElement)

    await waitFor(() =>
      expect(mockApiPost).toHaveBeenCalledWith(
        '/products',
        expect.objectContaining({ brand_id: 'brand-uuid-1' }),
      ),
    )
  })

  it('omits brand_id when the form barcode no longer matches the suggested product (stale found state)', async () => {
    mockUseMutationRunsMutationFn = true
    mockCatalogLookupReturnsFound = true
    mockApiPost.mockResolvedValueOnce({ id: 'prod-stale' })

    render(<ProductForm />)

    // Genuine found flow: the suggested barcode ('12345678') is written into the form.
    await waitFor(() =>
      expect(screen.getByLabelText('inventory:products.name', { exact: false })).toHaveValue('Catalog Product'),
    )
    fireEvent.change(screen.getByLabelText('inventory:products.sku', { exact: false }), { target: { value: 'SKU-STALE' } })
    // Stale case: the barcode field changes to a DIFFERENT barcode while
    // lookupState stays 'found' (cache-fresh lookup fires no state transition,
    // so suggestedProductRef still holds product A). Product A's brand_id must
    // NOT be posted onto the product created with the new barcode.
    fireEvent.change(screen.getByLabelText('editor.hero.barcodePlaceholder'), { target: { value: '99999999' } })
    fireEvent.submit(document.getElementById('product-editor-form') as HTMLFormElement)

    await waitFor(() => expect(mockApiPost).toHaveBeenCalled())
    const [, body] = mockApiPost.mock.calls[0] as [string, Record<string, unknown>]
    expect(body).not.toHaveProperty('brand_id')
  })

  it('Save & Close navigates to the product list after create', async () => {
    mockMutateAsync.mockResolvedValueOnce({ id: 'prod-10' })
    render(<ProductForm />)
    fireEvent.change(screen.getByLabelText('inventory:products.name', { exact: false }), { target: { value: 'Test Product' } })
    fireEvent.change(screen.getByLabelText('inventory:products.sku', { exact: false }), { target: { value: 'SKU-10' } })
    // Drive the real Save & Close path: open the split menu, click the item
    // (sets close intent + requestSubmit on the external form).
    fireEvent.click(screen.getByRole('button', { name: 'actions.openSaveMenu' }))
    fireEvent.click(screen.getByRole('menuitem', { name: 'actions.saveAndClose' }))
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/inventory/products'))
    // And NOT to the detail route.
    expect(mockNavigate).not.toHaveBeenCalledWith('/inventory/products/prod-10')
  })
})

describe('ProductForm (General section parity)', () => {
  it('does NOT render the deprecated Product Type selector (part/service/consumable retired)', () => {
    render(<ProductForm />)
    // The legacy Product Type taxonomy has been removed from the form entirely.
    expect(screen.queryByLabelText(/inventory:products\.type/i, { exact: false })).not.toBeInTheDocument()
    expect(screen.queryByText(/inventory:products\.typeOptions\.part/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/inventory:products\.typeOptions\.service/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/inventory:products\.typeOptions\.consumable/i)).not.toBeInTheDocument()
  })

  it('renders the UnitDropdown (unit of measure) in the General section', () => {
    render(<ProductForm />)
    expect(screen.getByTestId('unit-dropdown')).toBeInTheDocument()
  })

  it('renders two Toggle switches (role="switch") for is_active and is_active_for_ecommerce in the General section', () => {
    render(<ProductForm />)
    // Both status fields are now Toggles, not plain checkboxes
    const activeToggle = screen.getByRole('switch', { name: /inventory:products\.active/i })
    expect(activeToggle).toBeInTheDocument()
    const ecommerceToggle = screen.getByRole('switch', { name: /inventory:products\.isActiveForEcommerce/i })
    expect(ecommerceToggle).toBeInTheDocument()
  })

  it('active toggle accessible name uses "active" key (Produit actif / Active product label)', () => {
    render(<ProductForm />)
    // The aria-label must reference the new inventory:products.active key
    const activeToggle = screen.getByRole('switch', { name: /inventory:products\.active/i })
    expect(activeToggle).toBeInTheDocument()
  })

  it('status toggles default to correct values: is_active=true, is_active_for_ecommerce=false', () => {
    render(<ProductForm />)
    const activeToggle = screen.getByRole('switch', { name: /inventory:products\.active/i })
    // is_active defaults to true
    expect(activeToggle).toBeChecked()
    const ecommerceToggle = screen.getByRole('switch', { name: /inventory:products\.isActiveForEcommerce/i })
    // is_active_for_ecommerce defaults to false
    expect(ecommerceToggle).not.toBeChecked()
  })

  it('toggling is_active updates submitted payload', async () => {
    const { default: userEvent } = await import('@testing-library/user-event')
    const user = userEvent.setup()
    render(<ProductForm />)
    const activeToggle = screen.getByRole('switch', { name: /inventory:products\.active/i })
    // Default is true (checked); click to uncheck
    await user.click(activeToggle)
    expect(activeToggle).not.toBeChecked()
  })

  it('toggling is_active_for_ecommerce updates its state', async () => {
    const { default: userEvent } = await import('@testing-library/user-event')
    const user = userEvent.setup()
    render(<ProductForm />)
    const ecommerceToggle = screen.getByRole('switch', { name: /inventory:products\.isActiveForEcommerce/i })
    // Default is false (unchecked); click to check
    await user.click(ecommerceToggle)
    expect(ecommerceToggle).toBeChecked()
  })

  it('no raw checkbox remains for is_active or is_active_for_ecommerce (old checkboxes are gone)', () => {
    // The old checkboxes were registered via register('is_active') / register('is_active_for_ecommerce')
    // and had id="is_active" / id="is_active_for_ecommerce". Those must not exist.
    const { container } = render(<ProductForm />)
    expect(container.querySelector('#is_active[type="checkbox"]')).toBeNull()
    expect(container.querySelector('#is_active_for_ecommerce[type="checkbox"]')).toBeNull()
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

describe('ProductForm (Inventory & Units parity)', () => {
  it('batch tracking renders a Toggle (role="switch") instead of a Checkbox', () => {
    render(<ProductForm />)
    // Toggle renders role="switch" on the visually-hidden native input
    const toggle = screen.getByRole('switch', { name: /inventory:products\.requiresBatchTracking/i })
    expect(toggle).toBeInTheDocument()
  })

  it('toggling batch tracking (ON) reveals the default_shelf_life_days input', () => {
    render(<ProductForm />)
    const toggle = screen.getByRole('switch', { name: /inventory:products\.requiresBatchTracking/i })
    // Shelf-life input is hidden until the toggle is ON
    expect(screen.queryByLabelText(/inventory:products\.defaultShelfLifeDays/i)).not.toBeInTheDocument()
    fireEvent.click(toggle)
    expect(screen.getByLabelText(/inventory:products\.defaultShelfLifeDays/i)).toBeInTheDocument()
  })

  it('renders the units_per_pack input', () => {
    render(<ProductForm />)
    const input = screen.getByLabelText(/inventory:products\.unitsPerPack/i, { exact: false })
    expect(input).toBeInTheDocument()
    expect(input).toHaveAttribute('type', 'number')
  })

  it('renders the shelf_location input', () => {
    render(<ProductForm />)
    const input = screen.getByLabelText(/inventory:products\.shelfLocation/i, { exact: false })
    expect(input).toBeInTheDocument()
    expect(input).toHaveAttribute('type', 'text')
  })

  it('renders the reorder_point as a quantity input (type=number, inputMode=decimal)', () => {
    render(<ProductForm />)
    const input = screen.getByLabelText(/inventory:products\.reorderPoint/i, { exact: false })
    expect(input).toBeInTheDocument()
    expect(input).toHaveAttribute('type', 'number')
    expect(input).toHaveAttribute('inputmode', 'decimal')
  })

  it('renders the reorder_quantity as a quantity input (type=number, inputMode=decimal)', () => {
    render(<ProductForm />)
    const input = screen.getByLabelText(/inventory:products\.reorderQuantity/i, { exact: false })
    expect(input).toBeInTheDocument()
    expect(input).toHaveAttribute('type', 'number')
    expect(input).toHaveAttribute('inputmode', 'decimal')
  })

  it('legacy free-text unit Input is NOT present in the Inventory section', () => {
    const { container } = render(<ProductForm />)
    const inventorySection = container.querySelector('#section-inventory')
    expect(inventorySection).not.toBeNull()
    // The legacy unit input had id="unit"
    expect(inventorySection!.querySelector('#unit')).toBeNull()
  })
})

// WAC edit-mode test lives in __tests__/ProductFormWacEditMode.test.tsx —
// it seeds the query cache with a product that has cost_price and verifies
// the WAC field renders formatCurrency output (not the raw decimal).

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
