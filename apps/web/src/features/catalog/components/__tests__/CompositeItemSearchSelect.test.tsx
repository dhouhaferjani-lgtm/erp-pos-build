import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CompositeItemSearchSelect } from '../CompositeItemSearchSelect'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback ?? key,
  }),
}))

// Mock api module
const mockGet = vi.fn()
vi.mock('@/lib/api', () => ({
  api: {
    get: (...args: unknown[]) => mockGet(...args),
  },
}))

function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })
}

function createWrapper(queryClient = createTestQueryClient()) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        {children}
      </QueryClientProvider>
    )
  }
}

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: { id: 'user-1', name: 'User', email: 'user@example.test', tenant_id: tenantId, roles: [], email_verified_at: null },
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

describe('CompositeItemSearchSelect', () => {
  const defaultProps = {
    value: '',
    onChange: vi.fn(),
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setTenant('tenant-A', 'company-1')
  })

  afterEach(() => {
    resetTenant()
  })

  it('renders with placeholder text when no value is selected', () => {
    render(
      <CompositeItemSearchSelect {...defaultProps} placeholder="Select item" />,
      { wrapper: createWrapper() }
    )

    expect(screen.getByText('Select item')).toBeInTheDocument()
  })

  it('renders the trigger button with correct ARIA attributes', () => {
    render(
      <CompositeItemSearchSelect {...defaultProps} />,
      { wrapper: createWrapper() }
    )

    const trigger = screen.getByRole('button')
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
    expect(trigger).toHaveAttribute('aria-haspopup', 'listbox')
  })

  it('opens dropdown on click and shows search input', async () => {
    mockGet.mockResolvedValue({ data: { data: [] } })

    const user = userEvent.setup()
    render(
      <CompositeItemSearchSelect {...defaultProps} />,
      { wrapper: createWrapper() }
    )

    const trigger = screen.getByRole('button')
    await user.click(trigger)

    expect(trigger).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('textbox')).toBeInTheDocument()
  })

  it('shows loading state while fetching items', async () => {
    let resolvePromise: (value: unknown) => void
    mockGet.mockReturnValue(new Promise((resolve) => {
      resolvePromise = resolve
    }))

    const user = userEvent.setup()
    render(
      <CompositeItemSearchSelect {...defaultProps} />,
      { wrapper: createWrapper() }
    )

    await user.click(screen.getByRole('button'))

    await waitFor(() => {
      expect(screen.getByText('Loading...')).toBeInTheDocument()
    })

    // Clean up
    resolvePromise!({ data: { data: [] } })
  })

  it('displays items returned from the API', async () => {
    const mockItems = [
      { id: '1', name: 'Cappuccino', code: 'CAP-001' },
      { id: '2', name: 'Latte', code: 'LAT-001' },
    ]

    mockGet.mockResolvedValue({ data: { data: mockItems } })

    const user = userEvent.setup()
    render(
      <CompositeItemSearchSelect {...defaultProps} />,
      { wrapper: createWrapper() }
    )

    await user.click(screen.getByRole('button'))

    await waitFor(() => {
      expect(screen.getByText('Cappuccino')).toBeInTheDocument()
      expect(screen.getByText('Latte')).toBeInTheDocument()
      expect(screen.getByText('CAP-001')).toBeInTheDocument()
      expect(screen.getByText('LAT-001')).toBeInTheDocument()
    })
  })

  it('calls onChange when an item is selected', async () => {
    const mockItems = [
      { id: 'abc-123', name: 'Espresso', code: 'ESP-001' },
    ]

    mockGet.mockResolvedValue({ data: { data: mockItems } })

    const onChange = vi.fn()
    const user = userEvent.setup()

    render(
      <CompositeItemSearchSelect {...defaultProps} onChange={onChange} />,
      { wrapper: createWrapper() }
    )

    await user.click(screen.getByRole('button'))

    await waitFor(() => {
      expect(screen.getByText('Espresso')).toBeInTheDocument()
    })

    await user.click(screen.getByText('Espresso'))

    expect(onChange).toHaveBeenCalledWith('abc-123')
  })

  it('shows empty state when no items match search', async () => {
    mockGet.mockResolvedValue({ data: { data: [] } })

    const user = userEvent.setup()
    render(
      <CompositeItemSearchSelect {...defaultProps} />,
      { wrapper: createWrapper() }
    )

    await user.click(screen.getByRole('button'))

    const searchInput = await screen.findByRole('textbox')
    await user.type(searchInput, 'nonexistent')

    await waitFor(() => {
      expect(screen.getByText('catalog:noCompositeItemsFound')).toBeInTheDocument()
    })
  })

  it('is disabled when disabled prop is true', () => {
    render(
      <CompositeItemSearchSelect {...defaultProps} disabled />,
      { wrapper: createWrapper() }
    )

    const trigger = screen.getByRole('button')
    expect(trigger).toHaveAttribute('aria-disabled', 'true')
  })

  it('shows clear button when a value is selected', async () => {
    const mockItem = { id: 'selected-id', name: 'Selected Item', code: 'SEL-001' }
    mockGet.mockResolvedValue({ data: { data: mockItem } })

    const onChange = vi.fn()

    render(
      <CompositeItemSearchSelect value="selected-id" onChange={onChange} />,
      { wrapper: createWrapper() }
    )

    // Wait for the selected item to load
    await waitFor(() => {
      const clearButton = screen.getByLabelText('Clear selection')
      expect(clearButton).toBeInTheDocument()
    })
  })

  it('calls onChange with empty string when clear button is clicked', async () => {
    const mockItem = { id: 'selected-id', name: 'Selected Item', code: 'SEL-001' }
    mockGet.mockResolvedValue({ data: { data: mockItem } })

    const onChange = vi.fn()
    const user = userEvent.setup()

    render(
      <CompositeItemSearchSelect value="selected-id" onChange={onChange} />,
      { wrapper: createWrapper() }
    )

    await waitFor(() => {
      expect(screen.getByLabelText('Clear selection')).toBeInTheDocument()
    })

    await user.click(screen.getByLabelText('Clear selection'))

    expect(onChange).toHaveBeenCalledWith('')
  })

  it('scopes search and selected item query keys by tenant/company', async () => {
    mockGet.mockResolvedValue({ data: { data: { id: 'selected-id', name: 'Selected Item', code: 'SEL-001' } } })
    const queryClient = createTestQueryClient()

    render(
      <CompositeItemSearchSelect value="selected-id" onChange={vi.fn()} />,
      { wrapper: createWrapper(queryClient) }
    )

    await waitFor(() => {
      expect(queryClient.getQueryData(['composite-item-selected', 'selected-id', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockGet.mock.calls.length
    render(
      <CompositeItemSearchSelect value="tenantless-id" onChange={vi.fn()} />,
      { wrapper: createWrapper(createTestQueryClient()) }
    )
    expect(mockGet).toHaveBeenCalledTimes(calls)
  })
})
