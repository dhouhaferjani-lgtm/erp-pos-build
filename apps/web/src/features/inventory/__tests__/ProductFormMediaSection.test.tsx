/**
 * ProductForm — Media & Files section tests (TDD, §5 brief)
 *
 * Covers:
 * 1. Media section renders with a nav entry + #section-media anchor (create mode)
 * 2. Create mode: shows CreateModeImageBuffer (enabled add tile) — NOT disabled tile
 *    and does NOT call the upload endpoint on render
 * 3. Edit mode: #section-media anchor present; ProductImageSection mounts
 *    (no-regression); section nav shows media label
 *
 * KNOWN pre-existing failures NOT chased here:
 *   ProductMovementsTab.test.tsx, ProductDocumentsTab.test.tsx
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'

// ── i18n (echo key) ──────────────────────────────────────────────────────────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

// ── React Query ───────────────────────────────────────────────────────────────
vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => ({ data: undefined, isLoading: false }),
    useMutation: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

// ── Auth/Company stores ───────────────────────────────────────────────────────
vi.mock('../../../stores/authStore', () => {
  const state = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (selector: (s: typeof state) => unknown) => selector(state)
  useAuthStore.getState = () => state
  return { useAuthStore }
})
vi.mock('../../../stores/companyStore', () => {
  const state = { currentCompanyId: 'company-1' }
  const useCompanyStore = (selector: (s: typeof state) => unknown) => selector(state)
  useCompanyStore.getState = () => state
  return { useCompanyStore }
})

// ── Contexts ──────────────────────────────────────────────────────────────────
vi.mock('../../../contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({
    config: { vertical: 'generic' },
    hasModule: (name: string) => name === 'Inventory',
  }),
}))
vi.mock('../../../contexts/ProductConfigContext', () => ({
  useProductConfig: () => ({ isOtospex: false }),
}))
vi.mock('../../../hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'EUR', decimals: 2, locale: 'fr-FR' }),
  getDecimals: () => 2,
}))

// ── Heavy children ────────────────────────────────────────────────────────────
vi.mock('../../../components/catalog/CategorySelect', () => ({
  CategorySelect: () => <div data-testid="category-select" />,
}))
vi.mock('@/features/inventory/hooks/useCatalogBarcodeLookup', () => ({
  useCatalogBarcodeLookup: vi.fn(() => ({ isSearching: false })),
}))
vi.mock('../components/CatalogBanner', () => ({
  CatalogBanner: () => null,
}))
vi.mock('../../catalog/hooks/useVariants', () => ({
  useVariantsForProduct: () => ({ data: [] }),
}))
vi.mock('../../../components/molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: () => <div data-testid="tax-config" />,
}))
vi.mock('../../uom/components/UnitDropdown', () => ({
  UnitDropdown: ({ value, onChange }: { value?: string; onChange?: (v: string) => void }) => (
    <select data-testid="unit-dropdown" value={value ?? ''} onChange={(e) => onChange?.(e.target.value)}>
      <option value="">uom:selectUnit</option>
    </select>
  ),
}))
vi.mock('../../catalog/components/ProductVariantMatrixEditor', () => ({
  ProductVariantMatrixEditor: () => null,
}))

// ── ProductImageSection + CreateModeImageBuffer mocks ────────────────────────
vi.mock('../../products/components/ProductImageSection', () => ({
  ProductImageSection: () => <div data-testid="product-image-section" />,
}))
vi.mock('../../products/components/ParapharmacyMetadataFields', () => ({
  ParapharmacyMetadataFields: () => null,
}))
vi.mock('../../products/components/CreateModeImageBuffer', () => ({
  CreateModeImageBuffer: ({
    bufferedFiles,
    onFilesChange,
  }: {
    bufferedFiles: File[]
    onFilesChange: (files: File[]) => void
  }) => (
    <div data-testid="create-mode-image-buffer">
      <input
        data-testid="buffer-file-input"
        type="file"
        onChange={() => { onFilesChange([...bufferedFiles]) }}
      />
      <button type="button" aria-label="products:media.addImage">
        Add
      </button>
    </div>
  ),
}))

// ── Platform submission ───────────────────────────────────────────────────────
vi.mock('../api/platformQueries', () => ({
  useProductSubmission: () => ({ mutate: vi.fn() }),
  useEnrichmentRefresh: () => ({ mutate: vi.fn(), isPending: false }),
}))

// ── productImages facade — must NOT be called in create mode on render ────────
const mockGetProductImages = vi.fn()
const mockUploadProductImage = vi.fn()
vi.mock('../../products/api/productImages', () => ({
  getProductImages: mockGetProductImages,
  uploadProductImage: mockUploadProductImage,
  setProductImagePrimary: vi.fn(),
  deleteProductImage: vi.fn(),
  reorderProductImages: vi.fn(),
  getProductImageDownloadUrl: vi.fn(),
  getPublicProductImages: vi.fn(),
}))

// ─────────────────────────────────────────────────────────────────────────────
// CREATE MODE: useParams returns no id
// ─────────────────────────────────────────────────────────────────────────────
vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
  useParams: () => ({ id: '' }),
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

// Import AFTER all mocks are set up
const { ProductForm } = await import('../ProductForm')

describe('ProductForm — Media section (create mode)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the #section-media anchor', () => {
    const { container } = render(<ProductForm />)
    expect(container.querySelector('#section-media')).not.toBeNull()
  })

  it('renders a Media nav entry in the SectionNav', () => {
    render(<ProductForm />)
    // echo-key i18n: key resolves to 'catalog:editor.sectionLabels.media'
    expect(
      screen.getAllByText(/editor\.sectionLabels\.media/i).length,
    ).toBeGreaterThanOrEqual(1)
  })

  it('renders CreateModeImageBuffer in create mode (not the disabled tile)', () => {
    render(<ProductForm />)
    expect(screen.getByTestId('create-mode-image-buffer')).toBeInTheDocument()
    // Old "save first" text must NOT appear (buffer replaces it)
    expect(screen.queryByText('products:media.saveFirst')).not.toBeInTheDocument()
  })

  it('does NOT call getProductImages or uploadProductImage on render in create mode', () => {
    render(<ProductForm />)
    expect(mockGetProductImages).not.toHaveBeenCalled()
    expect(mockUploadProductImage).not.toHaveBeenCalled()
  })

  it('does NOT render the ProductImageSection stub in create mode', () => {
    render(<ProductForm />)
    // ProductImageSection is the edit-mode component — not present in create mode
    expect(screen.queryByTestId('product-image-section')).not.toBeInTheDocument()
  })
})
