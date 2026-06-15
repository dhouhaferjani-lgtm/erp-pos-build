import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { StockMovementsPage } from './StockMovementsPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('../../hooks/useLocation', () => ({ useLocation: () => ({ currentLocationId: null }) }))
vi.mock('../location/LocationSelector', () => ({ LocationSelector: () => <div data-testid="location-selector" /> }))

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

interface StockMovement {
  id: string
  product_id: string
  product_name: string
  location_id: string
  location_name: string
  movement_type: string
  quantity: string
  quantity_before: string
  quantity_after: string
  reference: string
  notes: string | null
  user_id: string
  user_name: string | null
  created_at: string
}

function makeMovement(overrides: Partial<StockMovement>): StockMovement {
  return {
    id: 'id',
    product_id: 'p-0',
    product_name: 'Widget',
    location_id: 'loc-1',
    location_name: 'Main',
    movement_type: 'receipt',
    quantity: '5.0000',
    quantity_before: '0.0000',
    quantity_after: '5.0000',
    reference: 'REF-0',
    notes: null,
    user_id: 'u-1',
    user_name: 'Alice',
    created_at: '2026-06-14T10:00:00Z',
    ...overrides,
  }
}

const mockReturn: {
  data: { data: StockMovement[] } | undefined
  isLoading: boolean
  error: unknown
} = {
  data: {
    data: [
      makeMovement({ id: '1', product_name: 'Alpha', movement_type: 'receipt', quantity: '5.0000' }),
      makeMovement({ id: '2', product_name: 'Beta', movement_type: 'issue', quantity: '-3.0000', quantity_after: '2.0000' }),
    ],
  },
  isLoading: false,
  error: null,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return { ...actual, useQuery: () => mockReturn }
})

describe('StockMovementsPage (canonical list)', () => {
  it('renders exactly one h1', () => {
    render(<StockMovementsPage />)
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
  })

  it('renders a row per movement', () => {
    render(<StockMovementsPage />)
    expect(screen.getByText('Alpha')).toBeInTheDocument()
    expect(screen.getByText('Beta')).toBeInTheDocument()
  })

  it('renders the movement type as a StatusBadge pill (rounded-full)', () => {
    render(<StockMovementsPage />)
    const pill = screen.getByText('movements.typeLabels.receipt')
    expect(pill.className).toContain('rounded-full')
  })

  it('renders the signed quantity in a numeric (tabular-nums) cell', () => {
    render(<StockMovementsPage />)
    const qty = screen.getByText('+5')
    // DataTable numeric columns right-align with tabular-nums on the <td>.
    const cell = qty.closest('td')
    expect(cell?.className).toContain('tabular-nums')
  })
})
