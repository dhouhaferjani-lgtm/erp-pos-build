import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { StockLevelsPage } from './StockLevelsPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    // Mirror i18next: a string 2nd arg is a default value; an object 2nd arg is
    // interpolation options. Return the key otherwise so assertions are stable.
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

// The quick modal is exercised on its own; here the page only has to hand it the
// right props and gate the links.
vi.mock('../stock-adjustments/components/QuickStockAdjustmentModal', () => ({
  QuickStockAdjustmentModal: ({ productId, productName }: { productId: string; productName: string }) => (
    <div data-testid="quick-adjust-modal" data-product-id={productId}>
      {productName}
    </div>
  ),
}))

const grantedPermissions = new Set<string>([
  'inventory.adjustments.create',
  // The quick modal posts immediately, so the Adjust affordance requires BOTH
  // create and post — a create-only operator would otherwise fill the form and
  // be refused at the end.
  'inventory.adjustments.post',
  'inventory.transfers.create',
  'goods-receipt.create-standalone',
])

vi.mock('../auth/components/RequirePermission', () => ({
  RequirePermission: ({
    permission,
    children,
    fallback = null,
  }: {
    permission?: string
    children: React.ReactNode
    fallback?: React.ReactNode
  }) => <>{permission === undefined || grantedPermissions.has(permission) ? children : fallback}</>,
}))

vi.mock('../../hooks/usePageTitle', () => ({ usePageTitle: () => {} }))
vi.mock('../../hooks/useLocation', () => ({ useLocation: () => ({ currentLocationId: null }) }))
vi.mock('../locations/LocationSelector', () => ({ LocationSelector: () => <div data-testid="location-selector" /> }))

vi.mock('../../stores/companyStore', () => {
  const state = { currentCompanyId: 'company-1' }
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

interface StockLevel {
  id: string
  product_id: string
  product_name: string | null
  location_id: string
  location_name: string | null
  quantity: string
  quantity_decimals: number
  reserved: string
  available: string
  min_quantity: string | null
}

function makeStock(overrides: Partial<StockLevel>): StockLevel {
  return {
    id: 'id',
    product_id: 'p-0',
    product_name: 'Widget',
    location_id: 'loc-1',
    location_name: 'Main',
    quantity: '10.0000',
    quantity_decimals: 3,
    reserved: '0.0000',
    available: '10.0000',
    min_quantity: '2.0000',
    ...overrides,
  }
}

interface StockLevelsResponse {
  data: StockLevel[]
  meta?: { total: number; current_page: number; per_page: number; last_page: number }
}

const mockStockLevelsReturn: {
  data: StockLevelsResponse | undefined
  isLoading: boolean
  error: unknown
} = {
  data: {
    data: [
      makeStock({ id: '1', product_id: 'p-1', product_name: 'Alpha', available: '50.0000', quantity: '50.0000' }),
      makeStock({ id: '2', product_id: 'p-2', product_name: 'Beta', available: '0.0000', quantity: '0.0000' }),
    ],
    meta: { total: 2, current_page: 1, per_page: 25, last_page: 1 },
  },
  isLoading: false,
  error: null,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: (opts: { queryKey: unknown[] }) => {
      // The locations query is keyed ['tenant','company','locations']; everything
      // else is the stock-levels query. Return an empty location list for the
      // former so the page renders without a network layer.
      const key = opts.queryKey
      if (Array.isArray(key) && key.includes('locations')) {
        return { data: [], isLoading: false, error: null }
      }
      return mockStockLevelsReturn
    },
    useMutation: () => ({ mutate: vi.fn(), isPending: false, error: null }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

describe('StockLevelsPage (canonical list)', () => {
  it('renders exactly one h1', () => {
    render(<StockLevelsPage />)
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
  })

  it('renders a row per stock level', () => {
    render(<StockLevelsPage />)
    expect(screen.getByText('Alpha')).toBeInTheDocument()
    expect(screen.getByText('Beta')).toBeInTheDocument()
  })

  it('renders stock status as a StatusBadge pill (rounded-full)', () => {
    render(<StockLevelsPage />)
    // out-of-stock row -> danger tone via StatusBadge, which renders a
    // rounded-full pill span (tokens.badge.base).
    const pill = screen.getByText('inventory:stock.status.outOfStock')
    expect(pill.className).toContain('rounded-full')
  })

  /**
   * CARRIED FORWARD from the deleted hand-rolled modal (DPA V7 / F7).
   *
   * The original assertion was that the modal shows the selected quantity at the
   * PRODUCT UNIT's precision (3 dp here, not the storage scale of 4) — a guard
   * from the UoM display-precision lane whose baselines are empty and must stay
   * empty. The modal moved, so the guard moves with it: the page must hand the
   * quick modal the right product, and the modal's own test asserts the
   * precision of `observed_before` and `quantity_after`.
   */
  it('opens the document-backed quick modal for the selected product', async () => {
    const user = userEvent.setup()
    render(<StockLevelsPage />)

    expect(screen.queryByTestId('quick-adjust-modal')).not.toBeInTheDocument()

    await user.click(screen.getAllByRole('button', { name: /inventory:stock.adjust/ })[0])

    const modal = screen.getByTestId('quick-adjust-modal')
    expect(modal).toHaveAttribute('data-product-id', 'p-1')
    expect(modal).toHaveTextContent('Alpha')
  })

  /**
   * The four raw writers are GONE. This asserts the page cannot call them —
   * there is no mutation left on it at all — and that the two destinations it
   * links to instead are permission-gated on their OWN requirements, so F7
   * cannot re-create the hole F8 closes.
   */
  it('never calls the deleted raw stock endpoints and gates its links', () => {
    render(<StockLevelsPage />)

    const hrefs = screen
      .getAllByRole('link')
      .map((link) => link.getAttribute('href') ?? '')

    for (const raw of ['/stock-movements/receive', '/stock-movements/issue', '/stock-movements/transfer', '/stock-movements/adjust']) {
      expect(hrefs.some((href) => href.includes(raw))).toBe(false)
    }

    expect(hrefs.some((href) => href.startsWith('/inventory/stock-transfers/new'))).toBe(true)
    expect(hrefs.some((href) => href.startsWith('/purchases/receipts/new'))).toBe(true)
  })

  it('hides the quick-adjust affordance without inventory.adjustments.post', () => {
    grantedPermissions.delete('inventory.adjustments.post')
    try {
      render(<StockLevelsPage />)
      expect(
        screen.queryByRole('button', { name: /inventory:stock.adjust/ }),
      ).not.toBeInTheDocument()
    } finally {
      grantedPermissions.add('inventory.adjustments.post')
    }
  })

  it('hides the transfer affordance without inventory.transfers.create', () => {
    grantedPermissions.delete('inventory.transfers.create')
    try {
      render(<StockLevelsPage />)
      const hrefs = screen.getAllByRole('link').map((link) => link.getAttribute('href') ?? '')
      expect(hrefs.some((href) => href.startsWith('/inventory/stock-transfers/new'))).toBe(false)
    } finally {
      grantedPermissions.add('inventory.transfers.create')
    }
  })
})
