import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { CreateCountingPage } from '../CreateCountingPage'

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
vi.mock('../../api/queries', () => ({
  useCreateCounting: () => ({
    mutate: mockMutate,
    isPending: false,
  }),
}))

// Mock useUsers
vi.mock('@/features/users/hooks/useUsers', () => ({
  useUsers: () => ({
    data: {
      data: [
        { id: 'u1', name: 'Alice' },
        { id: 'u2', name: 'Bob' },
      ],
    },
  }),
}))

// Mock child selectors
vi.mock('@/features/products/components/ProductSelector', () => ({
  ProductSelector: ({ onChange }: { onChange: (ids: string[]) => void }) => (
    <div data-testid="product-selector">
      <button type="button" onClick={() => { onChange(['p1']); }}>Select Product</button>
    </div>
  ),
}))

vi.mock('@/features/locations/components/LocationSelectorMulti', () => ({
  LocationSelectorMulti: ({ onChange }: { onChange: (ids: string[]) => void }) => (
    <div data-testid="location-selector-multi">
      <button type="button" onClick={() => { onChange(['l1']); }}>Select Location</button>
    </div>
  ),
}))

vi.mock('@/features/categories/components/CategorySelector', () => ({
  CategorySelector: ({ onChange }: { onChange: (ids: number[]) => void }) => (
    <div data-testid="category-selector">
      <button type="button" onClick={() => { onChange([1]); }}>Select Category</button>
    </div>
  ),
}))

vi.mock('@/features/users/components/UserSelector', () => ({
  UserSelector: ({
    onChange,
    value,
    label,
  }: {
    onChange: (id: string | null) => void
    value: string | null
    label: string
  }) => (
    <div data-testid="user-selector">
      <label>{label}</label>
      <button type="button" onClick={() => { onChange('u1'); }}>
        Select User
      </button>
      {value && <span data-testid="selected-user">{value}</span>}
    </div>
  ),
}))

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

describe('CreateCountingPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders scope step initially', () => {
    renderPage()
    // Scope type buttons should be visible via translation keys
    expect(screen.getByText('counting.create.scopeTitle')).toBeInTheDocument()
    expect(screen.getByText('counting.scopeTypes.full_inventory')).toBeInTheDocument()
    expect(screen.getByText('counting.scopeTypes.category')).toBeInTheDocument()
    expect(screen.getByText('counting.scopeTypes.product')).toBeInTheDocument()
  })

  it('skips selection step for full_inventory scope', async () => {
    const user = userEvent.setup()
    renderPage()

    // full_inventory is already selected by default, click Next
    await user.click(screen.getByText('next'))

    // Should land on configuration step (step 3), not selection
    expect(screen.getByText('counting.create.configTitle')).toBeInTheDocument()
  })

  it('shows category selector for category scope', async () => {
    const user = userEvent.setup()
    renderPage()

    // Select category scope
    await user.click(screen.getByText('counting.scopeTypes.category'))
    // Click Next
    await user.click(screen.getByText('next'))

    // CategorySelector should be rendered
    expect(screen.getByTestId('category-selector')).toBeInTheDocument()
  })

  it('shows product selector for product scope', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByText('counting.scopeTypes.product'))
    await user.click(screen.getByText('next'))

    expect(screen.getByTestId('product-selector')).toBeInTheDocument()
  })

  it('shows location selector for location scope', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByText('counting.scopeTypes.location'))
    await user.click(screen.getByText('next'))

    expect(screen.getByTestId('location-selector-multi')).toBeInTheDocument()
  })

  it('displays user names in review step', async () => {
    const user = userEvent.setup()
    renderPage()

    // Step 1: Scope - full_inventory (default)
    await user.click(screen.getByText('next'))

    // Step 2: Skipped for full_inventory

    // Step 3: Configuration
    await user.click(screen.getByText('next'))

    // Step 4: Assignment - select user
    const selectButtons = screen.getAllByText('Select User')
    await user.click(selectButtons[0])
    await user.click(screen.getByText('next'))

    // Step 5: Review - Alice should be displayed
    await waitFor(() => {
      expect(screen.getByText('Alice')).toBeInTheDocument()
    })
  })

  it('calls mutation on submit', async () => {
    const user = userEvent.setup()
    renderPage()

    // Navigate through all steps
    // Step 1: full_inventory (default) -> Next
    await user.click(screen.getByText('next'))
    // Step 3: Configuration -> Next
    await user.click(screen.getByText('next'))
    // Step 4: Assignment - select user then Next
    const selectButtons = screen.getAllByText('Select User')
    await user.click(selectButtons[0])
    await user.click(screen.getByText('next'))

    // Step 5: Review - Submit
    await user.click(screen.getByText('counting.create.submit'))

    expect(mockMutate).toHaveBeenCalledTimes(1)
  })
})
