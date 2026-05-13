import { render, screen, waitFor } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { ProfitLossPage } from './pages/ProfitLossPage'

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

describe('ProfitLossPage', () => {
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
      revenue: [],
      expenses: [],
      total_revenue: '0.00',
      total_expenses: '0.00',
      net_income: '0.00',
    })

    render(
      <QueryClientProvider client={queryClient}>
        <ProfitLossPage />
      </QueryClientProvider>
    )

    expect(screen.getByText(/Profit.*Loss/i)).toBeInTheDocument()
  })

  it('shows loading state', () => {
    mockApiGet.mockImplementation(
      () => new Promise(() => {}) // Never resolves
    )

    render(
      <QueryClientProvider client={queryClient}>
        <ProfitLossPage />
      </QueryClientProvider>
    )

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('displays revenue accounts', async () => {
    const mockData = {
      revenue: [
        {
          account_code: '4000',
          account_name: 'Sales Revenue',
          amount: '10000.00',
        },
      ],
      expenses: [],
      total_revenue: '10000.00',
      total_expenses: '0.00',
      net_income: '10000.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <QueryClientProvider client={queryClient}>
        <ProfitLossPage />
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('4000')).toBeInTheDocument()
      expect(screen.getByText('Sales Revenue')).toBeInTheDocument()
      const amounts = screen.getAllByText('10,000.00')
      expect(amounts.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays expense accounts', async () => {
    const mockData = {
      revenue: [],
      expenses: [
        {
          account_code: '6000',
          account_name: 'Salaries',
          amount: '5000.00',
        },
      ],
      total_revenue: '0.00',
      total_expenses: '5000.00',
      net_income: '-5000.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <QueryClientProvider client={queryClient}>
        <ProfitLossPage />
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('6000')).toBeInTheDocument()
      expect(screen.getByText('Salaries')).toBeInTheDocument()
      const amounts = screen.getAllByText('5,000.00')
      expect(amounts.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays net income calculation', async () => {
    const mockData = {
      revenue: [
        {
          account_code: '4000',
          account_name: 'Sales Revenue',
          amount: '10000.00',
        },
      ],
      expenses: [
        {
          account_code: '6000',
          account_name: 'Salaries',
          amount: '5000.00',
        },
      ],
      total_revenue: '10000.00',
      total_expenses: '5000.00',
      net_income: '5000.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <QueryClientProvider client={queryClient}>
        <ProfitLossPage />
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText(/Net Income/i)).toBeInTheDocument()
      // Net income should be displayed (appears at least once)
      const amounts = screen.getAllByText('5,000.00')
      expect(amounts.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('has date range filters', () => {
    mockApiGet.mockResolvedValue({
      revenue: [],
      expenses: [],
      total_revenue: '0.00',
      total_expenses: '0.00',
      net_income: '0.00',
    })

    render(
      <QueryClientProvider client={queryClient}>
        <ProfitLossPage />
      </QueryClientProvider>
    )

    expect(screen.getByLabelText(/from/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/to/i)).toBeInTheDocument()
  })

  it('has export button', () => {
    mockApiGet.mockResolvedValue({
      revenue: [],
      expenses: [],
      total_revenue: '0.00',
      total_expenses: '0.00',
      net_income: '0.00',
    })

    render(
      <QueryClientProvider client={queryClient}>
        <ProfitLossPage />
      </QueryClientProvider>
    )

    expect(screen.getByText(/export/i)).toBeInTheDocument()
  })
})
