import { render, screen, waitFor, within } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { ProfitLossPage } from './pages/ProfitLossPage'

const { mockApiGet } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
}))

const normalizeSpaces = (value: string | null): string => (value ?? '').replace(/\s/g, ' ')

vi.mock('@/lib/api', () => ({
  apiGet: mockApiGet,
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: () => true,
  }),
}))

vi.mock('@/features/owner-dashboard/components/OwnerChart', () => ({
  OwnerChart: ({ title }: { title: string }) => <div data-testid="owner-chart">{title}</div>,
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
      const row = screen.getByText('Sales Revenue').closest('tr')
      if (!row) throw new Error('revenue row not found')
      expect(within(row).getByText((_, node) => normalizeSpaces(node?.textContent ?? '') === '10 000,00 EUR')).toBeInTheDocument()
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
      const row = screen.getByText('Salaries').closest('tr')
      if (!row) throw new Error('expense row not found')
      expect(within(row).getByText((_, node) => normalizeSpaces(node?.textContent ?? '') === '5 000,00 EUR')).toBeInTheDocument()
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
      expect(screen.getAllByText(/Net Income/i).length).toBeGreaterThanOrEqual(1)
      // Net income should be displayed (appears at least once)
      const summary = screen.getAllByText(/Net Income/i)
        .map((label) => label.closest('div.flex'))
        .find((element): element is HTMLElement => element instanceof HTMLElement)
      if (!summary) throw new Error('net income summary not found')
      expect(within(summary).getByText((_, node) => normalizeSpaces(node?.textContent ?? '') === '5 000,00 EUR')).toBeInTheDocument()
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
