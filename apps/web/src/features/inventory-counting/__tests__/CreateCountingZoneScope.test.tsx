import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { CreateCountingPage } from '../pages/CreateCountingPage'

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
const mockMutate = vi.fn()
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

function zone(overrides: Partial<{ id: string; name: string; code: string }> = {}) {
  return {
    id: 'zone-1',
    location_id: 'loc-1',
    name: 'Aisle 1',
    code: 'A1',
    sort_order: 0,
    is_active: true,
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
    mockListZones.mockResolvedValue([zone(), zone({ id: 'zone-2', name: 'Aisle 2', code: 'A2' })])
  })

  afterEach(() => {
    resetTenant()
  })

  it('lists zones of the selected location once a location is chosen', async () => {
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
      expect(screen.getByText('Aisle 2')).toBeInTheDocument()
    })
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

    const input = screen.getByLabelText('counting.create.ambiguityWindowMinutes') as HTMLInputElement
    expect(input.value).toBe('15')
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
    expect(payload.scope_filters).toEqual({ location_id: 'loc-1', zone_ids: ['zone-1'] })
    expect(payload.block_sales).toBe(false)
    expect(payload.ambiguity_window_minutes).toBe(15)
  })
})
