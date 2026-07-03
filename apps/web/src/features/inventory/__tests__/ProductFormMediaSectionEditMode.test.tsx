/**
 * ProductForm — Media & Files section, EDIT MODE tests
 *
 * Separate file because module-level vi.mock('react-router-dom') useParams
 * must return { id: 'prod-abc-123' } for the whole file.
 */

import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'

// ── i18n (echo key) ──────────────────────────────────────────────────────────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

// ── Router — EDIT MODE ────────────────────────────────────────────────────────
vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
  useParams: () => ({ id: 'prod-abc-123' }),
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
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
vi.mock('../../products/components', () => ({
  ProductImageSection: () => <div data-testid="product-image-section" />,
  ParapharmacyMetadataFields: () => null,
  CreateModeImageBuffer: () => <div data-testid="create-mode-image-buffer" />,
}))

// ── Platform submission ───────────────────────────────────────────────────────
vi.mock('../api/platformQueries', () => ({
  useProductSubmission: () => ({ mutate: vi.fn() }),
  useEnrichmentRefresh: () => ({ mutate: vi.fn(), isPending: false }),
}))

// ── productImages facade ──────────────────────────────────────────────────────
vi.mock('../../products/api/productImages', () => ({
  getProductImages: vi.fn(),
  uploadProductImage: vi.fn(),
  setProductImagePrimary: vi.fn(),
  deleteProductImage: vi.fn(),
  reorderProductImages: vi.fn(),
  getProductImageDownloadUrl: vi.fn(),
  getPublicProductImages: vi.fn(),
}))

// Import AFTER mocks
const { ProductForm } = await import('../ProductForm')

describe('ProductForm — Media section (edit mode, id=prod-abc-123)', () => {
  it('renders the #section-media anchor in edit mode', () => {
    const { container } = render(<ProductForm />)
    expect(container.querySelector('#section-media')).not.toBeNull()
  })

  it('mounts ProductImageSection inside the Media section (no-regression)', () => {
    render(<ProductForm />)
    expect(screen.getByTestId('product-image-section')).toBeInTheDocument()
  })

  it('does NOT show the "save first" helper in edit mode', () => {
    render(<ProductForm />)
    expect(screen.queryByText('products:media.saveFirst')).not.toBeInTheDocument()
  })

  it('section nav shows the media label in edit mode', () => {
    render(<ProductForm />)
    expect(
      screen.getAllByText(/editor\.sectionLabels\.media/i).length,
    ).toBeGreaterThanOrEqual(1)
  })
})
