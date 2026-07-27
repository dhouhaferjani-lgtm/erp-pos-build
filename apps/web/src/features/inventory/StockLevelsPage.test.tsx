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

  it('renders the selected stock quantity at the product unit precision', async () => {
    const user = userEvent.setup()
    render(<StockLevelsPage />)

    await user.click(screen.getAllByRole('button', { name: 'inventory:stock.adjust' })[0])

    expect(screen.getByText('inventory:stock.modal.currentQuantity').parentElement).toHaveTextContent('50.000')
  })
})
