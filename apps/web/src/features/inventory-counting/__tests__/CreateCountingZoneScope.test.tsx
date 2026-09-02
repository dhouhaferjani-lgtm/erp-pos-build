import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { CreateCountingPage } from '../pages/CreateCountingPage'
import type { CreateCountingFormData } from '../types'

// Mock translation
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

// Mock navigate
const mockNavigate = vi.fn()
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  }
})

// Mock useCreateCounting
const mockMutate = vi.fn<(payload: CreateCountingFormData) => void>()
vi.mock('../api/queries', () => ({
  useCreateCounting: () => ({
    mutate: mockMutate,
    isPending: false,
  }),
}))

// Mock useUsers
vi.mock('@/features/users/hooks/useUsers', () => ({
  useUsers: () => ({
    data: {
      data: [{ id: 'u1', name: 'Alice' }],
    },
  }),
}))

vi.mock('@/components/molecules/line-items', () => ({
  LineItemEntryBar: () => <div data-testid="counting-line-entry-bar" />,
  ProductCell: () => <div data-testid="counting-product-cell" />,
}))

// Mock the single-location selector reused for zone scope (maxSelection=1)
vi.mock('@/features/locations/components/LocationSelectorMulti', () => ({
  LocationSelectorMulti: ({ onChange }: { onChange: (ids: string[]) => void }) => (
    <div data-testid="location-selector-multi">
      <button type="button" onClick={() => { onChange(['loc-1']); }}>Select Location</button>
    </div>
  ),
}))

vi.mock('@/features/categories/components/CategorySelector', () => ({
  CategorySelector: () => <div data-testid="category-selector" />,
}))

vi.mock('@/features/users/components/UserSelector', () => ({
  UserSelector: ({ onChange, label }: { onChange: (id: string | null) => void; label: string }) => (
    <div data-testid="user-selector">
      <label>{label}</label>
      <button type="button" onClick={() => { onChange('u1'); }}>Select User</button>
    </div>
  ),
}))

const mockListZones = vi.hoisted(() => vi.fn())
vi.mock('@/features/placement/api', () => ({
  listLocationNodes: mockListZones,
}))

function node(overrides: Partial<{
  id: string
  parent_id: string | null
  node_type: 'zone' | 'aisle' | 'rack' | 'shelf' | 'bin' | 'section'
  name: string
  code: string
  path: string
  depth: number
}> = {}) {
  return {
    id: 'node-a1',
    location_id: 'loc-1',
    parent_id: null,
    node_type: 'aisle' as const,
    name: 'Aisle 1',
    code: 'A1',
    path: 'A1',
    depth: 0,
    sort_order: 0,
    is_active: true,
    product_count: 0,
    deleted_at: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

function setTenant() {
  useAuthStore.setState({
    user: {
      id: 'current-user',
      name: 'Current User',
      email: 'current@example.test',
      tenant_id: 'tenant-A',
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <CreateCountingPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CreateCountingPage - zone scope', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setTenant()
    mockListZones.mockResolvedValue([
      node(),
      node({ id: 'node-r2', parent_id: 'node-a1', node_type: 'rack', name: 'Rack 2', code: 'R2', path: 'A1/R2', depth: 1 }),
      node({ id: 'node-b7', parent_id: 'node-r2', node_type: 'bin', name: 'Bin 7', code: 'B7', path: 'A1/R2/B7', depth: 2 }),
      node({ id: 'node-a10', name: 'Aisle 10', code: 'A10', path: 'A10' }),
    ])
  })

  afterEach(() => {
    cleanup()
    resetTenant()
  })

  it('renders an expandable node tree for the selected location', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByText('counting.scopeTypes.zone'))
    await user.click(screen.getByText('next'))

    // No location selected yet -> zones not fetched
    expect(mockListZones).not.toHaveBeenCalled()

    await user.click(screen.getByText('Select Location'))

    await waitFor(() => {
      expect(mockListZones).toHaveBeenCalledWith('loc-1')
    })
    await waitFor(() => {
      expect(screen.getByText('Aisle 1')).toBeInTheDocument()
      expect(screen.getByText('Aisle 10')).toBeInTheDocument()
    })
    expect(screen.queryByText('Rack 2')).not.toBeInTheDocument()

    await user.click(screen.getAllByRole('button', { name: 'placement.tree.expand' })[0])
    expect(await screen.findByText('Rack 2')).toBeInTheDocument()
  })

  it('disables the block-sales toggle under zone scope with an explanatory hint', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByText('counting.scopeTypes.zone'))
    await user.click(screen.getByText('next'))
    await user.click(screen.getByText('Select Location'))

    await waitFor(() => {
      expect(screen.getByText('Aisle 1')).toBeInTheDocument()
    })
    await user.click(screen.getByText('Aisle 1'))

    await user.click(screen.getByText('next'))

    const toggle = screen.getByRole('checkbox', { name: 'counting.create.blockSales' })
    expect(toggle).toBeDisabled()
    expect(toggle).not.toBeChecked()
    expect(screen.getByText('counting.create.blockSalesZoneDisabledHint')).toBeInTheDocument()
  })

  it('defaults the ambiguity window to 15 minutes', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByText('counting.scopeTypes.zone'))
    await user.click(screen.getByText('next'))
    await user.click(screen.getByText('Select Location'))
    await waitFor(() => { expect(screen.getByText('Aisle 1')).toBeInTheDocument() })
    await user.click(screen.getByText('Aisle 1'))
    await user.click(screen.getByText('next'))

    const input = screen.getByLabelText<HTMLInputElement>('counting.create.ambiguityWindowMinutes')
    expect(input.value).toBe('15')
  })

  /**
   * N-1 / A-8: the review step summarised execution mode / counts / unexpected
   * items but not the two fields that decide how the shop keeps trading during
   * the count. Under zone scope block_sales is forced to false, so the review
   * must say so rather than echo an untouched toggle.
   */
  it('summarises block sales and the ambiguity window on the review step', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByText('counting.scopeTypes.zone'))
    await user.click(screen.getByText('next'))
    await user.click(screen.getByText('Select Location'))
    await waitFor(() => { expect(screen.getByText('Aisle 1')).toBeInTheDocument() })
    await user.click(screen.getByText('Aisle 1'))
    await user.click(screen.getByText('next'))
    await user.click(screen.getByText('next'))

    const selectButtons = screen.getAllByText('Select User')
    await user.click(selectButtons[0])
    await user.click(screen.getByText('next'))

    expect(screen.getByTestId('review-block-sales')).toHaveTextContent('no')
    expect(screen.getByTestId('review-ambiguity-window')).toHaveTextContent('15')
  })

  it('carries zone_ids, block_sales:false and ambiguity_window_minutes in the create payload', async () => {
    const user = userEvent.setup()
    renderPage()

    // Step 1: scope = zone
    await user.click(screen.getByText('counting.scopeTypes.zone'))
    await user.click(screen.getByText('next'))

    // Step 2: selection = location + zone
    await user.click(screen.getByText('Select Location'))
    await waitFor(() => { expect(screen.getByText('Aisle 1')).toBeInTheDocument() })
    await user.click(screen.getByText('Aisle 1'))
    await user.click(screen.getByText('next'))

    // Step 3: configuration (block toggle disabled, ambiguity window default) -> Next
    await user.click(screen.getByText('next'))

    // Step 4: assignment
    const selectButtons = screen.getAllByText('Select User')
    await user.click(selectButtons[0])
    await user.click(screen.getByText('next'))

    // Step 5: review -> submit
    await user.click(screen.getByText('counting.create.submit'))

    expect(mockMutate).toHaveBeenCalledTimes(1)
    const payload = mockMutate.mock.calls[0][0]
    expect(payload.scope_type).toBe('zone')
    expect(payload.scope_filters).toEqual({ location_id: 'loc-1', zone_ids: ['node-a1'] })
    expect(payload.block_sales).toBe(false)
    expect(payload.ambiguity_window_minutes).toBe(15)
  })
})
