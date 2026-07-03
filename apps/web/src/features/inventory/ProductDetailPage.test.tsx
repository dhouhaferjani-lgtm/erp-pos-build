import { QueryClientProvider, QueryClient } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { ProductDetailPage } from './ProductDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const mockHasPermission = vi.hoisted(() => vi.fn())
const mockUseEnrichmentFastPath = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiDelete: mockApiDelete,
  }
})

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

vi.mock('./hooks/useEnrichmentFastPath', () => ({
  useEnrichmentFastPath: mockUseEnrichmentFastPath,
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('@/features/enrichment/api/enrichmentApi', () => ({
  acceptEnrichmentResult: vi.fn(),
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, sub?: unknown) => (typeof sub === 'string' ? sub : key),
  }),
}))

// ProductConfigContext drives the isOtospex branch.
vi.mock('@/contexts/ProductConfigContext', () => ({
  useProductConfig: () => ({ isOtospex: false }),
}))

// Child tabs are owned by another agent — stub them.
vi.mock('./components/ProductMovementsTab', () => ({
  ProductMovementsTab: () => <div data-testid="movements-tab" />,
}))
vi.mock('./components/ProductDocumentsTab', () => ({
  ProductDocumentsTab: () => <div data-testid="documents-tab" />,
}))
vi.mock('./components', () => ({
  ProductStockLevels: () => <div data-testid="stock-levels" />,
}))
vi.mock('../products/components', () => ({
  ProductPrimaryImageDisplay: () => <div data-testid="primary-image" />,
}))
vi.mock('../products/hooks/useProductRealtime', () => ({
  useProductRealtime: () => undefined,
}))
vi.mock('@/hooks/useTaxConfigName', () => ({
  useTaxConfigName: () => null,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function LocationSearchProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

function renderProductDetail(route = '/inventory/products/product-1') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[route]}>
        <LocationSearchProbe />
        <Routes>
          <Route path="/inventory/products/:id" element={<ProductDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function productFixture() {
  return {
    id: 'product-1',
    name: 'Brake Pad',
    sku: 'BP-001',
    is_physical: true,
    description: 'A brake pad',
    sale_price: '50.000',
    purchase_price: '30.000',
    cost_price: '30.000',
    tax_rate: '19.00',
    default_tax_configuration_id: null,
    unit: 'pcs',
    barcode: '12345',
    is_active: true,
    oem_numbers: null,
    cross_references: null,
    enrichment_status: null,
    platform_product_id: null,
    created_at: '2026-05-01T00:00:00Z',
    updated_at: null,
  }
}

describe('ProductDetailPage', () => {
  beforeEach(() => {
    setTenant('tenant-1', 'company-1')
    mockApiGet.mockReset()
    mockApiDelete.mockReset()
    mockNavigate.mockReset()
    mockHasPermission.mockImplementation(() => true)
    mockUseEnrichmentFastPath.mockReturnValue({ phase: 'idle' })
  })

  afterEach(() => {
    resetTenant()
  })

  it('renders exactly one h1 with the product name', async () => {
    mockApiGet.mockResolvedValue({ data: { data: productFixture() } })

    renderProductDetail()

    await waitFor(() => {
      expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Brake Pad')
    })
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders the active status via StatusBadge (rounded-full pill)', async () => {
    mockApiGet.mockResolvedValue({ data: { data: productFixture() } })

    renderProductDetail()

    await waitFor(() => {
      expect(screen.getAllByText('common:status.active').length).toBeGreaterThan(0)
    })

    const badge = screen
      .getAllByText('common:status.active')
      .find((element) => element.className.includes('rounded-full'))

    if (!badge) throw new Error('Expected the header status badge to render as a rounded pill')
    expect(badge.tagName).toBe('SPAN')
    expect(badge.className).toContain('rounded-full')
  })

  it('reads the active tab from the tab search param', async () => {
    mockApiGet.mockResolvedValue({ data: { data: productFixture() } })

    renderProductDetail('/inventory/products/product-1?tab=movements')

    expect(await screen.findByTestId('movements-tab')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'products.tabs.movements' })).toHaveAttribute('aria-selected', 'true')
  })

  it('writes the active tab to the tab search param when changed', async () => {
    mockApiGet.mockResolvedValue({ data: { data: productFixture() } })
    const user = userEvent.setup()

    renderProductDetail()

    await screen.findByRole('heading', { name: 'Brake Pad' })
    await user.click(screen.getByRole('tab', { name: 'products.tabs.financialOperations' }))

    expect(screen.getByTestId('documents-tab')).toBeInTheDocument()
    expect(screen.getByTestId('location-search')).toHaveTextContent('?tab=financialOperations')
  })

  it('mounts the fast-path ready card for pending enrichment products with view permission', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: {
          ...productFixture(),
          enrichment_status: 'pending',
        },
      },
    })
    mockUseEnrichmentFastPath.mockReturnValue({ phase: 'ready', result: makeResult() })

    renderProductDetail()

    expect(await screen.findByText('barcodeLookup.fastPathReadyTitle')).toBeInTheDocument()
    expect(mockUseEnrichmentFastPath).toHaveBeenCalledWith({
      productId: 'product-1',
      enabled: true,
    })
  })
})

function makeResult() {
  return {
    id: 'result-1',
    product_id: 'product-1',
    product_name: 'Brake Pad',
    product_barcode: '12345',
    product_sku: 'BP-001',
    tracking_id: 'tracking-1',
    status: 'pending_review',
    enriched_data: {
      name: 'Enriched Brake Pad',
      brand: null,
      description: null,
      classification: {},
      ingredients: [],
      images: [],
      confidence_score: 95,
      enrichment_tier: 'high',
      field_confidence: null,
      enrichment_sources: null,
      assigned_barcode: null,
      assigned_barcode_type: null,
    },
    enrichment_quality: 'high',
    assigned_barcode: null,
    reviewed_at: null,
    reviewed_by: null,
    accepted_fields: null,
    rejection_reason: null,
    created_at: '2026-07-03T00:00:00Z',
  }
}
