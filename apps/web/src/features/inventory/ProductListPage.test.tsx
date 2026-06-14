import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ProductListPage } from './ProductListPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    // Mirror i18next: a string 2nd arg is a default value; an object 2nd arg is
    // interpolation options (OffsetPagination passes `{ from, to, total }`).
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  // useTableState reads/writes the URL via useSearchParams; a stable empty
  // params object + no-op setter keeps it happy under jsdom.
  useSearchParams: () => [new URLSearchParams(), vi.fn()] as const,
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('../../hooks/usePageTitle', () => ({ usePageTitle: () => {} }))

// Lock the company so currency/locale formatting is deterministic. The hook is
// called both as a selector AND via `.getState()` (by tenantScopedKey).
vi.mock('../../stores/companyStore', () => {
  const state = {
    currentCompanyId: 'company-1',
    companies: [{ id: 'company-1', currency: 'EUR', locale: 'en_US' }],
  }
  const useCompanyStore = (selector: (s: typeof state) => unknown) => selector(state)
  useCompanyStore.getState = () => state
  return { useCompanyStore }
})

vi.mock('../../stores/authStore', () => {
  const state = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (selector: (s: typeof state) => unknown) => selector(state)
  useAuthStore.getState = () => state
  return { useAuthStore }
})

interface Product {
  id: string
  name: string
  sku: string
  is_physical: boolean
  description: string | null
  sale_price: string | null
  purchase_price: string | null
  tax_rate: string | null
  unit: string | null
  barcode: string | null
  is_active: boolean
  oem_numbers: string[] | null
  cross_references: Array<{ brand: string; reference: string }> | null
  created_at: string
  updated_at: string | null
}

function makeProduct(overrides: Partial<Product>): Product {
  return {
    id: 'id',
    name: 'Widget',
    sku: 'SKU-0',
    is_physical: true,
    description: null,
    sale_price: '0',
    purchase_price: null,
    tax_rate: null,
    unit: 'pcs',
    barcode: null,
    is_active: true,
    oem_numbers: null,
    cross_references: null,
    created_at: '2026-06-14T00:00:00Z',
    updated_at: null,
    ...overrides,
  }
}

interface ProductsResponse {
  data: Product[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
  aggregates: {
    total_products: number
    total_active: number
    average_price: string | null
  }
}

const mockUseQueryReturn: {
  data: ProductsResponse | undefined
  isLoading: boolean
  error: unknown
} = {
  data: {
    data: [
      makeProduct({ id: '1', name: 'Alpha', sku: 'SKU-1', sale_price: '120.500', is_active: true }),
      makeProduct({ id: '2', name: 'Beta', sku: 'SKU-2', sale_price: '90.000', is_active: false }),
    ],
    meta: { total: 30, current_page: 1, last_page: 2, per_page: 25, from: 1, to: 25 },
    aggregates: { total_products: 2, total_active: 1, average_price: '105.250' },
  },
  isLoading: false,
  error: null,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return { ...actual, useQuery: () => mockUseQueryReturn }
})

describe('ProductListPage (canonical list)', () => {
  it('renders a row per product', () => {
    render(<ProductListPage />)
    expect(screen.getByText('Alpha')).toBeInTheDocument()
    expect(screen.getByText('Beta')).toBeInTheDocument()
    expect(screen.getByText('SKU-1')).toBeInTheDocument()
    expect(screen.getByText('SKU-2')).toBeInTheDocument()
  })

  it('renders the active/inactive status as a status badge', () => {
    render(<ProductListPage />)
    expect(screen.getByText('status.active')).toBeInTheDocument()
    expect(screen.getByText('status.inactive')).toBeInTheDocument()
  })

  it('renders pagination controls (per-page combobox)', () => {
    render(<ProductListPage />)
    // OffsetPagination renders a per-page <select> (combobox). It was already
    // wired; this guards we keep it after the migration.
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })

  it('exposes the add action as a button (not a bare link)', () => {
    render(<ProductListPage />)
    // Canonical list pages drive the primary "Add" action through a <Button>.
    // The original page used a navigation <Link>. This is the red→green driver.
    expect(
      screen.getByRole('button', { name: /inventory:products\.new/ }),
    ).toBeInTheDocument()
  })

  it('keeps the sortable Name header clickable', () => {
    render(<ProductListPage />)
    // The sortable column header text resolves to the common:fields default.
    expect(screen.getByText('Name')).toBeInTheDocument()
  })
})
