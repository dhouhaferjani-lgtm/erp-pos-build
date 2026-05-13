import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter } from 'react-router-dom'
import { ChartOfAccountsPage } from '@/features/finance/pages/ChartOfAccountsPage'
import { seedAuth, resetAuth } from '@/test/seedAuth'

const { mockApiGet, mockApiPost, mockApiPatch } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
  mockApiPost: vi.fn(),
  mockApiPatch: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  apiGet: mockApiGet,
  apiPost: mockApiPost,
  apiPatch: mockApiPatch,
}))

const mockAccounts = [
  {
    id: '1',
    tenant_id: 'tenant-1',
    parent_id: null,
    code: '1000',
    name: 'Assets',
    type: 'asset',
    description: 'Assets account',
    is_active: true,
    is_system: true,
    balance: '10000.00',
    created_at: '2025-01-01T00:00:00Z',
    updated_at: '2025-01-01T00:00:00Z',
  },
  {
    id: '2',
    tenant_id: 'tenant-1',
    parent_id: '1',
    code: '1100',
    name: 'Cash',
    type: 'asset',
    description: 'Cash account',
    is_active: true,
    is_system: false,
    balance: '5000.00',
    created_at: '2025-01-01T00:00:00Z',
    updated_at: '2025-01-01T00:00:00Z',
  },
]

function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })
}

function TestWrapper({ children }: { children: React.ReactNode }) {
  return (
    <QueryClientProvider client={createTestQueryClient()}>
      <BrowserRouter>{children}</BrowserRouter>
    </QueryClientProvider>
  )
}

describe('ChartOfAccountsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  it('renders the page title', async () => {
    mockApiGet.mockResolvedValue(mockAccounts)

    render(<ChartOfAccountsPage />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /chart of accounts/i })).toBeInTheDocument()
    })
  })

  it('displays loading state initially', () => {
    mockApiGet.mockImplementation(() => new Promise(() => {}))

    render(<ChartOfAccountsPage />, { wrapper: TestWrapper })

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('displays list of accounts', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(mockAccounts)

    render(<ChartOfAccountsPage />, { wrapper: TestWrapper })

    // Parent account should be visible
    await waitFor(() => {
      expect(screen.getByText('Assets')).toBeInTheDocument()
      expect(screen.getByText('1000')).toBeInTheDocument()
    })

    // Expand parent to see child
    await user.click(screen.getByTestId('expand-1'))

    await waitFor(() => {
      expect(screen.getByText('Cash')).toBeInTheDocument()
      expect(screen.getByText('1100')).toBeInTheDocument()
    })
  })

  it('has an add account button', async () => {
    mockApiGet.mockResolvedValue(mockAccounts)

    render(<ChartOfAccountsPage />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /add account/i })).toBeInTheDocument()
    })
  })

  it('opens add account modal when button is clicked', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(mockAccounts)

    render(<ChartOfAccountsPage />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /add account/i })).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /add account/i }))

    await waitFor(() => {
      expect(screen.getByRole('dialog')).toBeInTheDocument()
    })
  })
})
