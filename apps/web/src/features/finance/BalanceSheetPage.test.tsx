import { render, screen, waitFor } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { BalanceSheetPage } from './pages/BalanceSheetPage'

const { mockApiGet } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  apiGet: mockApiGet,
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: () => true,
  }),
}))

describe('BalanceSheetPage', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
      },
    })
    vi.clearAllMocks()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  it('renders page title', () => {
    mockApiGet.mockResolvedValue({
      assets: [],
      liabilities: [],
      equity: [],
      total_assets: '0.00',
      total_liabilities: '0.00',
      total_equity: '0.00',
    })

    render(
      <QueryClientProvider client={queryClient}>
        <BalanceSheetPage />
      </QueryClientProvider>
    )

    expect(screen.getByText('Balance Sheet')).toBeInTheDocument()
  })

  it('shows loading state', () => {
    mockApiGet.mockImplementation(
      () => new Promise(() => {}) // Never resolves
    )

    render(
      <QueryClientProvider client={queryClient}>
        <BalanceSheetPage />
      </QueryClientProvider>
    )

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('displays asset accounts', async () => {
    const mockData = {
      assets: [
        {
          account_code: '1000',
          account_name: 'Cash',
          amount: '5000.00',
        },
      ],
      liabilities: [],
      equity: [],
      total_assets: '5000.00',
      total_liabilities: '0.00',
      total_equity: '0.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <QueryClientProvider client={queryClient}>
        <BalanceSheetPage />
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('1000')).toBeInTheDocument()
      expect(screen.getByText('Cash')).toBeInTheDocument()
      const amounts = screen.getAllByText('5,000.00')
      expect(amounts.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays liability accounts', async () => {
    const mockData = {
      assets: [],
      liabilities: [
        {
          account_code: '2000',
          account_name: 'Accounts Payable',
          amount: '3000.00',
        },
      ],
      equity: [],
      total_assets: '0.00',
      total_liabilities: '3000.00',
      total_equity: '0.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <QueryClientProvider client={queryClient}>
        <BalanceSheetPage />
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('2000')).toBeInTheDocument()
      expect(screen.getByText('Accounts Payable')).toBeInTheDocument()
      const amounts = screen.getAllByText('3,000.00')
      expect(amounts.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays equity accounts', async () => {
    const mockData = {
      assets: [],
      liabilities: [],
      equity: [
        {
          account_code: '3000',
          account_name: 'Capital',
          amount: '2000.00',
        },
      ],
      total_assets: '0.00',
      total_liabilities: '0.00',
      total_equity: '2000.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <QueryClientProvider client={queryClient}>
        <BalanceSheetPage />
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('3000')).toBeInTheDocument()
      expect(screen.getByText('Capital')).toBeInTheDocument()
      const amounts = screen.getAllByText('2,000.00')
      expect(amounts.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays balanced equation', async () => {
    const mockData = {
      assets: [
        {
          account_code: '1000',
          account_name: 'Cash',
          amount: '5000.00',
        },
      ],
      liabilities: [
        {
          account_code: '2000',
          account_name: 'Accounts Payable',
          amount: '3000.00',
        },
      ],
      equity: [
        {
          account_code: '3000',
          account_name: 'Capital',
          amount: '2000.00',
        },
      ],
      total_assets: '5000.00',
      total_liabilities: '3000.00',
      total_equity: '2000.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <QueryClientProvider client={queryClient}>
        <BalanceSheetPage />
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText(/Total Assets/i)).toBeInTheDocument()
      expect(screen.getByText(/Total Liabilities/i)).toBeInTheDocument()
      expect(screen.getByText(/Total Equity/i)).toBeInTheDocument()
    })
  })

  it('has date filter', () => {
    mockApiGet.mockResolvedValue({
      assets: [],
      liabilities: [],
      equity: [],
      total_assets: '0.00',
      total_liabilities: '0.00',
      total_equity: '0.00',
    })

    render(
      <QueryClientProvider client={queryClient}>
        <BalanceSheetPage />
      </QueryClientProvider>
    )

    expect(screen.getByLabelText(/as of date/i)).toBeInTheDocument()
  })

  it('has export button', () => {
    mockApiGet.mockResolvedValue({
      assets: [],
      liabilities: [],
      equity: [],
      total_assets: '0.00',
      total_liabilities: '0.00',
      total_equity: '0.00',
    })

    render(
      <QueryClientProvider client={queryClient}>
        <BalanceSheetPage />
      </QueryClientProvider>
    )

    expect(screen.getByText(/export/i)).toBeInTheDocument()
  })
})
