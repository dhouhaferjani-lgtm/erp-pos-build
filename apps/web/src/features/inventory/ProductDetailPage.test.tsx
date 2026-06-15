import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { ProductDetailPage } from './ProductDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiDelete: mockApiDelete,
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
    useNavigate: () => mockNavigate,
    useParams: () => ({ id: 'product-1' }),
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

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
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
  })

  afterEach(() => {
    resetTenant()
  })

  it('renders exactly one h1 with the product name', async () => {
    mockApiGet.mockResolvedValue({ data: { data: productFixture() } })
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(<ProductDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Brake Pad')
    })
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders the active status via StatusBadge (rounded-full pill)', async () => {
    mockApiGet.mockResolvedValue({ data: { data: productFixture() } })
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(<ProductDetailPage />, { wrapper: wrapper(queryClient) })

    const badge = await screen.findByText('common:status.active')
    expect(badge.tagName).toBe('SPAN')
    expect(badge.className).toContain('rounded-full')
  })
})
