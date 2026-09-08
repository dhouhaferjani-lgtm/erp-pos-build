import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
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
  LineItemEntryBar: ({ onAddProduct }: { onAddProduct: (product: { id: string; name: string; sku: string }) => void }) => (
    <div data-testid="counting-line-entry-bar">
      <button type="button" onClick={() => { onAddProduct({ id: 'p-1', name: 'Product 1', sku: 'SKU-1' }); }}>
        Add Product
      </button>
    </div>
  ),
  ProductCell: () => <div data-testid="counting-product-cell" />,
}))

// Mock the single-location selector reused for product_location scope (maxSelection=1)
vi.mock('@/features/locations/components/LocationSelectorMulti', () => ({
  LocationSelectorMulti: ({ onChange }: { onChange: (ids: string[]) => void }) => (
    <div data-testid="location-selector-multi">
      <button type="button" onClick={() => { onChange(['loc-1']); }}>Select Location</button>
      <button type="button" onClick={() => { onChange([]); }}>Clear Location</button>
    </div>
  ),
}))

vi.mock('@/features/categories/components/CategorySelector', () => ({
  CategorySelector: ({ onChange }: { onChange: (ids: number[]) => void }) => (
    <div data-testid="category-selector">
      <button type="button" onClick={() => { onChange([7]); }}>Select Category</button>
    </div>
  ),
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

describe('CreateCountingPage - product_location scope', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setTenant()
  })

  afterEach(() => {
    cleanup()
    resetTenant()
  })

  it('renders a location selector for product_location scope', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByText('counting.scopeTypes.product_location'))
    await user.click(screen.getByText('next'))

    // Both the product entry bar and the location selector are present
    expect(screen.getByTestId('counting-line-entry-bar')).toBeInTheDocument()
    expect(screen.getByTestId('location-selector-multi')).toBeInTheDocument()
  })

  it('blocks the selection step when products are selected but no location is chosen', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByText('counting.scopeTypes.product_location'))
    await user.click(screen.getByText('next'))

    // Add a product but pick NO location -> Next stays disabled
    await user.click(screen.getByText('Add Product'))

    expect(screen.getByText('next').closest('button')).toBeDisabled()
  })

  it('carries product_ids and location_id in the create payload once both are selected', async () => {
    const user = userEvent.setup()
    renderPage()

    // Step 1: scope = product_location
    await user.click(screen.getByText('counting.scopeTypes.product_location'))
    await user.click(screen.getByText('next'))

    // Step 2: selection = products + a single location
    await user.click(screen.getByText('Add Product'))
    await user.click(screen.getByText('Select Location'))
    expect(screen.getByText('next').closest('button')).toBeEnabled()
    await user.click(screen.getByText('next'))

    // Step 3: configuration -> Next
    await user.click(screen.getByText('next'))

    // Step 4: assignment
    await user.click(screen.getAllByText('Select User')[0])
    await user.click(screen.getByText('next'))

    // Step 5: review -> submit
    await user.click(screen.getByText('counting.create.submit'))

    expect(mockMutate).toHaveBeenCalledTimes(1)
    const payload = mockMutate.mock.calls[0][0]
    expect(payload.scope_type).toBe('product_location')
    expect(payload.scope_filters).toEqual({ product_ids: ['p-1'], location_id: 'loc-1' })
  })

  it('clears location_id and blocks again when the location is deselected', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByText('counting.scopeTypes.product_location'))
    await user.click(screen.getByText('next'))

    await user.click(screen.getByText('Add Product'))
    await user.click(screen.getByText('Select Location'))
    expect(screen.getByText('next').closest('button')).toBeEnabled()

    // Deselect the location -> cannot proceed
    await user.click(screen.getByText('Clear Location'))
    expect(screen.getByText('next').closest('button')).toBeDisabled()
  })

  it('does NOT render a location selector under plain product scope and submits product_ids only', async () => {
    const user = userEvent.setup()
    renderPage()

    // Step 1: scope = product
    await user.click(screen.getByText('counting.scopeTypes.product'))
    await user.click(screen.getByText('next'))

    // No location selector under plain product scope
    expect(screen.queryByTestId('location-selector-multi')).not.toBeInTheDocument()

    // Add a product -> can proceed without any location
    await user.click(screen.getByText('Add Product'))
    expect(screen.getByText('next').closest('button')).toBeEnabled()
    await user.click(screen.getByText('next'))

    await user.click(screen.getByText('next'))
    await user.click(screen.getAllByText('Select User')[0])
    await user.click(screen.getByText('next'))
    await user.click(screen.getByText('counting.create.submit'))

    expect(mockMutate).toHaveBeenCalledTimes(1)
    const payload = mockMutate.mock.calls[0][0]
    expect(payload.scope_type).toBe('product')
    expect(payload.scope_filters).toEqual({ product_ids: ['p-1'] })
  })
  it('drops the stale location_id when the scope is switched from product_location to product before submit', async () => {
    const user = userEvent.setup()
    renderPage()

    // Step 1: scope = product_location, then pick a product AND a location
    await user.click(screen.getByText('counting.scopeTypes.product_location'))
    await user.click(screen.getByText('next'))
    await user.click(screen.getByText('Add Product'))
    await user.click(screen.getByText('Select Location'))
    expect(screen.getByText('next').closest('button')).toBeEnabled()

    // Go back and switch the scope to plain product
    await user.click(screen.getByText('previous'))
    await user.click(screen.getByText('counting.scopeTypes.product'))
    await user.click(screen.getByText('next'))

    // Selection step under product scope: no location selector, re-add the product
    expect(screen.queryByTestId('location-selector-multi')).not.toBeInTheDocument()
    await user.click(screen.getByText('Add Product'))
    expect(screen.getByText('next').closest('button')).toBeEnabled()
    await user.click(screen.getByText('next'))

    await user.click(screen.getByText('next'))
    await user.click(screen.getAllByText('Select User')[0])
    await user.click(screen.getByText('next'))
    await user.click(screen.getByText('counting.create.submit'))

    expect(mockMutate).toHaveBeenCalledTimes(1)
    const payload = mockMutate.mock.calls[0][0]
    expect(payload.scope_type).toBe('product')
    expect(payload.scope_filters).toEqual({ product_ids: ['p-1'] })
    expect(payload.scope_filters).not.toHaveProperty('location_id')
  })

  it('keeps product_ids and location_id when the already-active scope tile is re-clicked', async () => {
    const user = userEvent.setup()
    renderPage()

    // Step 1: scope = product_location, then pick a product AND a location
    await user.click(screen.getByText('counting.scopeTypes.product_location'))
    await user.click(screen.getByText('next'))
    await user.click(screen.getByText('Add Product'))
    await user.click(screen.getByText('Select Location'))
    expect(screen.getByText('next').closest('button')).toBeEnabled()

    // Go back and re-click the SAME (already active) scope tile
    await user.click(screen.getByText('previous'))
    await user.click(screen.getByText('counting.scopeTypes.product_location'))
    await user.click(screen.getByText('next'))

    // The selection made before the re-click must still satisfy canProceed
    expect(screen.getByText('next').closest('button')).toBeEnabled()
    await user.click(screen.getByText('next'))

    await user.click(screen.getByText('next'))
    await user.click(screen.getAllByText('Select User')[0])
    await user.click(screen.getByText('next'))
    await user.click(screen.getByText('counting.create.submit'))

    expect(mockMutate).toHaveBeenCalledTimes(1)
    const payload = mockMutate.mock.calls[0][0]
    expect(payload.scope_type).toBe('product_location')
    expect(payload.scope_filters).toEqual({ product_ids: ['p-1'], location_id: 'loc-1' })
  })
})
