import { beforeEach, describe, it, expect, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { StockMovementsPage } from './StockMovementsPage'

interface CapturedQuery {
  queryFn: () => Promise<unknown>
}

const apiGetMock = vi.hoisted(() => vi.fn())
const queryCapture = vi.hoisted(() => ({ current: null as CapturedQuery | null }))

beforeEach(() => {
  apiGetMock.mockReset()
  queryCapture.current = null
})

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return { ...actual, api: { ...actual.api, get: apiGetMock } }
})

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

interface StockMovement {
  id: string
  product_id: string
  product_name: string
  location_id: string
  location_name: string
  movement_type: string
  quantity: string
  quantity_decimals: number
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
    quantity_decimals: 3,
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

interface StockMovementsResponse {
  data: StockMovement[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

const mockReturn: {
  data: StockMovementsResponse | undefined
  isLoading: boolean
  error: unknown
} = {
  data: {
    data: [
      makeMovement({ id: '1', product_name: 'Alpha', movement_type: 'receipt', quantity: '5.0000' }),
      makeMovement({ id: '2', product_name: 'Beta', movement_type: 'issue', quantity: '-3.0000', quantity_after: '2.0000' }),
    ],
    meta: { current_page: 1, last_page: 3, per_page: 25, total: 60, from: 1, to: 25 },
  },
  isLoading: false,
  error: null,
}

vi.mock('../../hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: () => false }),
}))

vi.mock('../batches/api/batches', () => ({
  reverseWriteOff: vi.fn(),
}))

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: (options: CapturedQuery) => {
      queryCapture.current = options
      return mockReturn
    },
    useMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
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

  it('renders movement and conservation quantities at unit precision', () => {
    render(<StockMovementsPage />)
    const qty = screen.getByText('+5.000')
    // DataTable numeric columns right-align with tabular-nums on the <td>.
    const cell = qty.closest('td')
    expect(cell?.className).toContain('tabular-nums')

    const alphaRow = screen.getByText('Alpha').closest('tr')
    expect(alphaRow).not.toBeNull()
    if (alphaRow === null) throw new Error('Expected Alpha movement row')

    expect(within(alphaRow).getByText('0.000')).toBeInTheDocument()
    expect(within(alphaRow).getByText('5.000')).toBeInTheDocument()
  })

  it('requests bounded server filters and renders the real OffsetPagination DOM', async () => {
    apiGetMock.mockResolvedValue({ data: mockReturn.data })
    render(<StockMovementsPage />)

    const firstQuery = queryCapture.current
    if (firstQuery === null) throw new Error('StockMovementsPage did not register its query')
    await firstQuery.queryFn()
    expect(apiGetMock).toHaveBeenLastCalledWith('/stock-movements?page=1&per_page=25')
    expect(screen.getByText('pagination.page 1 pagination.of 3')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'pagination.next' })).toBeEnabled()
    expect(screen.getByDisplayValue('25')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'pagination.next' }))
    await waitFor(() => { expect(queryCapture.current).not.toBe(firstQuery) })
    const secondQuery = queryCapture.current
    if (secondQuery === null) throw new Error('Page 2 did not register its query')
    await secondQuery.queryFn()
    expect(apiGetMock).toHaveBeenLastCalledWith('/stock-movements?page=2&per_page=25')
  })

  // The Transfers and Write-Offs tabs do NOT map to `movement_type=<tab value>`
  // like the other tabs: `transfer` is a type with no reason, `write_off` is a
  // REASON that spans several types. That asymmetry (StockMovementsPage.tsx
  // filter mapping) is the line that widened the Write-Offs tab, so pin it.
  async function urlForTab(tabLabel: string): Promise<string> {
    apiGetMock.mockResolvedValue({ data: mockReturn.data })
    render(<StockMovementsPage />)
    const initialQuery = queryCapture.current
    fireEvent.click(screen.getByRole('button', { name: tabLabel }))
    await waitFor(() => { expect(queryCapture.current).not.toBe(initialQuery) })
    const tabQuery = queryCapture.current
    if (tabQuery === null) throw new Error(`Tab ${tabLabel} did not register a query`)
    await tabQuery.queryFn()
    const requestedUrl: unknown = apiGetMock.mock.lastCall?.[0]
    if (typeof requestedUrl !== 'string') throw new Error('api.get was not called with a URL')
    return requestedUrl
  }

  it('maps the Transfers tab to movement_type=transfer and sends no reason', async () => {
    const url = await urlForTab('movements.filters.transfers')
    expect(url).toContain('movement_type=transfer')
    expect(url).not.toContain('reason=')
  })

  it('maps the Write-Offs tab to reason=write_off and sends no movement_type', async () => {
    const url = await urlForTab('movements.filters.writeOffs')
    expect(url).toContain('reason=write_off')
    expect(url).not.toContain('movement_type=')
  })
})
